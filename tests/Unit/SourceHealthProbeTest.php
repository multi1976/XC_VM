<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use XcVm\Infrastructure\Database\DatabaseFactory;
use XcVm\Module\SourceHealth\SourceHealthCalculator;
use XcVm\Module\SourceHealth\SourceHealthRepository;
use XcVm\Module\SourceHealth\SourceProber;
use XcVm\Tests\Support\InstallSchema;

/**
 * Active probes of the source-health module: which sources may be probed
 * (never one on air, nor one on a provider account a live stream uses), a
 * real probe over HTTP, storage, and how probes enter the score and order.
 */
final class SourceHealthProbeTest extends TestCase {
	public static function setUpBeforeClass(): void {
		$rDir = glob(dirname(__DIR__, 2) . '/src/Modules/source-health_*')[0] ?? '';
		if ($rDir === '') {
			self::markTestSkipped('The source-health module is not installed in this tree.');
		}
		foreach (['SourceFingerprint', 'SourceHealthCalculator', 'SourceHealthRepository', 'SourceProber'] as $rClass) {
			require_once $rDir . '/' . $rClass . '.php';
		}
	}

	public function testAccountKeyIsTheProviderAccount(): void {
		$this->assertSame('http://prov.example:8080/live/user/pass/', SourceProber::accountKey('http://prov.example:8080/live/user/pass/101.ts'));
		$this->assertSame(SourceProber::accountKey('http://PROV.example:8080/live/user/pass/7.ts'), SourceProber::accountKey('http://prov.example:8080/live/user/pass/101.ts'));
		$this->assertNotSame(SourceProber::accountKey('http://prov.example:8080/live/other/pass/7.ts'), SourceProber::accountKey('http://prov.example:8080/live/user/pass/101.ts'));
		$this->assertNull(SourceProber::accountKey('/srv/media/file.ts'));
	}

	public function testPlanNeverTouchesALiveSourceOrItsAccount(): void {
		$rLive = 'http://a.example/live/u/p/1.ts';
		$rCandidates = [
			['stream_id' => 5, 'source' => $rLive],                                 // on air
			['stream_id' => 5, 'source' => 'http://a.example/live/u/p/2.ts'],       // same account as a live source
			['stream_id' => 5, 'source' => 'http://b.example/live/x/y/3.ts'],       // free, probed 10 min ago
			['stream_id' => 5, 'source' => 'http://c.example/live/x/y/4.ts'],       // free, never probed
			['stream_id' => 5, 'source' => 'http://d.example/live/x/y/5.ts'],       // free, probed 2 h ago
			['stream_id' => 5, 'source' => 'udp://239.0.0.1:1234'],                 // not probeable from MAIN
		];
		$rNow = 100000;
		$rLast = ['5:' . md5('http://b.example/live/x/y/3.ts') => $rNow - 600, '5:' . md5('http://d.example/live/x/y/5.ts') => $rNow - 7200];

		[$rDue, $rSkipped] = SourceProber::plan($rCandidates, $rLast, [$rLive], $rNow);

		$this->assertSame(['http://c.example/live/x/y/4.ts', 'http://d.example/live/x/y/5.ts'], array_column($rDue, 'source'));
		$this->assertSame(['on air', 'its provider account is in use by a live stream', 'not a network source'], array_column($rSkipped, 'reason'));
		$this->assertCount(1, SourceProber::plan($rCandidates, $rLast, [$rLive], $rNow, 1)[0]);
	}

	/**
	 * 2026-11-10: probes showed "80x" — the provider's connect burst (seconds
	 * of buffered video at once) was taken for the read speed. The rate is now
	 * measured after the burst.
	 */
	public function testSteadyRateIgnoresTheConnectBurst(): void {
		// 10 s of buffer arrives in the first 0.3 s, then real time.
		$rSamples = [[0.1, 5.0], [0.3, 10.0]];
		for ($w = 0.5; $w <= 12.01; $w += 0.5) {
			$rSamples[] = [$w, 10.0 + ($w - 0.3)];
		}
		$this->assertEqualsWithDelta(1.0, SourceProber::steadyRate($rSamples, false), 0.01);

		// Slow source: 0.6 s of media per second after the burst.
		$rSlow = [[0.2, 3.0]];
		for ($w = 1.0; $w <= 12.01; $w += 1.0) {
			$rSlow[] = [$w, 3.0 + 0.6 * $w];
		}
		$this->assertEqualsWithDelta(0.6, SourceProber::steadyRate($rSlow, false), 0.01);

		// Stalled after the burst: no sample past 4 s.
		$this->assertNull(SourceProber::steadyRate([[0.2, 8.0], [0.4, 9.0]], false));
		// A short file that ended on its own: all of it, fast.
		$this->assertSame(30.0, SourceProber::steadyRate([[0.2, 3.0], [0.4, 12.0]], true));
		$this->assertNull(SourceProber::steadyRate([], true));
	}

	public function testARealProbeOverHttp(): void {
		$rProbe = trim((string) shell_exec('command -v ffprobe'));
		$rFfmpeg = trim((string) shell_exec('command -v ffmpeg'));
		if ($rProbe === '' || $rFfmpeg === '') {
			$this->markTestSkipped('ffmpeg/ffprobe not installed');
		}
		$rDir = sys_get_temp_dir() . '/sh-probe-' . getmypid();
		@mkdir($rDir);
		shell_exec(escapeshellarg($rFfmpeg) . ' -v error -y -f lavfi -i testsrc2=size=320x180:rate=25 -f lavfi -i sine=sample_rate=48000 -t 12 -map 0:v -map 1:a -c:v libx264 -preset ultrafast -pix_fmt yuv420p -c:a aac -ac 2 -ar 48000 -f mpegts ' . escapeshellarg($rDir . '/live.ts'));
		$rPort = 18000 + getmypid() % 1000;
		$rServer = proc_open(['php', '-S', '127.0.0.1:' . $rPort, '-t', $rDir], [0 => ['file', '/dev/null', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $rPipes);
		usleep(400000);
		try {
			$rOk = SourceProber::probe('http://127.0.0.1:' . $rPort . '/live.ts', ['user_agent' => 'XC_VM-test'], $rProbe, $rFfmpeg);
			$rDead = SourceProber::probe('http://127.0.0.1:1/live/u/p/9.ts', [], $rProbe, $rFfmpeg);
		} finally {
			proc_terminate($rServer);
			proc_close($rServer);
			@unlink($rDir . '/live.ts');
			@rmdir($rDir);
		}

		$this->assertTrue($rOk['ok'], json_encode($rOk));
		$this->assertSame('h264 320x180 25p · aac 48k 2ch', $rOk['label']);
		$this->assertNotNull($rOk['open_ms']);
		$this->assertFalse($rDead['ok']);
		$this->assertStringStartsWith('could not open', $rDead['error']);
		$this->assertStringNotContainsString('/live/u/p/', $rDead['error']); // the account stays out of the stored error
	}

	public function testRedirectHostsAreFollowedWithoutReadingTheStream(): void {
		$rDir = sys_get_temp_dir() . '/sh-redir-' . getmypid();
		@mkdir($rDir);
		$rPort = 19000 + getmypid() % 1000;
		file_put_contents($rDir . '/router.php', '<?php if (str_starts_with($_SERVER["REQUEST_URI"], "/live/")) { header("Location: http://127.0.0.1:' . $rPort . '/auth/tok?x=1", true, 302); return true; }'
			. ' if (str_starts_with($_SERVER["REQUEST_URI"], "/auth/")) { header("Content-Type: video/mp2t"); echo str_repeat("G", 4096); return true; } http_response_code(404); return true;');
		$rServer = proc_open(['php', '-S', '127.0.0.1:' . $rPort, $rDir . '/router.php'], [0 => ['file', '/dev/null', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $rPipes);
		usleep(400000);
		try {
			$rVia = SourceProber::redirectHosts('http://localhost:' . $rPort . '/live/u/p/9.ts', ['user_agent' => 'XC_VM-test']);
			$rDirect = SourceProber::redirectHosts('http://127.0.0.1:' . $rPort . '/auth/tok', []);
		} finally {
			proc_terminate($rServer);
			proc_close($rServer);
			@unlink($rDir . '/router.php');
			@rmdir($rDir);
		}
		$this->assertSame(['127.0.0.1'], $rVia);
		$this->assertSame([], $rDirect);
	}

	public function testAnErrorPageSaysWhy(): void {
		$rPage = '<html><head><title>XUI.one - Debug Mode</title><style>' . str_repeat('#notfound{position:absolute}', 50) . '</style></head>'
			. '<body><h1>Stream ID doesn&#39;t exist.</h1><p>http://host/live/user/pass/70.ts</p></body></html>';
		$this->assertSame("XUI.one - Debug Mode: Stream ID doesn't exist. (url)", SourceProber::pageReason($rPage));

		$rDir = sys_get_temp_dir() . '/sh-page-' . getmypid();
		@mkdir($rDir);
		$rPort = 20000 + getmypid() % 1000;
		file_put_contents($rDir . '/page.html', $rPage);
		file_put_contents($rDir . '/router.php', '<?php if (str_starts_with($_SERVER["REQUEST_URI"], "/live/")) { header("Location: /page.html", true, 302); return true; } return false;');
		$rServer = proc_open(['php', '-S', '127.0.0.1:' . $rPort, '-t', $rDir, $rDir . '/router.php'], [0 => ['file', '/dev/null', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $rPipes);
		usleep(400000);
		try {
			$rTrace = SourceProber::trace('http://127.0.0.1:' . $rPort . '/live/u/p/70.ts', []);
		} finally {
			proc_terminate($rServer);
			proc_close($rServer);
			array_map('unlink', glob($rDir . '/*'));
			@rmdir($rDir);
		}
		$this->assertSame([], $rTrace['hosts']); // same host: not a redirect host
		$this->assertSame("XUI.one - Debug Mode: Stream ID doesn't exist. (url)", $rTrace['page']);
	}

	public function testProbesAreStoredAggregatedAndSwitchedPerStream(): void {
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
			$rDb->query("INSERT INTO `streams` (`id`, `type`, `stream_display_name`, `stream_source`) VALUES (5, 1, 'News', ?)", json_encode(['http://a/1.ts', 'http://b/2.ts']));
			$rDb->query("INSERT INTO `streams_servers` (`stream_id`, `server_id`, `pid`, `stream_status`, `current_source`) VALUES (5, 1, 10, 0, 'http://a/1.ts')");

			$this->assertSame([], SourceHealthRepository::probeStreams());
			SourceHealthRepository::setProbe(5, true, 1);
			SourceHealthRepository::setProbe(5, true, 1);
			$this->assertSame([5], SourceHealthRepository::probeStreams());
			$this->assertCount(2, SourceHealthRepository::probeCandidates([5]));
			$this->assertSame(['http://a/1.ts'], SourceHealthRepository::onAirSources());
			$rDb->query("INSERT INTO `streams_servers` (`stream_id`, `server_id`, `pid`, `stream_status`, `current_source`) VALUES (6, 1, 11, 0, 'http://a/7.ts'), (5, 2, 12, 0, 'http://a/1.ts'), (8, 1, 0, 1, 'http://a/9.ts')");
			$this->assertSame(['http://a:/' => 3], SourceHealthRepository::accountConnections()); // two servers on one channel count twice; a stopped one not at all
			$rDb->query('DELETE FROM `streams_servers` WHERE `stream_id` IN (6, 8) OR `server_id` = 2');

			foreach ([[true, 900], [true, 1100], [false, null]] as $i => [$rOk, $rOpen]) {
				SourceHealthRepository::saveProbe(5, 'http://b/2.ts', ['ok' => $rOk, 'open_ms' => $rOpen, 'speed' => $rOk ? 1.2 : null, 'label' => 'h264 1920x1080 25i', 'error' => $rOk ? '' : 'could not open: timed out'], 1000 + $i);
			}
			SourceHealthRepository::saveSkip(5, 'http://a/1.ts', 'on air', 1010);
			$rAfter = SourceHealthRepository::probeStats(5, 0, [md5('http://b/2.ts') => [50, 995]]); // 995: 5 s before the three probes
			$this->assertSame([3, 2], [$rAfter[md5('http://b/2.ts')]['after_drop'], $rAfter[md5('http://b/2.ts')]['after_drop_ok']]);
			$this->assertSame(0, SourceHealthRepository::probeStats(5, 0, [md5('http://b/2.ts') => [50]])[md5('http://b/2.ts')]['after_drop']); // 950 s before: too long ago
			$rStats = SourceHealthRepository::probeStats(5, 0);

			$this->assertSame([2, 3, 1100, false], [$rStats[md5('http://b/2.ts')]['ok'], $rStats[md5('http://b/2.ts')]['total'], $rStats[md5('http://b/2.ts')]['open_ms'], $rStats[md5('http://b/2.ts')]['last_ok']]);
			$this->assertSame([0, 'skip', 'on air'], [$rStats[md5('http://a/1.ts')]['total'], $rStats[md5('http://a/1.ts')]['last_kind'], $rStats[md5('http://a/1.ts')]['last_error']]);
			$this->assertSame(1010, SourceHealthRepository::lastProbes([5])['5:' . md5('http://a/1.ts')]);

			// The DB layer escapes < and > on read: shown text is restored once, not escaped twice.
			SourceHealthRepository::saveProbe(5, 'http://b/2.ts', ['ok' => false, 'open_ms' => null, 'speed' => null, 'label' => '', 'error' => 'could not open: (source URL) <404>'], 2000);
			$this->assertSame('could not open: (source URL) <404>', SourceHealthRepository::probeStats(5, 0)[md5('http://b/2.ts')]['last_error']);

			$rDb->exec(InstallSchema::table('streams_errors'));
			foreach ([[100, 'old'], [500, 'a'], [600, 'b']] as [$rDate, $rText]) {
				$rDb->query('INSERT INTO `streams_errors` (`stream_id`, `server_id`, `date`, `error`) VALUES (5, 1, ?, ?)', $rDate, $rText);
			}
			$this->assertSame(['a', 'b'], array_column(SourceHealthRepository::errors(5, 200), 'error'));
			$this->assertSame(['b'], array_column(SourceHealthRepository::errors(5, 200, 1), 'error'));

			SourceHealthRepository::saveHosts(5, 'http://b/2.ts', ['192.142.25.110', 'LB.Example'], 3000);
			SourceHealthRepository::saveHosts(5, 'http://b/2.ts', ['192.142.25.110'], 3100);
			$this->assertSame([md5('http://b/2.ts') => ['192.142.25.110', 'lb.example']], SourceHealthRepository::hosts(5, 0));
			$this->assertSame([md5('http://b/2.ts') => ['192.142.25.110']], SourceHealthRepository::hosts(5, 3050));

			SourceHealthRepository::deleteForStreams([5]);
			$this->assertSame([], SourceHealthRepository::hosts(5, 0));
			$this->assertSame([], SourceHealthRepository::probeStreams());
			$this->assertSame([], SourceHealthRepository::probeStats(5, 0));
		} finally {
			DatabaseFactory::reset();
		}
	}

	public function testProbesCountAsStartsAndTheOrderDiscountsThinData(): void {
		$rA = 'http://a/1.ts';
		$rB = 'http://b/2.ts';
		$rEvents = [];
		$t = 1000;
		for ($i = 0; $i < 30; $i++) {                                  // A: on air for days, an outage a day
			$rEvents[] = ['server_id' => 1, 'action' => 'STREAM_RESTART', 'source' => $rA, 'date' => $t + $i * 20000];
			$rEvents[] = ['server_id' => 1, 'action' => 'STREAM_FAILED', 'source' => $rA, 'date' => $t + $i * 20000 + 19000];
		}
		$rEvents[] = ['server_id' => 1, 'action' => 'STREAM_RESTART', 'source' => $rB, 'date' => $t + 600000]; // B: 2 minutes once
		$rEvents[] = ['server_id' => 1, 'action' => 'STREAM_STOP', 'source' => '', 'date' => $t + 600120];
		$rNow = $t + 7 * 86400;

		$rNoProbes = array_column(SourceHealthCalculator::compute([$rA, $rB], $rEvents, $t, $rNow), null, 'index');
		$rProbed = array_column(SourceHealthCalculator::compute([$rA, $rB], $rEvents, $t, $rNow, [], [md5($rB) => ['ok' => 2, 'total' => 20]]), null, 'index');

		// B barely ran: its raw score leans on the priors, so the order still puts A first.
		$this->assertGreaterThan($rNoProbes[0]['score'], $rNoProbes[1]['score']);
		$this->assertSame([0, 1], SourceHealthCalculator::suggestedOrder(array_values($rNoProbes)));
		// Probes that mostly fail drag B's start chance down.
		$this->assertLessThan($rNoProbes[1]['score'] / 2, $rProbed[1]['score']);
		$this->assertSame([20, 2], [$rProbed[1]['probe_total'], $rProbed[1]['probe_ok']]);
	}
}
