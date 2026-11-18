<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use XcVm\Infrastructure\Database\DatabaseFactory;
use XcVm\Module\SourceHealth\AutoProbe;
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
		foreach (['SourceFingerprint', 'SourceHealthCalculator', 'SourceHealthRepository', 'SourceProber', 'SourceHealthTab', 'ProviderAccount', 'ProviderImport', 'SourceAudit', 'SourceActions', 'SourceFinder', 'StreamHealth', 'SourceAuditPage', 'AutoRank', 'AutoProbe', 'SourceHealthCron'] as $rClass) {
			require_once $rDir . '/' . $rClass . '.php';
		}
	}

	private static function row(int $rIndex, string $rSource, ?int $rScore, string $rConfidence, int $rUptime = 7200, int $rProbeOk = 0, string $rProfile = ''): array {
		return ['index' => $rIndex, 'source' => $rSource, 'score' => $rScore, 'confidence' => $rConfidence, 'uptime_sec' => $rUptime, 'probe_ok' => $rProbeOk, 'running' => $rIndex === 0, 'profile' => $rProfile];
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

		// Never a weak or a worse source first, not even in place of one that is off: the records of the first week.
		$this->assertSame('score under 30', AutoRank::ineligible(self::row(1, self::B, 6, 'high'), []));
		$rWorse = [self::row(0, self::A, 70, 'high'), self::row(1, self::B, 40, 'high')];
		$this->assertSame(0, AutoRank::decide($rWorse, $rAudit, null, $t)['state']['pending_count']);

		$o = static fn (int $i, int $f, int $up): array => ['incidents' => $i, 'start_fails' => $f, 'uptime' => $up];
		$n = static fn (int $i, int $f, int $up, int $ok = 0, int $tot = 0): array => ['incidents' => $i, 'start_fails' => $f, 'uptime' => $up, 'probe_ok' => $ok, 'probe_total' => $tot];
		$this->assertSame('helped', AutoRank::verdict($o(0, 18, 0), $n(1, 0, 7260, 33, 37)));             // Ferma Vip 2: one drop in two hours on air
		$this->assertSame('helped', AutoRank::verdict($o(2, 0, 3600), $n(0, 0, 0, 3, 3)));
		$this->assertSame('hurt', AutoRank::verdict($o(11, 17, 60240), $n(9, 0, 7560)));                // Super Sport 1: 4 an hour against 1.7
		$this->assertSame('hurt', AutoRank::verdict($o(0, 0, 3600), $n(1, 0, 600)));
		$this->assertSame('hurt', AutoRank::verdict($o(0, 0, 0), $n(0, 0, 0, 1, 4)));                   // most probes failed
		$this->assertSame('both_failed', AutoRank::verdict($o(0, 25, 0), $n(6, 0, 420)));                // Milan Channel: six drops in seven minutes
		$this->assertSame('same', AutoRank::verdict($o(0, 0, 3600), $n(0, 0, 0, 2, 2)));
		$this->assertSame('idle', AutoRank::verdict($o(0, 0, 0), $n(0, 0, 0)));                          // nobody watched it that day
		$this->assertSame('unknown', AutoRank::verdict($o(3, 1, 0), $n(0, 0, 0)));
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

			// A day later: the stream was not used that day, nothing to compare.
			$rLater = $rNow + AutoRank::EVAL_EVERY_SEC + AutoRank::OUTCOME_SEC + 60;
			$this->assertSame(1, AutoRank::run($rLater)['outcomes']);
			$this->assertSame('idle', $rDb->fetchValue('SELECT `verdict` FROM `source_health_rank_decisions` WHERE `stream_id` = 1'));
			$this->assertStringContainsString('not used', SourceAuditPage::render(['view' => 'rank'], $rLater));

			$rDb->query('DELETE FROM `streams` WHERE `id` = 1');
			AutoRank::run($rLater + 60);
			$this->assertSame(0, (int) $rDb->fetchValue('SELECT COUNT(*) FROM `source_health_rank_state` WHERE `stream_id` = 1'));
		} finally {
			DatabaseFactory::reset();
		}
	}

	public function testProfileGroupsKeepFailoversInOneProfile(): void {
		$t = 1_000_000;
		$HD = 'h264 1920x1080 25i · mp2 48k 2ch';
		$SD = 'h264 720x576 25i · aac 48k 2ch';
		$S = static fn (int $i): string => 'http://s' . $i . '.example/live/u' . $i . '/p/1.ts';
		// The stream is watched in HD (most time on air), whatever is first.
		$rRows = [self::row(0, $S(0), 80, 'high', 90000, 0, $HD), self::row(1, $S(1), 85, 'high', 3600, 0, $SD), self::row(2, $S(2), 75, 'high', 7200, 0, $HD), self::row(3, $S(3), null, 'none', 0, 0, '')];
		$this->assertSame($HD, AutoRank::mainProfile($rRows));
		$this->assertSame([$HD => 'A', $SD => 'B'], AutoRank::letters($rRows, $HD));
		// Order behind the first: its profile, then unknown, then other profiles.
		$this->assertSame([0, 2, 3, 1], AutoRank::proposedOrder($rRows, [], $HD, 0));
		$this->assertSame([0, 3, 1, 2], AutoRank::proposedOrder($rRows, [md5($S(2)) => ['status' => 'dead']], $HD, 0));   // off by the audit: last
		// Within PROFILE_SLACK, the main profile goes first; beyond it, the better source does.
		$this->assertSame(2, AutoRank::best(array_slice($rRows, 1), [], $HD)['index']);
		$rFar = [self::row(1, $S(1), 99, 'high', 3600, 0, $SD), self::row(2, $S(2), 50, 'high', 7200, 0, $HD)];
		$this->assertSame(1, AutoRank::best($rFar, [], $HD)['index']);

		// The first is fine, but a drop would fall on SD: a NEXT IN LINE proposal, twice to confirm.
		$r1 = AutoRank::decide($rRows, [], null, $t);
		$r2 = AutoRank::decide($rRows, [], $r1['state'], $t + 1800);
		$d = $r2['decision'];
		$this->assertSame(['order', 1, 2, 'B', 'A', '1,2,3,4', '1,3,4,2'], [$d['kind'], $d['old_index'], $d['new_index'], $d['old_profile'], $d['new_profile'], $d['order_before'], $d['order_after']]);
		$this->assertSame('a drop would fall back on profile B (#2); #3 keeps profile A', $d['reason']);
		// Already in profile order: nothing to propose.
		$rGood = [$rRows[0], $rRows[2], $rRows[3], $rRows[1]];
		foreach ($rGood as $k => &$r) {
			$r['index'] = $k;
		}
		unset($r);
		$this->assertSame(0, AutoRank::decide($rGood, [], null, $t)['state']['pending_count']);

		// A FIRST proposal prefers the main profile: an SD source 9 points ahead does not take the place of an HD one.
		$rFirst = [self::row(0, $S(0), 10, 'high', 90000, 0, $HD), self::row(1, $S(1), 90, 'high', 3600, 0, $SD), self::row(2, $S(2), 81, 'high', 7200, 0, $HD)];
		$d = AutoRank::decide($rFirst, [], AutoRank::decide($rFirst, [], null, $t)['state'], $t + 1800)['decision'];
		$this->assertSame(['first', 2, 'A', '3,1,2'], [$d['kind'], $d['new_index'], $d['new_profile'], $d['order_after']]);

		// Failovers of a day, and those that changed profile.
		$rEvents = [
			['server_id' => 1, 'action' => 'STREAM_START', 'source' => $S(0), 'date' => 1], ['server_id' => 1, 'action' => 'STREAM_FAILED', 'source' => $S(0), 'date' => 2],
			['server_id' => 1, 'action' => 'STREAM_START', 'source' => $S(1), 'date' => 3], ['server_id' => 1, 'action' => 'STREAM_START', 'source' => $S(2), 'date' => 4],
			['server_id' => 2, 'action' => 'STREAM_START', 'source' => $S(2), 'date' => 5], ['server_id' => 1, 'action' => 'STREAM_START', 'source' => $S(3), 'date' => 6],
		];
		$this->assertSame([3, 2], AutoRank::switches($rEvents, [md5($S(0)) => $HD, md5($S(1)) => $SD, md5($S(2)) => $HD]));   // 0→1 and 1→2 change profile, 2→3 unknown
	}

	public function testAutoProbesSpareTheSourceOnAirAndBusyAccounts(): void {
		$t = 2_000_000;
		$c = static fn (string $src, string $why, int $last = 0, int $id = 1): array => ['stream_id' => $id, 'source' => $src, 'why' => $why, 'every' => $why === 'proposed' ? AutoProbe::PROPOSED_EVERY_SEC : AutoProbe::PROFILE_EVERY_SEC, 'last' => $last];
		$rOnAir = ['http://live.example/live/a/b/1.ts'];
		$rBusyBackup = 'http://live.example/live/a/b/2.ts';                         // same account as the one on air
		$rCands = [
			$c('http://live.example/live/a/b/1.ts', 'profile'),                       // on air: never
			$c($rBusyBackup, 'profile'),
			$c('http://x.example/live/c/d/1.ts', 'profile', $t - 3600),             // probed an hour ago: not due
			$c('http://y.example/live/e/f/1.ts', 'profile'),
			$c('http://y.example/live/e/f/2.ts', 'profile'),                         // one per account per run
			$c('http://z.example/live/g/h/1.ts', 'proposed', $t - 3 * 3600),
			$c('http://dead.example/live/i/j/1.ts', 'proposed'),
			$c('udp://239.0.0.1:1234', 'profile'),
		];
		$rAudit = [md5('http://dead.example/live/i/j/1.ts') => ['status' => 'dead']];
		$rPlan = AutoProbe::plan($rCands, $rOnAir, [], $rAudit, $t);
		$this->assertSame(['http://z.example/live/g/h/1.ts', 'http://y.example/live/e/f/1.ts'], array_column($rPlan, 'source'));   // proposed first
		// A busy account only with fresh word that a connection is free.
		$rKey = md5((string) \XcVm\Module\SourceHealth\SourceProber::accountKey($rBusyBackup));
		$rFree = [$rKey => ['api' => true, 'max' => 10, 'active' => 2, 'checked_at' => $t - 60]];
		$this->assertContains($rBusyBackup, array_column(AutoProbe::plan($rCands, $rOnAir, $rFree, $rAudit, $t, 10), 'source'));
		$this->assertNotContains($rBusyBackup, array_column(AutoProbe::plan($rCands, $rOnAir, [$rKey => ['api' => true, 'max' => 2, 'active' => 2, 'checked_at' => $t - 60]], $rAudit, $t, 10), 'source'));
		// The login on air through another domain is the same account: never probed.
		$this->assertSame([], AutoProbe::plan([$c('http://alias.example:8080/live/a/b/9.ts', 'profile')], $rOnAir, [], [], $t, 10));
		$this->assertFalse(AutoProbe::ENABLED);
	}
}
