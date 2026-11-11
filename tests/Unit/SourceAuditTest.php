<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use XcVm\Infrastructure\Database\DatabaseFactory;
use XcVm\Module\SourceHealth\ProviderAccount;
use XcVm\Module\SourceHealth\ProviderImport;
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
		foreach (['SourceFingerprint', 'SourceHealthCalculator', 'SourceHealthRepository', 'SourceProber', 'SourceHealthTab', 'ProviderAccount', 'ProviderImport', 'SourceAudit', 'SourceAuditPage'] as $rClass) {
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

	public function testTheProviderSaysWhetherABusyAccountHasAConnectionToSpare(): void {
		$this->assertSame(['key' => 'http://p.example:8080/live/u/p/', 'host' => 'p.example', 'base' => 'http://p.example:8080', 'user' => 'u', 'pass' => 'p'], ProviderAccount::parse('http://p.example:8080/live/u/p/1.ts'));
		$this->assertSame(['u2', 'p@2'], array_values(array_intersect_key((array) ProviderAccount::parse('http://h.example/u2/p%402/77'), ['user' => 1, 'pass' => 1])));
		$this->assertNull(ProviderAccount::parse('http://h.example/stream.m3u8'));
		$this->assertNull(ProviderAccount::parse('rtmp://h.example/live/u/p/1'));

		$rInfo = ProviderAccount::fromApi(['user_info' => ['auth' => 1, 'status' => 'Active', 'max_connections' => '3', 'active_cons' => '1', 'exp_date' => '1900000000']]);
		$this->assertSame(['api' => true, 'auth' => true, 'status' => 'Active', 'max' => 3, 'active' => 1, 'exp' => 1900000000], $rInfo);
		$this->assertFalse(ProviderAccount::fromApi('<html>')['api']);

		$rNow = 1_800_000_000;
		$this->assertTrue(ProviderAccount::admits($rInfo + ['checked_at' => $rNow - 60], $rNow));             // 1 used + the check + 1 spare = 3
		$this->assertFalse(ProviderAccount::admits(['active' => 2] + $rInfo + ['checked_at' => $rNow], $rNow)); // full
		$this->assertFalse(ProviderAccount::admits($rInfo + ['checked_at' => $rNow - 600], $rNow));            // stale word
		$this->assertFalse(ProviderAccount::admits(['max' => 0] + $rInfo + ['checked_at' => $rNow], $rNow));
		$this->assertFalse(ProviderAccount::admits(null, $rNow));

		$this->assertNull(ProviderAccount::deadReason($rInfo, $rNow));
		$this->assertSame('account: expired', ProviderAccount::deadReason(['status' => 'Expired'] + $rInfo, $rNow));
		$this->assertSame('account: invalid login (the provider refused it)', ProviderAccount::deadReason(ProviderAccount::fromApi(['user_info' => ['auth' => 0]]), $rNow));
		$this->assertStringStartsWith('account: expired on ', (string) ProviderAccount::deadReason(['exp' => $rNow - 10] + $rInfo, $rNow));
		$this->assertSame('gone', \XcVm\Module\SourceHealth\SourceHealthCalculator::reasonKind('account: invalid login (the provider refused it)'));

		// The audit: a source on a busy account goes only on fresh word, one per account per run.
		$rSources = self::src('http://p.example/live/u/p/1.ts') + self::src('http://p.example/live/u/p/2.ts') + self::src('http://p.example/live/u/p/3.ts');
		$rOnAir = ['http://p.example/live/u/p/1.ts'];
		$rDue = SourceAudit::due($rSources, [], $rOnAir, $rNow);
		$this->assertSame([true, true], array_column($rDue, 'busy'));
		$this->assertSame(['http://p.example:/live/u/p/'], SourceAudit::accountsToAsk($rDue, [], $rNow));
		$rAccounts = [md5('http://p.example:/live/u/p/') => $rInfo + ['checked_at' => $rNow]];
		$this->assertSame([], SourceAudit::accountsToAsk($rDue, $rAccounts, $rNow));
		$this->assertSame(['http://p.example/live/u/p/2.ts'], array_column(SourceAudit::admit($rDue, $rAccounts, $rNow), 'source'));
		$this->assertSame([], SourceAudit::admit($rDue, [], $rNow));
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
		foreach (['streams', 'streams_servers', 'providers', 'providers_streams'] as $rTable) {
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

			$this->assertSame(1, SourceHealthRepository::requeueAudit([md5($rC), 'not-a-hash']));
			$this->assertSame(0, (int) SourceHealthRepository::auditState()[md5($rC)]['checked_at']);
			SourceHealthRepository::saveAccount(md5('http://q.example:/live/v/w/'), 'q.example', ['api' => true, 'auth' => true, 'status' => 'Active', 'max' => 50, 'active' => 8, 'exp' => null], 1900);
			$this->assertSame(['api' => true, 'auth' => true, 'status' => 'Active', 'max' => 50, 'active' => 8, 'exp' => null, 'checked_at' => 1900, 'host' => 'q.example'], SourceHealthRepository::accounts()[md5('http://q.example:/live/v/w/')]);

			$rHtml = SourceAuditPage::render(['status' => 'problems'], 2000);
			$this->assertStringContainsString('Dead + down: 2', $rHtml);                    // the dead source in two streams
			$this->assertStringContainsString('On air: 1', $rHtml);
			$this->assertStringContainsString('every checked source off', $rHtml);          // dead.example: both its sources off
			$this->assertStringNotContainsString('/u/p/', $rHtml);                          // accounts masked
			$this->assertSame(2, substr_count($rHtml, '>Dead</span>'));
			$this->assertStringContainsString('Recheck all shown (1)', $rHtml);
			$this->assertStringContainsString('queued', $rHtml);
			$rFound = SourceAuditPage::render(['status' => 'all', 'q' => 'sport'], 2000);
			$this->assertStringContainsString('Sport', $rFound);
			$this->assertStringNotContainsString('>News<', $rFound);
			$this->assertStringContainsString('account 8/50 conn.', SourceAuditPage::render(['status' => 'all'], 2000));
		} finally {
			DatabaseFactory::reset();
		}
	}

	public function testARecheckNeverTakesTheLastConnectionOfABusyAccount(): void {
		$rDb = new TestDb();
		foreach (['streams', 'streams_servers'] as $rTable) {
			$rDb->exec(InstallSchema::table($rTable));
		}
		$rSchema = (string) file_get_contents(glob(dirname(__DIR__, 2) . '/src/Modules/source-health_*')[0] . '/database.sql');
		foreach (array_filter(array_map('trim', explode(';', $rSchema))) as $rStatement) {
			$rDb->exec($rStatement);
		}
		DatabaseFactory::set($rDb);
		$rDir = sys_get_temp_dir() . '/sh-recheck-' . getmypid();
		@mkdir($rDir);
		$rPort = 22000 + getmypid() % 1000;
		file_put_contents($rDir . '/active', '1');
		file_put_contents($rDir . '/router.php', '<?php $u = $_SERVER["REQUEST_URI"];'
			. ' if (str_starts_with($u, "/player_api.php")) { header("Content-Type: application/json"); echo json_encode(["user_info" => ["auth" => 1, "status" => "Active", "max_connections" => "3", "active_cons" => trim(file_get_contents(__DIR__ . "/active")), "exp_date" => null]]); return true; }'
			. ' if (str_starts_with($u, "/live/")) { file_put_contents(__DIR__ . "/hit", $u, FILE_APPEND); header("Content-Type: video/mp2t"); echo str_repeat("\x47" . str_repeat("\x00", 187), 50); return true; } http_response_code(404); return true;');
		$rServer = proc_open(['php', '-S', '127.0.0.1:' . $rPort, $rDir . '/router.php'], [0 => ['file', '/dev/null', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $rPipes);
		usleep(400000);
		try {
			$rLive = 'http://127.0.0.1:' . $rPort . '/live/u/p/1.ts';
			$rBackup = 'http://127.0.0.1:' . $rPort . '/live/u/p/2.ts';
			$rDb->query("INSERT INTO `streams` (`id`, `type`, `stream_display_name`, `stream_source`) VALUES (5, 1, 'News', ?)", json_encode([$rLive, $rBackup]));
			$rDb->query("INSERT INTO `streams_servers` (`stream_id`, `server_id`, `pid`, `stream_status`, `current_source`) VALUES (5, 1, 10, 0, ?)", $rLive);

			$rOnAir = SourceAudit::recheckNow(5, md5($rLive));
			$this->assertFalse($rOnAir['ok']);
			$this->assertStringContainsString('on air', $rOnAir['message']);

			$rOk = SourceAudit::recheckNow(5, md5($rBackup));                     // 1 of 3 in use: the check and a spare fit
			$this->assertTrue($rOk['ok'], $rOk['message']);
			$this->assertSame('ok', SourceHealthRepository::auditState()[md5($rBackup)]['status']);

			file_put_contents($rDir . '/active', '2');                             // 2 of 3: the check would leave no spare
			@unlink($rDir . '/hit');
			$rFull = SourceAudit::recheckNow(5, md5($rBackup));
			$this->assertFalse($rFull['ok']);
			$this->assertStringContainsString('no connection to spare (2/3 in use)', $rFull['message']);
			$this->assertFileDoesNotExist($rDir . '/hit');                          // no stream request was made
		} finally {
			proc_terminate($rServer);
			proc_close($rServer);
			array_map('unlink', glob($rDir . '/*'));
			@rmdir($rDir);
			DatabaseFactory::reset();
		}
	}

	public function testAccountsInSourcesBecomeStreamProvidersThroughTheCoreService(): void {
		$this->assertSame(70, ProviderImport::channelId('http://h.example/live/u/p/70.ts'));
		$this->assertSame(1138, ProviderImport::channelId('http://h.example/u/p/1138'));
		$this->assertNull(ProviderImport::channelId('http://h.example/u/p/ts'));
		$this->assertSame('rtsh 1', ProviderImport::normalizeName('AL: RTSH 1 HD'));
		$this->assertSame(ProviderImport::normalizeName('Top Channel FHD'), ProviderImport::normalizeName('|AL| TOP CHANNEL'));
		$this->assertSame(['name' => 'h.example – u', 'ip' => 'h.example', 'port' => '8443', 'username' => 'u', 'password' => 'p', 'legacy' => 1, 'enabled' => 1, 'ssl' => 1, 'hls' => 1],
			ProviderImport::formData(['host' => 'h.example', 'scheme' => 'https', 'port' => 8443, 'user' => 'u', 'pass' => 'p', 'hls' => true]));

		$rDb = new TestDb();
		foreach (['streams', 'streams_servers', 'providers', 'providers_streams'] as $rTable) {
			$rDb->exec(InstallSchema::table($rTable));
		}
		$rSchema = (string) file_get_contents(glob(dirname(__DIR__, 2) . '/src/Modules/source-health_*')[0] . '/database.sql');
		foreach (array_filter(array_map('trim', explode(';', $rSchema))) as $rStatement) {
			$rDb->exec($rStatement);
		}
		DatabaseFactory::set($rDb);
		$rSaved = [$GLOBALS['db'] ?? null, $GLOBALS['rUserInfo'] ?? null, $GLOBALS['rPermissions'] ?? null];
		$GLOBALS['db'] = $rDb;
		$GLOBALS['rUserInfo'] = ['id' => 1, 'member_group_id' => 1];
		$GLOBALS['rPermissions'] = ['is_admin' => 1, 'advanced' => []];
		try {
			$rDb->query("INSERT INTO `streams` (`id`, `type`, `stream_display_name`, `stream_source`) VALUES (5, 1, 'AL: RTSH 1 HD', ?), (6, 1, 'Sport', ?)",
				json_encode(['http://a.example:8080/live/u1/p1/70.ts', 'http://b.example/u2/p2/9']), json_encode(['http://a.example:8080/live/u1/p1/71.ts', 'http://a.example:8080/live/u3/p3/5.m3u8']));
			$rDb->query("INSERT INTO `providers` (`id`, `name`, `ip`, `port`, `username`, `password`, `enabled`, `status`, `data`) VALUES (1, 'B', 'http://B.example/', 80, 'u2', 'p2', 1, 1, ?)", json_encode(['streams' => 2]));
			$rDb->query("INSERT INTO `providers_streams` (`provider_id`, `type`, `stream_id`, `stream_display_name`) VALUES (1, 'live', 8, 'RTSH 1'), (1, 'live', 12, 'Other')");

			$rFound = ProviderImport::discover();
			$this->assertCount(3, $rFound);
			$rA = $rFound[md5('http://a.example:8080/live/u1/p1/')];
			$this->assertSame([2, [5, 6], 8080, null], [$rA['sources'], $rA['streams'], $rA['port'], $rA['provider']]);
			$this->assertTrue($rFound[md5('http://a.example:8080/live/u3/p3/')]['hls']);
			$this->assertSame(1, $rFound[md5('http://b.example:/u2/p2/')]['provider']['id']);   // matched through its scheme and trailing slash

			$rResult = ProviderImport::add(array_keys($rFound));
			$this->assertSame(['added' => 2, 'existing' => 1, 'failed' => 0], $rResult);
			$rRow = $rDb->query('SELECT `name`, `ip`, `port`, `username`, `password`, `legacy`, `enabled`, `hls` FROM `providers` WHERE `username` = ?', 'u3') ? $rDb->get_raw_row() : null;
			$this->assertSame(['name' => 'a.example – u3', 'ip' => 'a.example', 'port' => '8080', 'username' => 'u3', 'password' => 'p3', 'legacy' => '1', 'enabled' => '1', 'hls' => '1'], array_map('strval', (array) $rRow));
			$this->assertSame(['added' => 0, 'existing' => 3, 'failed' => 0], ProviderImport::add(array_keys(ProviderImport::discover())));

			// The synced list of provider B says channel 9 is gone, and that "RTSH 1" is channel 8 now.
			$rCheck = ProviderImport::channelCheck([['source' => 'http://b.example/u2/p2/9', 'name' => 'AL: RTSH 1 HD'], ['source' => 'http://b.example/u2/p2/12', 'name' => 'Other']]);
			$this->assertSame(['provider' => 'B', 'listed' => false, 'suggest' => [['id' => 8, 'name' => 'RTSH 1']]], $rCheck[md5('http://b.example/u2/p2/9')]);
			$this->assertTrue($rCheck[md5('http://b.example/u2/p2/12')]['listed']);
			$this->assertArrayNotHasKey(md5('http://a.example:8080/live/u1/p1/70.ts'), ProviderImport::channelCheck([['source' => 'http://a.example:8080/live/u1/p1/70.ts', 'name' => 'x']])); // not synced yet: no claim
		} finally {
			[$GLOBALS['db'], $GLOBALS['rUserInfo'], $GLOBALS['rPermissions']] = $rSaved;
			DatabaseFactory::reset();
		}
	}
}
