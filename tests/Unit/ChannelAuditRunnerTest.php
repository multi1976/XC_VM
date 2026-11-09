<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use XcVm\Infrastructure\Database\DatabaseFactory;
use XcVm\Module\ChannelAudit\AuditRepository;
use XcVm\Module\ChannelAudit\AuditRunner;
use XcVm\Module\ChannelAudit\AuditStandard;
use XcVm\Module\ChannelAudit\FileScanner;
use XcVm\Tests\Support\InstallSchema;

/**
 * A channel audit end to end against a real database and real files: the job
 * is created as the page creates it, run as the background command runs it,
 * and read back as the report shows it.
 */
final class ChannelAuditRunnerTest extends TestCase {
	private TestDb $rDb;
	private string $rDir = '';
	private string $rProbe = '';
	private string $rFfmpeg = '';

	protected function setUp(): void {
		$rModule = glob(dirname(__DIR__, 2) . '/src/Modules/channel-audit_*')[0] ?? '';
		if ($rModule === '') {
			$this->markTestSkipped('The channel-audit module is not installed in this tree.');
		}
		foreach (['PacketAnalyzer', 'AuditStandard', 'FileScanner', 'AuditRepository', 'SourceResolver', 'AuditRunner'] as $rClass) {
			require_once $rModule . '/' . $rClass . '.php';
		}
		$this->rProbe = trim((string) shell_exec('command -v ffprobe'));
		$this->rFfmpeg = trim((string) shell_exec('command -v ffmpeg'));
		if ($this->rProbe === '' || $this->rFfmpeg === '') {
			$this->markTestSkipped('ffmpeg/ffprobe not installed');
		}
		if (!defined('SERVER_ID')) {
			define('SERVER_ID', 1);
		}
		$this->rDb = new TestDb();
		$this->rDb->exec(InstallSchema::table('streams'));
		$rSchema = (string) file_get_contents($rModule . '/database.sql');
		foreach (array_filter(array_map('trim', explode(';', $rSchema))) as $rStatement) {
			$this->rDb->exec($rStatement);
		}
		DatabaseFactory::set($this->rDb);

		$this->rDir = sys_get_temp_dir() . '/channel-audit-run-' . getmypid();
		@mkdir($this->rDir);
		foreach (['a.mp4' => '-g 25 -keyint_min 25', 'b.mp4' => '-g 40 -keyint_min 40'] as $rName => $rGop) {
			shell_exec(escapeshellarg($this->rFfmpeg) . ' -v error -y -f lavfi -i testsrc2=size=320x180:rate=25 -f lavfi -i sine=sample_rate=48000 -t 2 -map 0:v -map 1:a'
				. ' -c:v libx264 -preset ultrafast -pix_fmt yuv420p ' . $rGop . ' -sc_threshold 0 -c:a aac -ac 2 -ar 48000 ' . escapeshellarg($this->rDir . '/' . $rName));
		}
	}

	protected function tearDown(): void {
		DatabaseFactory::reset();
		if ($this->rDir !== '' && is_dir($this->rDir)) {
			array_map('unlink', glob($this->rDir . '/*') ?: []);
			rmdir($this->rDir);
		}
	}

	public function testTheSeededStandardsAreInstalledOnce(): void {
		$rSchema = (string) file_get_contents(glob(dirname(__DIR__, 2) . '/src/Modules/channel-audit_*')[0] . '/database.sql');
		foreach (array_filter(array_map('trim', explode(';', $rSchema))) as $rStatement) {
			$this->rDb->exec($rStatement); // a second install over the first
		}
		$rStandards = array_column(AuditRepository::standards(), 'spec', 'name');

		$this->assertCount(2, $rStandards);
		$this->assertSame(1920, $rStandards['Full HD 25p (H.264 / AAC stereo)']['width']);
		$rChunks = $rStandards['HD Ready 25p, joined chunks (H.264 / AAC stereo)'];
		$this->assertSame([1280, 720, 100, 'max', 10, 150], [$rChunks['width'], $rChunks['height'], $rChunks['gop'], $rChunks['gop_mode'], $rChunks['max_glitches'], $rChunks['max_glitch_ms']]);
		$this->assertSame([], AuditStandard::normalize($rChunks)[1]);
	}

	public function testAChannelAuditRunsEveryItemAndCountsTheResults(): void {
		$this->rDb->query(
			"INSERT INTO `streams` (`id`, `type`, `stream_display_name`, `stream_source`) VALUES (7, 3, 'Movies 24/7', ?)",
			json_encode([$this->rDir . '/a.mp4', $this->rDir . '/b.mp4', $this->rDir . '/missing.mp4'])
		);
		[$rSpec] = AuditStandard::normalize(['width' => 320, 'height' => 180, 'gop' => 25] + AuditStandard::DEFAULTS);
		$rStandardID = AuditRepository::saveStandard(0, 'Test 320p', $rSpec);
		$rStandard = AuditRepository::standard($rStandardID);
		$rJobID = AuditRepository::createJob(['kind' => 'channel', 'stream_id' => 7, 'label' => 'Movies 24/7', 'standard_name' => $rStandard['name'], 'spec' => json_encode($rStandard['spec']), 'deep' => 1, 'total' => 3]);

		$this->assertSame(0, AuditRunner::run($rJobID, new FileScanner($this->rProbe, $this->rFfmpeg)));

		$rJob = AuditRepository::job($rJobID);
		$this->assertSame('done', $rJob['status']);
		$this->assertSame([3, 3, 1, 0, 2], [(int) $rJob['total'], (int) $rJob['done'], (int) $rJob['passed'], (int) $rJob['warned'], (int) $rJob['failed']]);

		$rItems = AuditRepository::items($rJobID);
		$this->assertSame(['pass', 'fail', 'error'], array_column($rItems, 'status'));
		$this->assertSame(['a.mp4', 'b.mp4', 'missing.mp4'], array_column($rItems, 'name'));
		$rReport = json_decode((string) $rItems[1]['report'], true);
		$this->assertSame('gop', $rReport['issues'][0][1]);
		$this->assertStringContainsString('file not found', json_decode((string) $rItems[2]['report'], true)['issues'][0][2]);

		// A finished job does not run again.
		$this->assertSame(1, AuditRunner::run($rJobID, new FileScanner($this->rProbe, $this->rFfmpeg)));
	}

	public function testACancelledJobDoesNotRunAndAMissingChannelFailsTheJob(): void {
		$rSpec = json_encode(AuditStandard::DEFAULTS);
		$rCancelled = AuditRepository::createJob(['kind' => 'channel', 'stream_id' => 7, 'label' => 'x', 'standard_name' => 's', 'spec' => $rSpec]);
		AuditRepository::updateJob($rCancelled, ['status' => 'cancelled']);
		$this->assertSame(1, AuditRunner::run($rCancelled, new FileScanner($this->rProbe, $this->rFfmpeg)));
		$this->assertSame([], AuditRepository::items($rCancelled));

		$rMissing = AuditRepository::createJob(['kind' => 'channel', 'stream_id' => 999, 'label' => 'x', 'standard_name' => 's', 'spec' => $rSpec]);
		AuditRunner::run($rMissing, new FileScanner($this->rProbe, $this->rFfmpeg));
		$rJob = AuditRepository::job($rMissing);
		$this->assertSame(['failed', 'the created channel no longer exists'], [$rJob['status'], $rJob['error']]);

		AuditRepository::deleteJob($rMissing);
		$this->assertNull(AuditRepository::job($rMissing));
	}
}
