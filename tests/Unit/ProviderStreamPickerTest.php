<?php

use PHPUnit\Framework\TestCase;
use XcVm\Public\Controllers\Admin\TableController;
use XcVm\Tests\Support\InstallSchema;

/**
 * The Providers list of the stream and movie forms: a search by a stream's
 * name finds the providers' channels of the same name whatever their quality
 * tag or country prefix, closest first, and each row says what the form
 * shows (category, channel id, connections, expiry).
 *
 * The handler ends the request itself, so it runs in a child PHP over the
 * test's schema, as in AuditAdminMisc2TablesTest.
 */
final class ProviderStreamPickerTest extends TestCase {
	private const CHILD = <<<'PHP'
<?php
final class XC_VM {
	public static function db_connect(bool $rMigrate = false) {
		return TestDb::connect($GLOBALS['rIn']['schema']);
	}
}

require %BOOTSTRAP%;

$rIn = json_decode($argv[1], true);

\XcVm\Core\Config\SettingsManager::set(['redis_handler' => 0]);
\XcVm\Infrastructure\Database\DatabaseFactory::connect();
$db = \XcVm\Infrastructure\Database\DatabaseFactory::get();
$rSettings = ['date_format' => 'Y-m-d'];
$rUserInfo = ['id' => 9, 'member_group_id' => 1];
$rPermissions = ['is_admin' => 1, 'advanced' => []];
\XcVm\Core\Http\RequestManager::set($rIn['request']);

$rHandler = new ReflectionMethod(\XcVm\Public\Controllers\Admin\TableController::class, 'handleProviderStreams');
$rHandler->invoke(new \XcVm\Public\Controllers\Admin\TableController(), ['draw' => 1, 'recordsTotal' => 0, 'recordsFiltered' => 0, 'data' => []], 0, 100, false);
PHP;

	private TestDb $rDb;
	private string $rDir;

	protected function setUp(): void {
		$this->rDb = new TestDb();
		foreach (['providers', 'providers_streams'] as $rTable) {
			$this->rDb->exec(InstallSchema::table($rTable));
		}
		$this->rDb->query("INSERT INTO `providers` (`id`, `name`, `ip`, `port`, `username`, `password`, `enabled`, `status`, `legacy`, `data`) VALUES (3, 'Zeta', '192.0.2.50', 80, 'u', 'p', 1, 1, 1, ?), (4, 'Alpha', '192.0.2.60', 8080, 'v', 'w', 1, 1, 1, ?)",
			json_encode(['max_connections' => '2', 'active_connections' => '2', 'exp_date' => '1900000000']), json_encode(['max_connections' => '10', 'active_connections' => '1', 'exp_date' => null]));
		foreach ([[3, 70, 'AL| RTSH 1 FHD', '["Albania"]'], [3, 71, 'RTSH 12', '["Albania"]'], [4, 5, 'RTSH 1', '["Shqip"]'], [4, 6, 'Top Channel HD', '[]'], [4, 7, 'RTSH SPORT', '[]']] as [$rProvider, $rID, $rName, $rCats]) {
			$this->rDb->query("INSERT INTO `providers_streams` (`provider_id`, `stream_id`, `stream_display_name`, `category_array`, `type`) VALUES (?, ?, ?, ?, 'live')", $rProvider, $rID, $rName, $rCats);
		}
		$this->rDir = sys_get_temp_dir() . '/xcvm-provpick-' . getmypid() . '-' . bin2hex(random_bytes(4)) . '/';
		mkdir($this->rDir, 0777, true);
		file_put_contents($this->rDir . 'child.php', str_replace('%BOOTSTRAP%', var_export(dirname(__DIR__) . '/bootstrap.php', true), self::CHILD));
	}

	protected function tearDown(): void {
		exec('rm -rf ' . escapeshellarg($this->rDir));
	}

	/** @return list<array<string, mixed>> */
	private function rows(array $rRequest): array {
		$rIn = ['schema' => $this->rDb->schema(), 'request' => ['draw' => 1, 'type' => 'live'] + $rRequest];
		exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($this->rDir . 'child.php') . ' ' . escapeshellarg(json_encode($rIn)) . ' 2>&1', $rOut);
		$rJson = json_decode(implode("\n", $rOut), true);
		$this->assertIsArray($rJson, implode("\n", $rOut));
		return $rJson['data'] ?? [];
	}

	public function testSearchWordsLeaveOutQualityTagsAndCountryPrefixes(): void {
		$this->assertSame(['rtsh', '1'], TableController::providerSearchWords('RTSH 1 HD'));
		$this->assertSame(['rtsh', '1'], TableController::providerSearchWords('AL: RTSH 1 FHD'));
		$this->assertSame(['top', 'channel'], TableController::providerSearchWords('|ALB| Top Channel HD'));
		$this->assertSame(['sky', 'sports', 'main', 'event'], TableController::providerSearchWords('[UK] Sky Sports Main Event 4K'));
		$this->assertSame([], TableController::providerSearchWords('HD'));
	}

	public function testTheSameChannelUnderAnyTagIsFoundClosestFirst(): void {
		$rRows = $this->rows(['search' => ['value' => 'RTSH 1 HD'], 'columns' => [['data' => 'stream_icon'], ['data' => 'name'], ['data' => 'provider_name'], ['data' => null]]]);
		// Whole words first ("RTSH 12" holds "1" only inside "12"), shortest first among them.
		$this->assertSame(['RTSH 1', 'AL| RTSH 1 FHD', 'RTSH 12'], array_column($rRows, 'name'));
		$this->assertSame('Shqip', $rRows[0]['category']);
		$this->assertSame([5, 'Alpha', '1', '10', ''], [$rRows[0]['provider_stream_id'], $rRows[0]['provider_name'], $rRows[0]['provider_active'], $rRows[0]['provider_max'], $rRows[0]['provider_expires_date']]);
		$this->assertSame(['Zeta', '2', '2', date('Y-m-d', 1900000000)], [$rRows[1]['provider_name'], $rRows[1]['provider_active'], $rRows[1]['provider_max'], $rRows[1]['provider_expires_date']]);
		$this->assertSame('http://192.0.2.50:80/live/u/p/70.ts', $rRows[1]['stream_url']);

		$this->assertSame(['Top Channel HD'], array_column($this->rows(['search' => ['value' => '|AL| TOP CHANNEL']]), 'name'));
		$this->assertSame(['RTSH 12'], array_column($this->rows(['search' => ['value' => '71']]), 'name'));            // a channel id
		$this->assertEqualsCanonicalizing(['AL| RTSH 1 FHD', 'RTSH 12'], array_column($this->rows(['search' => ['value' => 'Zeta']]), 'name'));   // a provider name
	}

	public function testAColumnSortsByWhatItShowsInEitherForm(): void {
		$rStreamForm = [['data' => 'stream_icon'], ['data' => 'name'], ['data' => 'provider_name'], ['data' => null]];
		$this->assertSame(['AL| RTSH 1 FHD', 'RTSH 1', 'RTSH 12', 'RTSH SPORT', 'Top Channel HD'], array_column($this->rows(['columns' => $rStreamForm, 'order' => [['column' => 1, 'dir' => 'asc']]]), 'name'));
		$this->assertSame(['Alpha', 'Alpha', 'Alpha', 'Zeta', 'Zeta'], array_column($this->rows(['columns' => $rStreamForm, 'order' => [['column' => 2, 'dir' => 'asc']]]), 'provider_name'));
		$rMovieForm = [['data' => 'name'], ['data' => 'provider_name'], ['data' => null]];
		$this->assertSame('Zeta', $this->rows(['columns' => $rMovieForm, 'order' => [['column' => 1, 'dir' => 'desc']]])[0]['provider_name']);
		$this->assertSame('AL| RTSH 1 FHD', $this->rows(['columns' => $rStreamForm, 'order' => [['column' => 0, 'dir' => 'asc']]])[0]['name']);   // the icon column does not sort
	}
}
