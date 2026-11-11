<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use XcVm\Cli\CommandRegistry;
use XcVm\Core\Module\ModuleLoader;
use XcVm\Module\SourceHealth\SourceFingerprint;
use XcVm\Module\SourceHealth\SourceHealthCalculator;
use XcVm\Module\SourceHealth\SourceHealthTab;

/**
 * The source-health module's pure parts: reading streams_logs into per-source
 * figures, the score, and the output fingerprint.
 */
final class SourceHealthCalculatorTest extends TestCase {
	private const A = 'http://a.example:8080/live/u/p/1.ts';
	private const B = 'http://b.example/live/u/p/1.ts';
	private const C = 'http://c.example/hls/1.m3u8';

	public static function setUpBeforeClass(): void {
		$rDir = glob(dirname(__DIR__, 2) . '/src/Modules/source-health_*')[0] ?? null;
		if ($rDir === null) {
			self::markTestSkipped('The source-health module is not installed in this tree.');
		}
		foreach (['SourceHealthCalculator', 'SourceFingerprint', 'SourceHealthTab'] as $rClass) {
			require_once $rDir . '/' . $rClass . '.php';
		}
	}

	private static function ev(int $rServer, string $rAction, string $rSource, int $rDate): array {
		return ['server_id' => $rServer, 'action' => $rAction, 'source' => $rSource, 'date' => $rDate];
	}

	/** @return array<int, array<string, mixed>> rows keyed by source index */
	private static function rows(array $rEvents, int $rFrom = 1000, int $rNow = 10000, array $rRunning = []): array {
		return array_column(SourceHealthCalculator::compute([self::A, self::B, self::C], $rEvents, $rFrom, $rNow, $rRunning), null, 'index');
	}

	public function testFailoverChargesTheFailedSourceAndCreditsTimeOnAir(): void {
		$rRows = self::rows([
			self::ev(1, 'STREAM_START', self::A, 1000),
			self::ev(1, 'STREAM_FAILED', self::A, 4600),     // A ran 1 h, then failed
			self::ev(1, 'STREAM_START_FAIL', self::B, 4610), // B did not come up
			self::ev(1, 'STREAM_RESTART', self::C, 4620),    // C took over and still runs
		], 1000, 10000, [1 => self::C]);

		$this->assertSame(1, $rRows[0]['starts']);
		$this->assertSame(1, $rRows[0]['failures']);
		$this->assertSame(3600, $rRows[0]['uptime_sec']);
		$this->assertSame(1, $rRows[1]['start_fails']);
		$this->assertSame(0, $rRows[1]['uptime_sec']);
		$this->assertSame(10000 - 4620, $rRows[2]['uptime_sec']);
		$this->assertTrue($rRows[2]['running']);
		$this->assertFalse($rRows[0]['running']);
	}

	public function testASilentEndIsNotAFailureAndAPlannedSwitchIsCountedAsSuch(): void {
		$rRows = self::rows([
			self::ev(1, 'STREAM_START', self::A, 1000),
			self::ev(1, 'STREAM_START', self::A, 2000),     // new supervision (daemon restart): no reason logged
			self::ev(1, 'PRIORITY_SWITCH', self::B, 3000),  // planned move to B
			self::ev(1, 'STREAM_RESTART', self::B, 3001),
			self::ev(1, 'STREAM_STOP', '', 5001),
		]);

		$this->assertSame(0, $rRows[0]['failures']);
		$this->assertSame(2, $rRows[0]['starts']);
		$this->assertSame(1, $rRows[0]['switched_away']);
		$this->assertSame(2001, $rRows[0]['uptime_sec']);
		$this->assertSame(0, $rRows[1]['failures']);
		$this->assertSame(2000, $rRows[1]['uptime_sec']);
	}

	public function testQualityIssueIsCountedOnceNotAgainAsAFailure(): void {
		$rRows = self::rows([
			self::ev(1, 'STREAM_START', self::A, 1000),
			self::ev(1, 'AUDIO_LOSS', self::A, 1500),
			self::ev(1, 'STREAM_RESTART', self::A, 1510),
		], 1000, 2000, [1 => self::A]);

		$this->assertSame(1, $rRows[0]['quality_issues']);
		$this->assertSame(0, $rRows[0]['failures']);
		$this->assertSame(2, $rRows[0]['starts']);
		$this->assertSame(1000, $rRows[0]['uptime_sec']);
	}

	public function testARunOpenBeforeTheWindowCountsFromTheWindowStart(): void {
		$rRows = self::rows([
			self::ev(1, 'STREAM_START', self::A, 100), // seed: on air when the window opened
			self::ev(1, 'STREAM_STOP', '', 1600),
		], 1000, 10000);

		$this->assertSame(0, $rRows[0]['starts']);
		$this->assertSame(600, $rRows[0]['uptime_sec']);
		$this->assertSame(0, $rRows[0]['failures']);
	}

	public function testServersAreReadIndependently(): void {
		$rRows = self::rows([
			self::ev(1, 'STREAM_START', self::A, 1000),
			self::ev(2, 'STREAM_START', self::B, 1000),
			self::ev(2, 'STREAM_FAILED', self::B, 1500), // server 2's B fails; server 1's A is untouched
		], 1000, 2000, [1 => self::A]);

		$this->assertSame(0, $rRows[0]['failures']);
		$this->assertSame(1000, $rRows[0]['uptime_sec']);
		$this->assertSame(1, $rRows[1]['failures']);
		$this->assertSame(500, $rRows[1]['uptime_sec']);
	}

	public function testAnOpenRunWhoseEncoderIsGoneIsNotCreditedUntilNow(): void {
		$rRows = self::rows([
			self::ev(1, 'STREAM_START', self::A, 1000),
			self::ev(1, 'AUTO_RESTART', self::A, 1200), // last event on the server
		], 1000, 10000, []);

		$this->assertSame(200, $rRows[0]['uptime_sec']);
		$this->assertFalse($rRows[0]['running']);
	}

	public function testUnknownSourcesAndBlankLinesAreIgnored(): void {
		$rRows = SourceHealthCalculator::compute([self::A, '  ', self::A], [
			self::ev(1, 'STREAM_START', 'http://removed.example/x.ts', 1000),
			self::ev(1, 'STREAM_FAILED', 'http://removed.example/x.ts', 1100),
		], 1000, 2000);

		$this->assertCount(1, $rRows);
		$this->assertSame(0, $rRows[0]['failures']);
	}

	/**
	 * bandookk, 2026-11-09: 136 drops in 3 days on air, yet hours clean in VLC
	 * and on the panel. The drops come in bursts — the panel retrying through one
	 * outage — so they are a few incidents, and the score is about those.
	 */
	public function testABurstOfDropsDuringOneOutageIsOneIncident(): void {
		$rEvents = [];
		$t = 1000;
		foreach ([0, 86400, 2 * 86400] as $rDay) {               // one outage a day …
			for ($i = 0; $i < 40; $i++) {                         // … of 40 quick drops, 1 min apart
				$rEvents[] = self::ev(1, 'STREAM_RESTART', self::A, $t + $rDay + 60 * $i);
				$rEvents[] = self::ev(1, 'STREAM_FAILED', self::A, $t + $rDay + 60 * $i + 50);
			}
			$rEvents[] = self::ev(1, 'STREAM_RESTART', self::A, $t + $rDay + 2400); // then up all day
		}
		$rRows = self::rows($rEvents, 1000, 1000 + 3 * 86400, [1 => self::A]);

		$this->assertSame(120, $rRows[0]['failures']);
		$this->assertSame(3, $rRows[0]['incidents']);
		$this->assertGreaterThan(75, $rRows[0]['score']);
	}

	public function testIncidentTimesAndALastDayWindowFromTheSameLog(): void {
		$rDay = 86400;
		$rEvents = [
			self::ev(1, 'STREAM_START', self::A, 1000),
			self::ev(1, 'STREAM_FAILED', self::A, 2000),
			self::ev(1, 'STREAM_RESTART', self::A, 2010),
			self::ev(1, 'STREAM_FAILED', self::A, 2500),          // same outage
			self::ev(1, 'STREAM_RESTART', self::A, 2510),          // then clean for days
		];
		$rNow = 1000 + 3 * $rDay;
		$rWeek = self::rows($rEvents, 1000, $rNow, [1 => self::A]);
		$rLastDay = self::rows($rEvents, $rNow - $rDay, $rNow, [1 => self::A]);

		$this->assertSame([2000], $rWeek[0]['incident_times']);
		$this->assertSame(0, $rLastDay[0]['incidents']);
		$this->assertSame($rDay, $rLastDay[0]['uptime_sec']);       // the run open since 2510 seeds the day
		$this->assertGreaterThanOrEqual(95, $rLastDay[0]['score']);
	}

	/**
	 * 2026-11-10: a source probed 12/12 ok from outside had 289 failed starts
	 * in the panel. The encoder's error lines say why; they carry no source, so
	 * each is put with the source its server logged an event for nearest in time.
	 */
	public function testErrorLinesArePutWithTheSourceInUseAtTheTime(): void {
		$rEvents = [
			self::ev(1, 'STREAM_START', self::A, 1000),
			self::ev(1, 'STREAM_FAILED', self::A, 2000),
			self::ev(1, 'STREAM_START_FAIL', self::B, 2010),
			self::ev(2, 'STREAM_START', self::C, 1000),
		];
		$rErrors = [
			['server_id' => 1, 'date' => 1995, 'error' => '[http @ 0x55d4c3a1b2c0] HTTP error 509 Bandwidth Limit Exceeded'],   // just before A's drop
			['server_id' => 1, 'date' => 1996, 'error' => '[http @ 0x55d4c3a1b9f0] HTTP error 509 Bandwidth Limit Exceeded'],
			['server_id' => 1, 'date' => 2008, 'error' => 'http://b.example/live/u/p/1.ts: Connection refused'],                // B's failed start (after A's drop at 2000)
			['server_id' => 2, 'date' => 1050, 'error' => 'Non-monotonous DTS in output stream 0:1; previous: 81234, current: 81000'],
			['server_id' => 1, 'date' => 9000, 'error' => 'far from any event'],                                              // after B's failed start: nothing on air
			['server_id' => 2, 'date' => 50000, 'error' => 'Non-monotonous DTS in output stream 0:1; previous: 9, current: 8'], // C's run, hours in
		];
		$rOut = SourceHealthCalculator::errorsBySource([self::A, self::B, self::C], $rEvents, $rErrors);

		$this->assertSame([['HTTP error 509 Bandwidth Limit Exceeded', 2, 1996, false]], $rOut['by_source'][0]);
		$this->assertSame([['(url) Connection refused', 1, 2008, true]], $rOut['by_source'][1]);
		$this->assertSame(['Non-monotonous DTS in output stream #:#; previous: #, current: #', 2, 50000, false], $rOut['by_source'][2][0]);
		$this->assertSame(1, $rOut['unattributed']);
	}

	/**
	 * 2026-11-10, real panel: the fanout remuxer writes a banner and a stream
	 * description on every launch; those filled the top five and hid the one
	 * line that says why ("source failed: …"). They are now launches, not errors.
	 */
	public function testLaunchBannersAndStreamDescriptionsAreNotErrors(): void {
		$rLaunch = [
			'2026/11/10 19:36:01 [remux] xc_fanout 1.4.2 native remuxer, pid 41234',
			"2026/11/10 19:36:01 [remux] Input #0, mpegts, from 'http://a.example:8080/live/u/p/1.ts':",
			'  Program 1',
			'    Stream #0:0[0x100]: Video: h264 (High), yuv420p, 1920x1080, 25 fps',
			"Output #0, hls, to '/home/xc_vm/content/streams/561_.m3u8':",
		];
		$rErrors = [];
		foreach ([1000, 3000] as $t) {
			foreach ($rLaunch as $rLine) {
				$rErrors[] = ['server_id' => 1, 'date' => $t + 1, 'error' => $rLine];
			}
		}
		$rErrors[] = ['server_id' => 1, 'date' => 1990, 'error' => '[remux] source failed: read tcp 10.0.0.2:51234->1.2.3.4:80: i/o timeout'];
		$rErrors[] = ['server_id' => 1, 'date' => 2995, 'error' => '[remux] source failed: remux: unsupported source: http://x/y a web page, not a stream'];
		$rEvents = [
			self::ev(1, 'STREAM_START', self::A, 1000),
			self::ev(1, 'STREAM_FAILED', self::A, 2000),
			self::ev(1, 'STREAM_RESTART', self::A, 3000),
		];
		$rOut = SourceHealthCalculator::errorsBySource([self::A], $rEvents, $rErrors);

		$this->assertSame(2, $rOut['launches'][0]);
		$this->assertSame(['source failed: read tcp (ip):#->(ip):#: i/o timeout', 'source failed: remux: unsupported source: (url) a web page, not a stream'], array_column($rOut['by_source'][0], 0));
		$this->assertSame('info', SourceHealthCalculator::errorKind('2026/11/10 19:36:01 [remux] stopped'));
		$this->assertSame('source failed: Get "(url)": EOF', SourceHealthCalculator::normalizeError('[remux] source failed: Get "http://a.example/live/u/p/1.ts": EOF'));
		$this->assertSame('error', SourceHealthCalculator::errorKind('[http @ 0x55] HTTP error 403 Forbidden'));
	}

	/**
	 * 2026-11-10, real panel: "lookup newday22.ddns.net: no such host" showed
	 * under all four sources. streams_errors dates a line when the cron copied
	 * it (every line of a run shares one time) and the panel walks the list in
	 * seconds, so time cannot place it. The line names its host: that decides.
	 */
	public function testALineThatNamesAHostGoesToThatSourceOnly(): void {
		$rSources = ['http://lalelalja.com:80/u/p/ts', 'http://webstar-ks.ch:8080/u/p/1138', 'http://newday22.ddns.net:2095/u/p/69504', 'http://aflaxtv.xyz:8080/u/p/1306'];
		$rEvents = [];
		foreach ($rSources as $i => $rSource) {                     // one walk, a second per source
			$rEvents[] = self::ev(1, 'STREAM_START_FAIL', $rSource, 5000 + $i);
		}
		$rCopied = 5040;                                            // the cron ran once for all of them
		$rErrors = [
			['server_id' => 1, 'date' => $rCopied, 'error' => '2026/11/10 19:00:00 [remux] source failed: remux: unsupported source: http 404'],
			['server_id' => 1, 'date' => $rCopied, 'error' => '2026/11/10 19:00:01 [remux] source failed: Get "http://webstar-ks.ch:8080/u/p/1138": EOF'],
			['server_id' => 1, 'date' => $rCopied, 'error' => '2026/11/10 19:00:02 [remux] source failed: Get "http://newday22.ddns.net:2095/u/p/69504": dial tcp: lookup newday22.ddns.net on 127.0.0.53:53: no such host'],
			['server_id' => 1, 'date' => $rCopied, 'error' => "2026/11/10 19:00:03 [remux] Input #0, mpegts, from 'http://aflaxtv.xyz:8080/u/p/1306':"],
		];
		$rOut = SourceHealthCalculator::errorsBySource($rSources, $rEvents, $rErrors);

		$this->assertStringContainsString('no such host', $rOut['by_source'][2][0][0]);
		$this->assertCount(1, $rOut['by_source'][2]);
		$this->assertStringContainsString('EOF', $rOut['by_source'][1][0][0]);
		$this->assertSame([3 => 1], $rOut['launches']);
		$this->assertSame(3, $rOut['by_name']);
		foreach ([1, 3] as $i) {
			foreach ($rOut['by_source'][$i] ?? [] as [$rMessage]) {
				$this->assertStringNotContainsString('newday22', $rMessage);
			}
		}
	}

	public function testAnErrorNamingARedirectHostGoesToTheSourceThatRedirectsThere(): void {
		$rSources = ['http://prov.example:8080/u/p/1306', 'http://other.example/u/p/7', 'http://third.example/u/p/9'];
		$rEvents = [self::ev(1, 'STREAM_START', $rSources[1], 5000)];   // source 2 on air: time alone would blame it
		$rErrors = [
			['server_id' => 1, 'date' => 5010, 'error' => '2026/11/10 19:00:00 [remux] source failed: Get "http://192.142.25.110/auth/tok123": a web page'],
			['server_id' => 1, 'date' => 5010, 'error' => '2026/11/10 19:00:05 [remux] source failed: Get "http://lb.shared.example/x": EOF'],
		];
		$rRedirects = [
			md5($rSources[0]) => ['192.142.25.110', 'lb.shared.example'],
			md5($rSources[2]) => ['lb.shared.example', 'other.example'],   // shared, and another source's own host: both ignored
		];
		$rOut = SourceHealthCalculator::errorsBySource($rSources, $rEvents, $rErrors, 120, $rRedirects);

		$this->assertStringContainsString('a web page', $rOut['by_source'][0][0][0]);
		$this->assertTrue($rOut['by_source'][0][0][3]);                   // by host
		$this->assertSame(1, $rOut['by_name']);
		$this->assertStringContainsString('EOF', $rOut['by_source'][1][0][0]); // the shared host fell back to time
		$this->assertSame(1, $rOut['by_time']);
		$this->assertArrayNotHasKey(2, $rOut['by_source']);
	}

	public function testRunLengthsAreTakenFromWholeRunsAndASteadyLengthIsFlagged(): void {
		$rEvents = [self::ev(1, 'STREAM_START', self::A, 500)];          // opened before the window: not seen whole
		$t = 1100;
		$rEvents[] = self::ev(1, 'STREAM_FAILED', self::A, $t);
		foreach ([7400, 7450, 7380, 7420, 7500, 7410] as $rLength) {      // ~2h03m every time
			$rEvents[] = self::ev(1, 'STREAM_START', self::A, $t += 10);
			$rEvents[] = self::ev(1, 'STREAM_FAILED', self::A, $t += $rLength);
		}
		$rRow = self::rows($rEvents, 1000, $t + 100)[0];
		$this->assertSame([7400, 7450, 7380, 7420, 7500, 7410], $rRow['run_lengths']);

		$rStats = SourceHealthCalculator::runLengths($rRow['run_lengths']);
		$this->assertSame([6, 7380, 7500], [$rStats['count'], $rStats['min'], $rStats['max']]);
		$this->assertSame(6, $rStats['buckets']['2–4h']);
		$this->assertTrue($rStats['steady']);

		$rSpread = SourceHealthCalculator::runLengths([120, 900, 3000, 6000, 20000]);
		$this->assertFalse($rSpread['steady']);
		$this->assertSame(1, $rSpread['buckets']['>4h']);
		$this->assertFalse(SourceHealthCalculator::runLengths([7400, 7400])['steady']);  // too few to tell
		$this->assertNull(SourceHealthCalculator::runLengths([]));
	}

	public function testRecoveryIsTheTimeUntilTheSourceHoldsAgain(): void {
		$rEvents = [
			self::ev(1, 'STREAM_START', self::A, 1000),
			self::ev(1, 'STREAM_FAILED', self::A, 8200),        // held 2h, drops
			self::ev(1, 'STREAM_START_FAIL', self::A, 8210),    // try 1: does not start
			self::ev(1, 'STREAM_START', self::A, 8230),         // try 2: 20 s, drops
			self::ev(1, 'STREAM_FAILED', self::A, 8250),
			self::ev(1, 'STREAM_START', self::A, 8260),         // try 3: holds 1h
			self::ev(1, 'STREAM_FAILED', self::A, 11860),       // drops again…
			self::ev(1, 'STREAM_START', self::B, 11870),        // …and the stream settles on B
			self::ev(1, 'STREAM_FAILED', self::B, 15000),
		];
		$rRows = self::rows($rEvents, 900, 16000);

		$this->assertSame([[60, 3]], $rRows[0]['recoveries']);
		$this->assertSame(1, $rRows[0]['moved_away']);
		$this->assertSame(['count' => 1, 'median' => 60, 'p75' => 60, 'max' => 60, 'attempts' => 3], SourceHealthCalculator::recoveryStats($rRows[0]['recoveries']));
		$this->assertNull(SourceHealthCalculator::recoveryStats([]));

		$rStats = SourceHealthCalculator::runLengths($rRows[0]['run_lengths']);
		$this->assertSame([3, 1, 2, 7200], [$rStats['count'], $rStats['short'], $rStats['held'], $rStats['max']]);
		$this->assertSame(20, $rStats['min']);
		$this->assertSame([3600, 7200], [$rStats['p25'], $rStats['p75']]); // of the runs that held
	}

	public function testACutLineIsCompletedAndReasonsAreClassified(): void {
		$rUrl = 'http://192.142.25.110/auth/' . str_repeat('x', 400);
		$rCut = static fn (string $rLine): string => mb_substr($rLine, 0, 500);
		$rFull = '2026/11/11 03:02:00 [remux] source failed: Get "' . $rUrl . '": net/http: timeout awaiting response headers';
		$rA = SourceHealthCalculator::normalizeError($rCut($rFull));
		$rB = SourceHealthCalculator::normalizeError($rCut(str_replace('/auth/', '/auth/zz', $rFull)));
		$this->assertSame('source failed: Get "(url)": net/http: timeout awaiting response headers', $rA);
		$this->assertSame($rA, $rB);
		$this->assertStringEndsWith('no such host', SourceHealthCalculator::normalizeError('lookup x: no such host'));

		$this->assertSame('gone', SourceHealthCalculator::reasonKind("remux: unsupported source: (url): a web page, not a stream (XUI.one - Debug Mode: Stream ID doesn't exist.)"));
		$this->assertSame('gone', SourceHealthCalculator::reasonKind('dial tcp: lookup pcaminf.zapto.org on (ip):#: no such host'));
		$this->assertSame('temporary', SourceHealthCalculator::reasonKind('Get "(url)": net/http: timeout awaiting response headers'));
		$this->assertSame('temporary', SourceHealthCalculator::reasonKind('remux: source ended'));
		$this->assertNull(SourceHealthCalculator::reasonKind('remux: unsupported source: http 404'));
	}

	public function testALineTimeStampIsUsedAndATimeZoneOffsetTakenOut(): void {
		$rStamp = strtotime('2026-11-10 19:00:00');
		$this->assertSame($rStamp, SourceHealthCalculator::lineTime('2026/11/10 19:00:00 [remux] x', $rStamp + 50));
		$this->assertSame($rStamp + 3600, SourceHealthCalculator::lineTime('2026/11/10 19:00:00 [remux] x', $rStamp + 3650)); // writer one hour behind
		$this->assertSame(777, SourceHealthCalculator::lineTime('  Program 1', 777));
	}

	public function testAFailedStartDoesNotHideTheNextDrop(): void {
		$rRows = self::rows([
			self::ev(1, 'STREAM_START_FAIL', self::A, 1000),
			self::ev(1, 'STREAM_RESTART', self::A, 1100),
			self::ev(1, 'STREAM_FAILED', self::A, 1300),
		], 1000, 5000);

		$this->assertSame(1, $rRows[0]['incidents']);
	}

	/** The figures from a real panel (2026-11-09): the score must rank them sensibly. */
	public function testScoreOnRealFigures(): void {
		$rScore = static fn (int $rStarts, int $rFails, int $rFailures, int $rUptime): ?int => SourceHealthCalculator::score([
			'starts' => $rStarts, 'start_fails' => $rFails, 'failures' => $rFailures, 'quality_issues' => 0, 'uptime_sec' => $rUptime,
		])[0];
		$rDash = $rScore(25, 3, 25, 6 * 86400 + 23 * 3600);  // 25 drops in a week
		$rWebstar = $rScore(29, 18, 21, 5 * 86400 + 20 * 3600);
		$rBandook = $rScore(18, 14, 14, 27 * 3600);
		$rLalelalja = $rScore(13, 2, 8, 50 * 60);         // drops every 6 minutes
		$rNeverUp = $rScore(0, 8, 0, 0);
		$rFewCleanMinutes = $rScore(3, 0, 0, 15 * 60);

		$this->assertGreaterThan($rWebstar, $rDash);
		$this->assertGreaterThan($rBandook, $rWebstar);
		$this->assertGreaterThan($rLalelalja, $rBandook);
		$this->assertLessThan(20, $rLalelalja);
		$this->assertLessThan(10, $rNeverUp);
		$this->assertGreaterThan(50, $rFewCleanMinutes);
		$this->assertLessThan(80, $rDash);                 // not "excellent" with 3 drops a day
	}

	public function testScoreAndSuggestedOrder(): void {
		$rRows = self::rows([
			self::ev(1, 'STREAM_START_FAIL', self::A, 1000),
			self::ev(1, 'STREAM_START_FAIL', self::A, 1010),
			self::ev(1, 'STREAM_START', self::B, 1020),
		], 1000, 1000 + 90000, [1 => self::B]);

		[$rNone] = SourceHealthCalculator::score($rRows[2]);
		$this->assertNull($rNone);
		$this->assertSame('high', $rRows[1]['confidence']);
		$this->assertGreaterThan(95, $rRows[1]['score']);
		$this->assertLessThan(30, $rRows[0]['score']);
		$this->assertSame([1, 0, 2], SourceHealthCalculator::suggestedOrder(array_values($rRows)));
	}

	public function testFingerprintGroupsSourcesThatPlayersCanSwitchBetween(): void {
		$rInfo = static fn (string $vc, int $h, string $fps, string $ac, int $ch, string $profile = 'High'): array => ['codecs' => [
			'video' => ['codec_name' => $vc, 'profile' => $profile, 'width' => $h * 16 / 9, 'height' => $h, 'avg_frame_rate' => $fps, 'field_order' => 'progressive'],
			'audio' => ['codec_name' => $ac, 'sample_rate' => '48000', 'channels' => $ch],
		]];
		$a = SourceFingerprint::fromStreamInfo($rInfo('h264', 1080, '25/1', 'aac', 2));
		$b = SourceFingerprint::fromStreamInfo($rInfo('h264', 1080, '25/1', 'aac', 2, 'Main'));
		$c = SourceFingerprint::fromStreamInfo($rInfo('h264', 1080, '30000/1001', 'aac', 2));
		$d = SourceFingerprint::fromStreamInfo($rInfo('h264', 1080, '25/1', 'ac3', 6));

		$this->assertSame($a['key'], $b['key']);
		$this->assertNotSame($a['key'], $c['key']);
		$this->assertNotSame($a['key'], $d['key']);
		$this->assertSame('h264 1920x1080 25p · aac 48k 2ch', $a['label']);
		$this->assertNull(SourceFingerprint::fromStreamInfo(['codecs' => ['video' => '', 'audio' => '']]));
		$this->assertNull(SourceFingerprint::fromStreamInfo(null));
	}

	public function testMaskHidesCredentials(): void {
		$this->assertSame('http://a.example:8080/…/1.ts', SourceHealthTab::mask(self::A));
		$this->assertSame('https://cdn.example/…/index.m3u8', SourceHealthTab::mask('https://cdn.example/token/abc/index.m3u8?sig=secret'));
		$this->assertSame('3h 5m', SourceHealthTab::duration(3 * 3600 + 300));
	}

	public function testTheLoaderDiscoversTheModuleWithItsCronAndCommand(): void {
		$rLoader = (new ModuleLoader())->loadAll(dirname(__DIR__, 2) . '/src/Modules');

		$this->assertTrue($rLoader->isLoaded('source-health'));
		$this->assertCount(1, $rLoader->collectCronEntries());
		$this->assertStringStartsWith('*/5 * * * * ', $rLoader->collectCronEntries()[0]);
		$this->assertStringContainsString(' cron:source_health ', $rLoader->collectCronEntries()[0]);
		$rRegistry = new CommandRegistry();
		$rLoader->registerAllCommands($rRegistry);
		$this->assertNotNull($rRegistry->get('cron:source_health'));
		$rManifest = $rLoader->getManifest('source-health');
		$this->assertSame($rManifest['version'] ?? null, $rLoader->getModule('source-health')?->getVersion());
	}
}
