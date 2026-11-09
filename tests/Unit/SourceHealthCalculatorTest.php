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
