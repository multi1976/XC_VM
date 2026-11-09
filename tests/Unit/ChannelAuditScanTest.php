<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use XcVm\Module\ChannelAudit\AuditStandard;
use XcVm\Module\ChannelAudit\FileScanner;
use XcVm\Module\ChannelAudit\PacketAnalyzer;

/**
 * The channel-audit module's checks against real files made by ffmpeg: one that
 * meets the standard and one for each way a file can break a concatenated
 * channel. Skipped where ffmpeg/ffprobe are not installed.
 */
final class ChannelAuditScanTest extends TestCase {
	private static string $rDir = '';
	private static string $rProbe = '';
	private static string $rFfmpeg = '';

	public static function setUpBeforeClass(): void {
		$rModule = glob(dirname(__DIR__, 2) . '/src/Modules/channel-audit_*')[0] ?? '';
		if ($rModule === '') {
			self::markTestSkipped('The channel-audit module is not installed in this tree.');
		}
		foreach (['PacketAnalyzer', 'AuditStandard', 'FileScanner'] as $rClass) {
			require_once $rModule . '/' . $rClass . '.php';
		}
		self::$rProbe = trim((string) shell_exec('command -v ffprobe'));
		self::$rFfmpeg = trim((string) shell_exec('command -v ffmpeg'));
		if (self::$rProbe === '' || self::$rFfmpeg === '') {
			self::markTestSkipped('ffmpeg/ffprobe not installed');
		}
		self::$rDir = sys_get_temp_dir() . '/channel-audit-test-' . getmypid();
		@mkdir(self::$rDir);
	}

	public static function tearDownAfterClass(): void {
		if (self::$rDir !== '' && is_dir(self::$rDir)) {
			array_map('unlink', glob(self::$rDir . '/*') ?: []);
			rmdir(self::$rDir);
		}
	}

	private static function spec(array $rOverride = []): array {
		[$rSpec, $rErrors] = AuditStandard::normalize(array_merge(AuditStandard::DEFAULTS, ['width' => 320, 'height' => 180, 'gop' => 25], $rOverride));
		self::assertSame([], $rErrors);
		return $rSpec;
	}

	/** Make a 3 s test file: testsrc2 + a sine, H.264/AAC, GOP 25. $rVideoIn/$rAudioIn go before their input, $rOut before the output. */
	private static function make(string $rName, string $rVideoIn = '', string $rAudioIn = '', string $rOut = ''): string {
		$rPath = self::$rDir . '/' . $rName;
		if (!is_file($rPath)) {
			$rCmd = escapeshellarg(self::$rFfmpeg) . ' -v error -y ' . $rVideoIn . ' -f lavfi -i testsrc2=size=320x180:rate=25 '
				. $rAudioIn . ' -f lavfi -i sine=frequency=440:sample_rate=48000 -t 3 -map 0:v -map 1:a'
				. ' -c:v libx264 -preset ultrafast -pix_fmt yuv420p -g 25 -keyint_min 25 -sc_threshold 0'
				. ' -c:a aac -ac 2 -ar 48000 ' . $rOut . ' ' . escapeshellarg($rPath) . ' 2>&1';
			$rLog = shell_exec($rCmd);
			self::assertFileExists($rPath, (string) $rLog);
		}
		return $rPath;
	}

	private function scan(string $rPath, array $rSpec, bool $rDeep = false): array {
		return (new FileScanner(self::$rProbe, self::$rFfmpeg))->scan($rPath, $rSpec, $rDeep);
	}

	/** @return list<string> the fields that failed */
	private static function failed(array $rResult): array {
		return array_values(array_unique(array_map(static fn (array $i): string => $i[1], array_filter($rResult['issues'], static fn (array $i): bool => $i[0] === 'fail'))));
	}

	public function testAFileThatMeetsTheStandardPasses(): void {
		$rResult = $this->scan(self::make('good.mp4'), self::spec(), true);

		$this->assertSame('pass', $rResult['status'], json_encode($rResult['issues']));
		$this->assertEqualsWithDelta(3.0, $rResult['facts']['duration'], 0.05);
		$this->assertSame([25 => 2], $rResult['facts']['gops']);
		$this->assertSame([], $rResult['facts']['decode_errors']);
	}

	public function testEachStandardFieldIsEnforced(): void {
		$rResult = $this->scan(self::make('good.mp4'), self::spec(['width' => 1920, 'height' => 1080, 'fps' => '30000/1001', 'audio_codec' => 'mp2', 'audio_rate' => 44100, 'audio_channels' => 1, 'video_profile' => 'Main']));

		$this->assertSame('fail', $rResult['status']);
		$this->assertEqualsCanonicalizing(['resolution', 'fps', 'audio_codec', 'audio_rate', 'audio_channels', 'video_profile', 'cfr'], self::failed($rResult));
	}

	public function testWrongKeyframeIntervalFails(): void {
		$rResult = $this->scan(self::make('gop.mp4', '', '', '-g 37 -keyint_min 37'), self::spec());

		$this->assertSame(['gop'], self::failed($rResult));
	}

	public function testVariableFrameRateFails(): void {
		// Frames after the 30th are pushed 0.5 s later: a hole in the video timeline.
		$rPath = self::make('vfr.mkv', '', '', '-vf \'setpts=if(gt(N\,30)\,PTS+0.5/TB\,PTS)\' -fps_mode vfr');
		$rResult = $this->scan($rPath, self::spec(['gop' => 0, 'av_end_ms' => 1000]));

		$this->assertContains('cfr', self::failed($rResult));
		$rCfr = array_values(array_filter($rResult['issues'], static fn (array $i): bool => $i[1] === 'cfr'))[0][2];
		$this->assertStringContainsString('00:00:01', $rCfr);
	}

	public function testLateAndShortAudioFail(): void {
		$rLate = $this->scan(self::make('late.mp4', '', '-itsoffset 0.5'), self::spec());
		$this->assertContains('av_start', self::failed($rLate));

		$rShort = $this->scan(self::make('short.mp4', '', '', '-af atrim=0:2'), self::spec());
		$this->assertContains('av_end', self::failed($rShort));
		$this->assertStringContainsString('before the video', implode(' ', array_column($rShort['issues'], 2)));
	}

	public function testAudioStreamCountIsEnforced(): void {
		$rPath = self::$rDir . '/two-audio.mp4';
		shell_exec(escapeshellarg(self::$rFfmpeg) . ' -v error -y -i ' . escapeshellarg(self::make('good.mp4')) . ' -map 0:v -map 0:a -map 0:a -c copy ' . escapeshellarg($rPath));

		$this->assertSame(['streams'], self::failed($this->scan($rPath, self::spec())));
	}

	public function testDeepDecodeFindsDamageTheMetadataDoesNot(): void {
		$rPath = self::$rDir . '/damaged.ts';
		copy(self::make('good.ts'), $rPath);
		$rBytes = file_get_contents($rPath);
		// Overwrite payload bytes (not the 0x47 sync bytes) in a run of TS packets mid-file.
		for ($i = 188 * 200; $i < 188 * 260; $i += 188) {
			$rBytes = substr_replace($rBytes, str_repeat("\xAA", 150), $i + 30, 150);
		}
		file_put_contents($rPath, $rBytes);

		$rShallow = $this->scan($rPath, self::spec(['av_end_ms' => 1000, 'av_start_ms' => 1000]));
		$rDeep = $this->scan($rPath, self::spec(['av_end_ms' => 1000, 'av_start_ms' => 1000]), true);

		$this->assertNotContains('decode', self::failed($rShallow));
		$this->assertContains('decode', self::failed($rDeep));
	}

	/**
	 * Parts joined by stream copy (the concat demuxer, -c copy) carry small
	 * seams: a short GOP at each join and a timestamp step or two. A strict
	 * standard fails them; one with GOP "at most" and a glitch tolerance passes
	 * them with warnings — and still fails a file whose seam is too big.
	 */
	public function testJoinedPartsPassATolerantStandardWithWarnings(): void {
		$rList = self::$rDir . '/join.txt';
		$rParts = [];
		foreach (['p1.mp4' => '2.1', 'p2.mp4' => '1.7', 'p3.mp4' => '2.3'] as $rName => $rLength) {
			$rPath = self::$rDir . '/' . $rName;
			shell_exec(escapeshellarg(self::$rFfmpeg) . ' -v error -y -f lavfi -i testsrc2=size=320x180:rate=25 -f lavfi -i sine=sample_rate=48000 -t ' . $rLength
				. ' -map 0:v -map 1:a -c:v libx264 -preset ultrafast -pix_fmt yuv420p -g 25 -keyint_min 25 -sc_threshold 0 -c:a aac -ac 2 -ar 48000 ' . escapeshellarg($rPath));
			$rParts[] = "file '" . $rPath . "'";
		}
		file_put_contents($rList, implode("\n", $rParts) . "\n");
		$rJoined = self::$rDir . '/joined.mkv';
		shell_exec(escapeshellarg(self::$rFfmpeg) . ' -v error -y -f concat -safe 0 -i ' . escapeshellarg($rList) . ' -c copy ' . escapeshellarg($rJoined));

		$rStrict = $this->scan($rJoined, self::spec(['av_end_ms' => 200]));
		$this->assertContains('gop', self::failed($rStrict));

		$rTolerant = $this->scan($rJoined, self::spec(['av_end_ms' => 200, 'gop_mode' => 'max', 'max_glitches' => 10, 'max_glitch_ms' => 150]));
		$this->assertSame([], self::failed($rTolerant), json_encode($rTolerant['issues']));
		$this->assertSame('warn', $rTolerant['status']);
		$this->assertContains('gop', array_column($rTolerant['issues'], 1));

		$rTooTight = $this->scan($rJoined, self::spec(['av_end_ms' => 200, 'gop_mode' => 'max', 'max_glitches' => 10, 'max_glitch_ms' => 0]));
		$rGlitchy = array_intersect(['cfr', 'timestamps'], self::failed($rTooTight));
		$rClean = !in_array('timestamps', array_column($rTolerant['issues'], 1), true);
		$this->assertTrue($rClean || $rGlitchy !== [], 'glitches above the size limit must fail');
	}

	public function testAMissingFileIsAnError(): void {
		$rResult = $this->scan(self::$rDir . '/nope.mp4', self::spec());

		$this->assertSame('error', $rResult['status']);
		$this->assertStringContainsString('could not be opened', $rResult['issues'][0][2]);
	}

	public function testAnalyzerIgnoresDiscardedPrimingAndCountsAudioHoles(): void {
		$rA = new PacketAnalyzer(0, 1);
		$rA->feedCsv('0,0.000000,-0.080000,0.040000,K__');
		$rA->feedCsv('1,-0.021333,-0.021333,0.021333,KD_,');  // priming: not played
		$rA->feedCsv('1,0.000000,0.000000,0.021333,K__');
		$rA->feedCsv('1,0.021333,0.021333,0.021333,K__');
		$rA->feedCsv('1,0.142666,0.142666,0.021333,K__');     // 100 ms hole
		$rA->feedCsv('0,0.040000,-0.040000,0.040000,___');
		$rA->feedCsv('garbage');
		$rOut = $rA->finish(0.04);

		$this->assertSame(0.0, $rOut['audio']['start']);
		$this->assertSame(1, $rOut['audio']['gaps']);
		$this->assertEqualsWithDelta(0.1, $rOut['audio']['max_gap'], 0.001);
		$this->assertSame(2, $rOut['video']['frames']);
		$this->assertSame(0, $rOut['video']['irregular']);
		$this->assertTrue($rOut['video']['first_key']);
	}

	public function testStandardValidation(): void {
		[, $rErrors] = AuditStandard::normalize(['video_codec' => 'h264; rm -rf /', 'fps' => 'abc', 'scan' => 'x', 'width' => 99999] + AuditStandard::DEFAULTS);

		$this->assertCount(4, $rErrors);
		$this->assertSame('h264 1920x1080 25p yuv420p SAR 1:1 GOP 50 · aac 48k 2ch', AuditStandard::describe(AuditStandard::normalize(AuditStandard::DEFAULTS)[0]));
		$this->assertSame('h264 1280x720 25p yuv420p SAR 1:1 GOP ≤100 · aac 48k 2ch · tolerates 10 timestamp glitch(es) ≤ 150 ms',
			AuditStandard::describe(AuditStandard::normalize(['width' => 1280, 'height' => 720, 'gop' => 100, 'gop_mode' => 'max', 'max_glitches' => 10, 'max_glitch_ms' => 150] + AuditStandard::DEFAULTS)[0]));
		$this->assertEqualsWithDelta(29.97, AuditStandard::rate('30000/1001'), 0.001);
	}
}
