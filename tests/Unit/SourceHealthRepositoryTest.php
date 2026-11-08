<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use XcVm\Infrastructure\Database\DatabaseFactory;
use XcVm\Module\SourceHealth\SourceHealthCron;
use XcVm\Module\SourceHealth\SourceHealthRepository;
use XcVm\Tests\Support\InstallSchema;

/**
 * The source-health module against a real database: the core tables it reads
 * (streams_logs, streams_servers, from the install schema) and its own table.
 */
final class SourceHealthRepositoryTest extends TestCase {
	private TestDb $rDb;
	private string $rDir;

	protected function setUp(): void {
		$this->rDir = glob(dirname(__DIR__, 2) . '/src/Modules/source-health_*')[0] ?? '';
		if ($this->rDir === '') {
			self::markTestSkipped('The source-health module is not installed in this tree.');
		}
		foreach (['SourceFingerprint', 'SourceHealthRepository', 'SourceHealthCron'] as $rClass) {
			require_once $this->rDir . '/' . $rClass . '.php';
		}
		$this->rDb = new TestDb();
		$this->rDb->exec(InstallSchema::table('streams_logs'));
		$this->rDb->exec(InstallSchema::table('streams_servers'));
		$this->rDb->exec((string) file_get_contents($this->rDir . '/database.sql'));
		DatabaseFactory::set($this->rDb);
	}

	protected function tearDown(): void {
		DatabaseFactory::reset();
	}

	private function log(int $rServer, string $rAction, string $rSource, int $rDate, int $rStream = 7): void {
		$this->rDb->query('INSERT INTO `streams_logs` (`stream_id`, `server_id`, `action`, `source`, `date`) VALUES (?, ?, ?, ?, ?)', $rStream, $rServer, $rAction, $rSource, $rDate);
	}

	public function testEventsCarryTheRunOnAirWhenTheWindowOpened(): void {
		$this->log(1, 'STREAM_START', 'a', 100);   // seed for server 1
		$this->log(2, 'STREAM_START', 'b', 100);
		$this->log(2, 'STREAM_STOP', '', 200);     // server 2 was stopped before the window: no seed
		$this->log(1, 'STREAM_FAILED', 'a', 1500);
		$this->log(1, 'STREAM_START', 'x', 1500, 8); // another stream

		$rEvents = SourceHealthRepository::events(7, 1000);

		$this->assertSame([[1, 'STREAM_START', 100], [1, 'STREAM_FAILED', 1500]], array_map(static fn (array $e): array => [(int) $e['server_id'], $e['action'], (int) $e['date']], $rEvents));
	}

	public function testCronRecordsTheProfileOfEachSourceOnAirAndTheRunningMap(): void {
		$rInfo = json_encode(['codecs' => [
			'video' => ['codec_name' => 'h264', 'width' => 1280, 'height' => 720, 'avg_frame_rate' => '50/1', 'field_order' => 'progressive'],
			'audio' => ['codec_name' => 'aac', 'sample_rate' => '48000', 'channels' => 2],
		]]);
		$rInsert = 'INSERT INTO `streams_servers` (`stream_id`, `server_id`, `parent_id`, `pid`, `stream_status`, `current_source`, `stream_info`, `bitrate`) VALUES (?, ?, ?, ?, ?, ?, ?, ?)';
		$this->rDb->query($rInsert, 7, 1, null, 1234, 0, 'http://a/1.ts', $rInfo, 3500);
		$this->rDb->query($rInsert, 7, 2, 1, 999, 0, 'http://relay/7.ts', $rInfo, 3500); // child of server 1: skipped
		$this->rDb->query($rInsert, 7, 3, null, null, 1, 'http://b/1.ts', $rInfo, null); // not running: skipped

		$this->assertSame(1, SourceHealthCron::run(5000));
		$this->assertSame(1, SourceHealthCron::run(6000)); // upsert, not a second row

		$rProfiles = SourceHealthRepository::profiles(7);
		$this->assertSame([md5('http://a/1.ts')], array_keys($rProfiles));
		$rProfile = $rProfiles[md5('http://a/1.ts')];
		$this->assertSame('h264 1280x720 50p · aac 48k 2ch', $rProfile['label']);
		$this->assertSame(5000, (int) $rProfile['first_seen']);
		$this->assertSame(6000, (int) $rProfile['last_seen']);
		$this->assertSame(3500, (int) $rProfile['bitrate']);

		$rRunning = SourceHealthRepository::running(7);
		ksort($rRunning);
		$this->assertSame([1 => 'http://a/1.ts', 2 => 'http://relay/7.ts'], $rRunning);

		SourceHealthRepository::deleteForStreams([7]);
		$this->assertSame([], SourceHealthRepository::profiles(7));
	}

	public function testStaleProfilesArePruned(): void {
		$rFp = ['key' => 'k', 'label' => 'l', 'video_codec' => 'h264', 'video_profile' => '', 'width' => 1, 'height' => 1, 'fps' => 25.0, 'scan' => 'p', 'audio_codec' => '', 'audio_rate' => 0, 'audio_channels' => 0];
		SourceHealthRepository::saveProfile(7, 1, 'old', $rFp, null, 100);
		SourceHealthRepository::saveProfile(7, 1, 'new', $rFp, null, 100 + SourceHealthRepository::PROFILE_TTL_SEC);

		SourceHealthRepository::pruneProfiles(101 + SourceHealthRepository::PROFILE_TTL_SEC);

		$this->assertSame([md5('new')], array_keys(SourceHealthRepository::profiles(7)));
	}
}
