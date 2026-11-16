<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use XcVm\Infrastructure\Database\DatabaseFactory;
use XcVm\Module\SourceHealth\AutoRank;
use XcVm\Module\SourceHealth\SourceAuditPage;
use XcVm\Module\SourceHealth\SourceHealthRepository;
use XcVm\Module\SourceHealth\SourceHealthTab;
use XcVm\Tests\Support\InstallSchema;

/**
 * Auto-rank in shadow mode: when a source further down is proposed first,
 * the hysteresis that keeps it from flapping, the verdict a day later, and
 * that nothing in the stream changes.
 */
final class AutoRankTest extends TestCase {
	private const A = 'http://a.example/live/u/p/1.ts';
	private const B = 'http://b.example/live/v/q/2.ts';

	public static function setUpBeforeClass(): void {
		$rDir = glob(dirname(__DIR__, 2) . '/src/Modules/source-health_*')[0] ?? null;
		if ($rDir === null) {
			self::markTestSkipped('The source-health module is not installed in this tree.');
		}
		foreach (['SourceFingerprint', 'SourceHealthCalculator', 'SourceHealthRepository', 'SourceProber', 'SourceHealthTab', 'ProviderAccount', 'ProviderImport', 'SourceAudit', 'SourceActions', 'SourceFinder', 'StreamHealth', 'SourceAuditPage', 'AutoRank'] as $rClass) {
			require_once $rDir . '/' . $rClass . '.php';
		}
	}

	private static function row(int $rIndex, string $rSource, ?int $rScore, string $rConfidence, int $rUptime = 7200, int $rProbeOk = 0): array {
		return ['index' => $rIndex, 'source' => $rSource, 'score' => $rScore, 'confidence' => $rConfidence, 'uptime_sec' => $rUptime, 'probe_ok' => $rProbeOk, 'running' => $rIndex === 0];
	}

	public function testHysteresisAndWhatMayBePutFirst(): void {
		$t = 1_000_000;
		$rClose = [self::row(0, self::A, 60, 'high'), self::row(1, self::B, 70, 'high')];
		$this->assertNull(AutoRank::decide($rClose, [], null, $t)['decision']);                         // 10 points: not clearly better
		$this->assertSame(0, AutoRank::decide($rClose, [], null, $t)['state']['pending_count']);

		$rClear = [self::row(0, self::A, 40, 'high'), self::row(1, self::B, 90, 'high')];
		$r1 = AutoRank::decide($rClear, [], null, $t);
		$this->assertNull($r1['decision']);                                                             // once is not enough
		$this->assertSame(1, $r1['state']['pending_count']);
		$r2 = AutoRank::decide($rClear, [], $r1['state'], $t + 1800);
		$this->assertSame([md5(self::A), md5(self::B), 'ranks 90 against 40 for the first', true],
			[$r2['decision']['old_hash'], $r2['decision']['new_hash'], $r2['decision']['reason'], $r2['decision']['on_air']]);
		// Shadow mode leaves the order alone: the same proposal is not recorded again, even after the interval.
		$r3 = AutoRank::decide($rClear, [], AutoRank::decide($rClear, [], $r2['state'], $t + 3600)['state'], $t + 3600 + AutoRank::MIN_INTERVAL_SEC);
		$this->assertNull($r3['decision']);
		// A proposal that changes inside the interval waits for it.
		$rOther = [self::row(0, self::A, 40, 'high'), self::row(1, self::B, 50, 'high'), self::row(2, 'http://c.example/live/w/r/3.ts', 95, 'high')];
		$r4 = AutoRank::decide($rOther, [], AutoRank::decide($rOther, [], $r2['state'], $t + 3600)['state'], $t + 5400);
		$this->assertNull($r4['decision']);
		$this->assertNotNull(AutoRank::decide($rOther, [], $r4['state'], $t + 1800 + AutoRank::MIN_INTERVAL_SEC)['decision']);

		// Never put first: too little data, under an hour on air with few probes, or off by Source Audit.
		$this->assertSame('too little data', AutoRank::ineligible(self::row(1, self::B, 95, 'low'), []));
		$this->assertSame('not an hour on air nor 3 good probes', AutoRank::ineligible(self::row(1, self::B, 95, 'medium', 600, 2), []));
		$this->assertNull(AutoRank::ineligible(self::row(1, self::B, 95, 'medium', 0, 3), []));
		$this->assertSame('Source Audit found it dead', AutoRank::ineligible(self::row(1, self::B, 95, 'high'), [md5(self::B) => ['status' => 'dead']]));
		$rDeadBackup = [self::row(0, self::A, 10, 'high'), self::row(1, self::B, 95, 'high')];
		$this->assertSame(0, AutoRank::decide($rDeadBackup, [md5(self::B) => ['status' => 'dead']], null, $t)['state']['pending_count']);

		// A first source off by Source Audit makes way for any eligible one, margin or not.
		$rFirstOff = [self::row(0, self::A, 70, 'high'), self::row(1, self::B, 72, 'high')];
		$rAudit = [md5(self::A) => ['status' => 'dead']];
		$r5 = AutoRank::decide($rFirstOff, $rAudit, AutoRank::decide($rFirstOff, $rAudit, null, $t)['state'], $t + 1800);
		$this->assertSame('the first source is dead by Source Audit', $r5['decision']['reason']);

		$this->assertSame('helped', AutoRank::verdict(2, 0, 0, 0, 3, 3));
		$this->assertSame('hurt', AutoRank::verdict(0, 0, 1, 600, 0, 0));
		$this->assertSame('hurt', AutoRank::verdict(0, 0, 0, 0, 1, 4));                                  // most probes failed
		$this->assertSame('both_failed', AutoRank::verdict(1, 0, 1, 300, 0, 0));
		$this->assertSame('same', AutoRank::verdict(0, 0, 0, 0, 2, 2));
		$this->assertSame('unknown', AutoRank::verdict(3, 1, 0, 0, 0, 0));
	}

	public function testRunRecordsWithoutTouchingTheStreamAndReadsTheDayAfter(): void {
		$rDb = new TestDb();
		foreach (['streams', 'streams_servers', 'streams_logs', 'streams_errors', 'settings'] as $rTable) {
			$rDb->exec(InstallSchema::table($rTable));
		}
		foreach (array_filter(array_map('trim', explode(';', (string) file_get_contents(glob(dirname(__DIR__, 2) . '/src/Modules/source-health_*')[0] . '/database.sql')))) as $rStatement) {
			$rDb->exec($rStatement);
		}
		DatabaseFactory::set($rDb);
		try {
			$rNow = time();
			$rDb->query("INSERT INTO `streams` (`id`, `type`, `stream_display_name`, `stream_source`) VALUES (1, 1, 'Tring Sport 2', ?), (2, 1, 'Single', ?)",
				json_encode([self::A, self::B]), json_encode([self::A]));
			// A is off by Source Audit; B answered every probe.
			SourceHealthRepository::saveAudit(md5(self::A), 'a.example', ['status' => 'dead', 'kind' => null, 'reason' => 'http 404', 'fails' => 3, 'first_fail' => $rNow - 86400, 'last_ok' => 0], null, $rNow - 60);
			for ($i = 1; $i <= 4; $i++) {
				$rDb->query("INSERT INTO `source_health_probes` (`stream_id`, `source_hash`, `checked_at`, `kind`, `ok`) VALUES (1, ?, ?, 'probe', 1)", md5(self::B), $rNow - $i * 3600);
			}

			$this->assertSame(['evaluated' => 1, 'recorded' => 0, 'outcomes' => 0], AutoRank::run($rNow));   // the single-source stream is not evaluated
			$this->assertSame(['evaluated' => 0, 'recorded' => 0, 'outcomes' => 0], AutoRank::run($rNow + 60));   // not its turn yet
			$this->assertSame(['evaluated' => 1, 'recorded' => 1, 'outcomes' => 0], AutoRank::run($rNow + AutoRank::EVAL_EVERY_SEC));
			$this->assertSame(json_encode([self::A, self::B]), $rDb->fetchValue('SELECT `stream_source` FROM `streams` WHERE `id` = 1'));   // shadow: untouched

			$rPage = SourceAuditPage::render(['view' => 'rank'], $rNow + AutoRank::EVAL_EVERY_SEC);
			$this->assertStringContainsString('Tring Sport 2', $rPage);
			$this->assertStringContainsString('the first source is dead by Source Audit', $rPage);
			$this->assertStringContainsString('waiting', $rPage);
			$this->assertStringNotContainsString('/live/u/p/', $rPage);                                     // masked, no account
			$this->assertStringContainsString('it would have put #2 first instead of #1', SourceHealthTab::render(['id' => 1, 'stream_source' => json_encode([self::A, self::B]), 'stream_display_name' => 'Tring Sport 2'], 'edit'));

			// A day later: nothing failed and nothing more was seen of B, so no difference.
			$rLater = $rNow + AutoRank::EVAL_EVERY_SEC + AutoRank::OUTCOME_SEC + 60;
			$this->assertSame(1, AutoRank::run($rLater)['outcomes']);
			$this->assertSame('same', $rDb->fetchValue('SELECT `verdict` FROM `source_health_rank_decisions` WHERE `stream_id` = 1'));

			$rDb->query('DELETE FROM `streams` WHERE `id` = 1');
			AutoRank::run($rLater + 60);
			$this->assertSame(0, (int) $rDb->fetchValue('SELECT COUNT(*) FROM `source_health_rank_state` WHERE `stream_id` = 1'));
		} finally {
			DatabaseFactory::reset();
		}
	}
}
