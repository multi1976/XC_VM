<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use XcVm\Infrastructure\Database\DatabaseFactory;
use XcVm\Module\SourceHealth\SourceAudit;
use XcVm\Module\SourceHealth\SourceAuditPage;
use XcVm\Module\SourceHealth\SourceHealthRepository;
use XcVm\Tests\Support\InstallSchema;

/**
 * Source Audit: which sources get checked (never the line on air, nor its
 * provider account), what a check concludes, and when a source is dead.
 */
final class SourceAuditTest extends TestCase {
	public static function setUpBeforeClass(): void {
		$rDir = glob(dirname(__DIR__, 2) . '/src/Modules/source-health_*')[0] ?? null;
		if ($rDir === null) {
			self::markTestSkipped('The source-health module is not installed in this tree.');
		}
		foreach (['SourceFingerprint', 'SourceHealthCalculator', 'SourceHealthRepository', 'SourceProber', 'SourceHealthTab', 'SourceAudit', 'SourceAuditPage'] as $rClass) {
			require_once $rDir . '/' . $rClass . '.php';
		}
	}

	private static function src(string $rSource, int $rStream = 1): array {
		return [md5($rSource) => ['source' => $rSource, 'stream_id' => $rStream]];
	}

	public function testTheLineOnAirAndItsAccountAreNeverChecked(): void {
		$rSources = self::src('http://p.example/live/u/p/1.ts')     // on air
			+ self::src('http://p.example/live/u/p/2.ts')           // same account as the line on air
			+ self::src('http://p.example/live/other/pw/3.ts')      // another account on that host
			+ self::src('rtmp://r.example/app/4')                    // not http
			+ self::src('http://q.example/x/5.ts');
		$rDue = SourceAudit::plan($rSources, [], ['http://p.example/live/u/p/1.ts'], 100000);
		$this->assertSame(['http://p.example/live/other/pw/3.ts', 'http://q.example/x/5.ts'], array_column($rDue, 'source'));
	}

	public function testIntervalsOrderAndTheCapPerProvider(): void {
		$rSources = [];
		foreach (range(1, 8) as $i) {
			$rSources += self::src('http://big.example/live/a' . $i . '/p/' . $i . '.ts');
		}
		$rSources += self::src('http://ok.example/1.ts') + self::src('http://down.example/1.ts') + self::src('http://new.example/1.ts');
		$rNow = 1_000_000;
		$rState = [
			md5('http://ok.example/1.ts')   => ['checked_at' => $rNow - 3600, 'status' => 'ok'],           // up, checked an hour ago: not due
			md5('http://down.example/1.ts') => ['checked_at' => $rNow - 5 * 3600, 'status' => 'down'],     // failing, 5 h ago: due
		];
		$rDue = SourceAudit::plan($rSources, $rState, [], $rNow, 60, 5);
		$rHosts = array_map(static fn (array $r): string => SourceAudit::host($r['source']), $rDue);
		$this->assertSame(5, count(array_keys($rHosts, 'big.example')));       // at most 5 per provider per run
		$this->assertNotContains('ok.example', $rHosts);
		$this->assertContains('down.example', $rHosts);
		$this->assertSame('down.example', end($rHosts));                       // never-checked sources first
	}

	public function testASourceIsCalledDeadOnlyOnRepeatedEvidence(): void {
		$rGone = ['ok' => false, 'error' => "a web page, not a stream (XUI.one - Debug Mode: Stream ID doesn't exist.)"];
		$v1 = SourceAudit::verdict(null, $rGone, 1000);
		$this->assertSame(['down', 'gone', 1, 1000], [$v1['status'], $v1['kind'], $v1['fails'], $v1['first_fail']]);
		$v2 = SourceAudit::verdict($v1, $rGone, 2000);
		$this->assertSame(['dead', 2, 1000], [$v2['status'], $v2['fails'], $v2['first_fail']]);

		$rTimeout = ['ok' => false, 'error' => 'timeout awaiting response headers'];
		$t0 = 1_000_000;
		$t = SourceAudit::verdict(null, $rTimeout, $t0);
		$t = SourceAudit::verdict($t, $rTimeout, $t0 + 4 * 3600);
		$t = SourceAudit::verdict($t, $rTimeout, $t0 + 8 * 3600);
		$this->assertSame('down', $t['status']);                               // three failures, but within 12 h
		$t = SourceAudit::verdict($t, $rTimeout, $t0 + 12 * 3600);
		$this->assertSame('dead', $t['status']);

		$rBack = SourceAudit::verdict($t, ['ok' => true, 'error' => ''], $t0 + 13 * 3600);
		$this->assertSame(['ok', 0, 0, $t0 + 13 * 3600], [$rBack['status'], $rBack['fails'], $rBack['first_fail'], $rBack['last_ok']]);
	}

	public function testWhatACheckConcludes(): void {
		$rTs = str_repeat("\x47" . str_repeat("\x00", 187), 4);
		$this->assertSame(['ok' => true, 'error' => ''], SourceAudit::classify(23, 'write error', 200, 'video/mp2t', $rTs));
		$this->assertSame(['ok' => true, 'error' => 'playlist'], SourceAudit::classify(0, '', 200, 'application/vnd.apple.mpegurl', "#EXTM3U\n#EXT-X-VERSION:3\n"));
		$this->assertSame('no such host', SourceAudit::classify(6, 'Could not resolve host: x', 0, '', '')['error']);
		$this->assertSame('http 404', SourceAudit::classify(0, '', 404, 'text/html', '<html>nf</html>')['error']);
		$this->assertSame('empty body', SourceAudit::classify(0, '', 200, 'video/mp2t', '')['error']);
		$rPage = SourceAudit::classify(0, '', 200, 'text/html; charset=UTF-8', "<html><head><title>XUI.one - Debug Mode</title><style>b{}</style></head><body><h1>Stream ID doesn't exist.</h1></body></html>");
		$this->assertSame(['ok' => false, 'error' => "a web page, not a stream (XUI.one - Debug Mode: Stream ID doesn't exist.)"], $rPage);
		$this->assertTrue(SourceAudit::classify(0, '', 200, 'video/x-flv', 'FLV' . str_repeat("\x01", 300))['ok']);
	}

	public function testARealCheckOverHttp(): void {
		$rDir = sys_get_temp_dir() . '/sh-audit-' . getmypid();
		@mkdir($rDir);
		$rPort = 21000 + getmypid() % 1000;
		file_put_contents($rDir . '/live.ts', str_repeat("\x47" . str_repeat("\x11", 187), 200));
		file_put_contents($rDir . '/router.php', '<?php if (str_starts_with($_SERVER["REQUEST_URI"], "/live/")) { header("Location: /live.ts", true, 302); return true; }'
			. ' if ($_SERVER["REQUEST_URI"] === "/gone") { header("Content-Type: text/html"); echo "<html><title>XUI.one - Debug Mode</title><h1>Stream ID doesn&#39;t exist.</h1></html>"; return true; }'
			. ' if ($_SERVER["REQUEST_URI"] === "/live.ts") { header("Content-Type: video/mp2t"); readfile(__DIR__ . "/live.ts"); return true; } http_response_code(404); return true;');
		$rServer = proc_open(['php', '-S', '127.0.0.1:' . $rPort, $rDir . '/router.php'], [0 => ['file', '/dev/null', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $rPipes);
		usleep(400000);
		try {
			$rUp = SourceAudit::check('http://127.0.0.1:' . $rPort . '/live/u/p/1.ts', ['user_agent' => 'XC_VM-test']);
			$rGone = SourceAudit::check('http://127.0.0.1:' . $rPort . '/gone', []);
			$rMissing = SourceAudit::check('http://127.0.0.1:' . $rPort . '/nope', []);
			$rRefused = SourceAudit::check('http://127.0.0.1:1/x', []);
		} finally {
			proc_terminate($rServer);
			proc_close($rServer);
			array_map('unlink', glob($rDir . '/*'));
			@rmdir($rDir);
		}
		$this->assertTrue($rUp['ok'], json_encode($rUp));
		$this->assertNotNull($rUp['ms']);
		$this->assertSame("a web page, not a stream (XUI.one - Debug Mode: Stream ID doesn't exist.)", $rGone['error']);
		$this->assertSame('http 404', $rMissing['error']);
		$this->assertSame('connection refused', $rRefused['error']);
	}

	public function testStateIsStoredPrunedAndShown(): void {
		$rDb = new TestDb();
		foreach (['streams', 'streams_servers'] as $rTable) {
			$rDb->exec(InstallSchema::table($rTable));
		}
		$rSchema = (string) file_get_contents(glob(dirname(__DIR__, 2) . '/src/Modules/source-health_*')[0] . '/database.sql');
		foreach (array_filter(array_map('trim', explode(';', $rSchema))) as $rStatement) {
			$rDb->exec($rStatement);
		}
		DatabaseFactory::set($rDb);
		try {
			$rA = 'http://p.example/live/u/p/1.ts';
			$rB = 'http://q.example/live/v/w/2.ts';
			$rC = 'http://dead.example/live/x/y/3.ts';
			$rDb->query("INSERT INTO `streams` (`id`, `type`, `stream_display_name`, `stream_source`) VALUES (5, 1, 'News', ?), (6, 1, 'Sport', ?), (7, 3, 'Created', ?)", json_encode([$rA, $rB, $rC]), json_encode([$rC]), json_encode(['/x.mp4']));
			$rDb->query("INSERT INTO `streams_servers` (`stream_id`, `server_id`, `pid`, `stream_status`, `current_source`) VALUES (5, 1, 10, 0, ?)", $rA);

			$this->assertSame([md5($rA), md5($rB), md5($rC)], array_keys(SourceHealthRepository::allLiveSources())); // live streams only, each source once
			SourceHealthRepository::saveAudit(md5($rC), 'dead.example', ['status' => 'dead', 'kind' => 'gone', 'reason' => 'no such host', 'fails' => 2, 'first_fail' => 900, 'last_ok' => 0], null, 1000);
			SourceHealthRepository::saveAudit(md5($rB), 'q.example', ['status' => 'ok', 'kind' => null, 'reason' => '', 'fails' => 0, 'first_fail' => 0, 'last_ok' => 1000], 120, 1000);
			SourceHealthRepository::saveAudit(md5('http://old.example/1.ts'), 'old.example', ['status' => 'down', 'kind' => null, 'reason' => 'http 500', 'fails' => 1, 'first_fail' => 1000, 'last_ok' => 0], null, 1000);
			SourceHealthRepository::pruneAudit(array_keys(SourceHealthRepository::allLiveSources()));
			$rState = SourceHealthRepository::auditState();
			$this->assertEqualsCanonicalizing([md5($rB), md5($rC)], array_keys($rState));       // old.example is in no stream any more
			$this->assertSame('dead', $rState[md5($rC)]['status']);

			$rHtml = SourceAuditPage::render(['status' => 'problems'], 2000);
			$this->assertStringContainsString('Dead + down: 2', $rHtml);                    // the dead source in two streams
			$this->assertStringContainsString('On air: 1', $rHtml);
			$this->assertStringContainsString('every checked source off', $rHtml);          // dead.example: both its sources off
			$this->assertStringNotContainsString('/u/p/', $rHtml);                          // accounts masked
			$this->assertSame(2, substr_count($rHtml, '>Dead</span>'));
		} finally {
			DatabaseFactory::reset();
		}
	}
}
