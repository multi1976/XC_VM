<?php

use PHPUnit\Framework\TestCase;
use XcVm\Cli\CronJobs\ProvidersCronJob;
use XcVm\Core\Config\ConstantsInitializer;
use XcVm\Domain\Stream\ProviderService;
use XcVm\Infrastructure\Database\DatabaseFactory;
use XcVm\Tests\Support\InstallSchema;

/**
 * A Stream Provider syncs only the lists it is set to (live, VOD, series),
 * oldest sync first, and a list that could not be fetched is not wiped.
 */
final class ProviderSyncScopeTest extends TestCase {
	private TestDb $rDb;
	private string $rDir;
	private int $rPort;
	/** @var resource|null */
	private $rServer = null;
	/** @var array<string, mixed> */
	private array $rBefore = [];

	protected function setUp(): void {
		foreach (ConstantsInitializer::statuses() as $rName => $rValue) {
			defined($rName) || define($rName, $rValue);
		}
		$this->rDb = new TestDb();
		foreach (['providers', 'providers_streams', 'streams'] as $rTable) {
			$this->rDb->exec(InstallSchema::table($rTable));
		}
		DatabaseFactory::set($this->rDb);
		$this->rBefore = ['db' => $GLOBALS['db'] ?? null, 'rUserInfo' => $GLOBALS['rUserInfo'] ?? null, 'rPermissions' => $GLOBALS['rPermissions'] ?? null];
		$GLOBALS['db'] = $this->rDb;
		$GLOBALS['rUserInfo'] = ['id' => 1, 'member_group_id' => 1];
		$GLOBALS['rPermissions'] = ['is_admin' => 1, 'advanced' => []];
		foreach ([ProviderService::class] as $rClass) {
			if (method_exists($rClass, 'setDb')) {
				$rClass::setDb($this->rDb);
			}
		}

		$this->rDir = sys_get_temp_dir() . '/provsync-' . getmypid();
		@mkdir($this->rDir);
		$this->rPort = 23000 + getmypid() % 1000;
		// A provider: player_api.php with the usual actions. Every request is logged;
		// `fail` lists actions to answer with 500, `down` makes it answer nothing useful.
		file_put_contents($this->rDir . '/router.php', '<?php $d = __DIR__; parse_str((string) parse_url($_SERVER["REQUEST_URI"], PHP_URL_QUERY), $q); $a = $q["action"] ?? "info";'
			. ' file_put_contents("$d/log", $a . "\n", FILE_APPEND);'
			. ' if (is_file("$d/down")) { http_response_code(500); return true; }'
			. ' if (in_array($a, explode(",", (string) @file_get_contents("$d/fail")), true)) { http_response_code(500); return true; }'
			. ' header("Content-Type: application/json");'
			. ' echo json_encode(match ($a) {'
			. '   "info" => ["user_info" => ["auth" => 1, "status" => "Active", "max_connections" => "5", "active_cons" => "1", "exp_date" => "1900000000"]],'
			. '   "get_live_categories" => [["category_id" => "1", "category_name" => "Albania"]],'
			. '   "get_live_streams" => [["stream_id" => 70, "name" => "RTSH 1", "category_id" => "1"], ["stream_id" => 71, "name" => "Top Channel", "category_id" => "1"]],'
			. '   "get_vod_categories" => [["category_id" => "9", "category_name" => "Films"]],'
			. '   "get_vod_streams" => [["stream_id" => 5000, "name" => "A film", "category_id" => "9", "container_extension" => "mp4"]],'
			. '   "get_series" => [["series_id" => 1]],'
			. '   default => [] }); return true;');
		$this->rServer = proc_open(['php', '-S', '127.0.0.1:' . $this->rPort, $this->rDir . '/router.php'], [0 => ['file', '/dev/null', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $rPipes);
		usleep(400000);
	}

	protected function tearDown(): void {
		if ($this->rServer !== null) {
			proc_terminate($this->rServer);
			proc_close($this->rServer);
		}
		array_map('unlink', glob($this->rDir . '/*') ?: []);
		@rmdir($this->rDir);
		foreach ($this->rBefore as $rKey => $rValue) {
			$GLOBALS[$rKey] = $rValue;
		}
		DatabaseFactory::reset();
	}

	private function provider(int $rID): array {
		$this->rDb->query('SELECT * FROM `providers` WHERE `id` = ?', $rID);
		return $this->rDb->get_raw_row();
	}

	private function sync(int $rID): array {
		@unlink($this->rDir . '/log');
		$rJob = new ProvidersCronJob();
		$rMethod = new ReflectionMethod($rJob, 'syncProvider');
		$rMethod->invoke($rJob, $this->provider($rID), []);
		return array_values(array_filter(explode("\n", (string) @file_get_contents($this->rDir . '/log'))));
	}

	/** @return array<string, list<int>> type => stream ids */
	private function stored(int $rID): array {
		$rOut = [];
		$this->rDb->query('SELECT `type`, `stream_id` FROM `providers_streams` WHERE `provider_id` = ? ORDER BY `stream_id`', $rID);
		foreach ($this->rDb->get_rows() as $rRow) {
			$rOut[$rRow['type']][] = (int) $rRow['stream_id'];
		}
		return $rOut;
	}

	public function testTheFormChoosesTheListsAndOtherCallersKeepThem(): void {
		$rBase = ['name' => 'P', 'ip' => '127.0.0.1', 'port' => (string) $this->rPort, 'username' => 'u', 'password' => 'p', 'legacy' => 1, 'enabled' => 1];
		$rForm = ProviderService::process($rBase + ['sync_form' => 1, 'sync_live' => 1]);
		$rID = (int) $rForm['data']['insert_id'];
		$this->assertSame(['1', '0', '0'], array_map('strval', array_values(array_intersect_key($this->provider($rID), array_flip(['sync_live', 'sync_vod', 'sync_series'])))));

		$rApi = ProviderService::process(['username' => 'u2'] + $rBase);                 // the admin API, no switches: as before, all three
		$this->assertSame(['1', '1', '1'], array_map('strval', array_values(array_intersect_key($this->provider((int) $rApi['data']['insert_id']), array_flip(['sync_live', 'sync_vod', 'sync_series'])))));

		ProviderService::process(['edit' => $rID, 'name' => 'Renamed'] + $rBase);         // an edit without them keeps the stored choice
		$this->assertSame(['Renamed', '1', '0', '0'], array_map('strval', array_values(array_intersect_key($this->provider($rID), array_flip(['name', 'sync_live', 'sync_vod', 'sync_series'])))));
	}

	public function testOnlyTheChosenListsAreFetchedAndATurnedOffListIsCleared(): void {
		$this->rDb->query("INSERT INTO `providers` (`id`, `name`, `ip`, `port`, `username`, `password`, `legacy`, `enabled`, `sync_live`, `sync_vod`, `sync_series`) VALUES (1, 'P', '127.0.0.1', ?, 'u', 'p', 1, 1, 1, 1, 1)", $this->rPort);
		$rAll = $this->sync(1);
		$this->assertSame(['info', 'get_live_categories', 'get_vod_categories', 'get_live_streams', 'get_vod_streams', 'get_series'], $rAll);
		$this->assertSame(['live' => [70, 71], 'movie' => [5000]], $this->stored(1));
		$rData = json_decode($this->provider(1)['data'], true);
		$this->assertSame([2, 1, 1, '5', 1], [$rData['streams'], $rData['movies'], $rData['series'], $rData['max_connections'], (int) $this->provider(1)['status']]);

		$this->rDb->query('UPDATE `providers` SET `sync_vod` = 0, `sync_series` = 0 WHERE `id` = 1');
		$this->assertSame(['info', 'get_live_categories', 'get_live_streams'], $this->sync(1));     // no VOD, no series requests
		$this->assertSame(['live' => [70, 71]], $this->stored(1));                                  // the VOD entries are gone
		$rData = json_decode($this->provider(1)['data'], true);
		$this->assertSame([2, 0, 0], [$rData['streams'], $rData['movies'], $rData['series']]);
	}

	public function testAFailedFetchNeverWipesTheStoredList(): void {
		$this->rDb->query("INSERT INTO `providers` (`id`, `name`, `ip`, `port`, `username`, `password`, `legacy`, `enabled`, `sync_live`, `sync_vod`, `sync_series`) VALUES (1, 'P', '127.0.0.1', ?, 'u', 'p', 1, 1, 1, 0, 0)", $this->rPort);
		$this->sync(1);
		$this->assertSame(['live' => [70, 71]], $this->stored(1));

		file_put_contents($this->rDir . '/fail', 'get_live_streams');
		$this->sync(1);
		$this->assertSame(['live' => [70, 71]], $this->stored(1));
		$this->assertSame(2, json_decode($this->provider(1)['data'], true)['streams']);           // the count of the last good fetch

		touch($this->rDir . '/down');
		$this->assertSame(['info'], $this->sync(1));                                                // unreachable: no list is asked for
		$this->assertSame(['live' => [70, 71]], $this->stored(1));
		$rRow = $this->provider(1);
		$this->assertSame([0, -1, 2], [(int) $rRow['status'], json_decode($rRow['data'], true)['exp_date'], json_decode($rRow['data'], true)['streams']]);
	}

	public function testTheLeastRecentlySyncedProviderGoesFirst(): void {
		$this->rDb->query("INSERT INTO `providers` (`id`, `name`, `ip`, `port`, `username`, `password`, `legacy`, `enabled`, `last_changed`) VALUES (1, 'Recent', '127.0.0.1', ?, 'a', 'p', 1, 1, 2000), (2, 'Old', '127.0.0.1', ?, 'b', 'p', 1, 1, 1000), (3, 'Never', '127.0.0.1', ?, 'c', 'p', 1, 1, NULL), (4, 'Off', '127.0.0.1', ?, 'd', 'p', 1, 0, NULL)", $this->rPort, $this->rPort, $this->rPort, $this->rPort);
		$this->rDb->query('SELECT `name` FROM `providers` WHERE `enabled` = 1 ORDER BY `last_changed` IS NOT NULL, `last_changed` ASC, `id` ASC;');
		$this->assertSame(['Never', 'Old', 'Recent'], array_column($this->rDb->get_rows(), 'name'));
		$rSource = (string) file_get_contents(MAIN_HOME . 'Cli/CronJobs/ProvidersCronJob.php');
		$this->assertStringContainsString('ORDER BY `last_changed` IS NOT NULL, `last_changed` ASC, `id` ASC', $rSource);
	}
}
