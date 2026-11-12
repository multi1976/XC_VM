<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use XcVm\Infrastructure\Database\DatabaseFactory;
use XcVm\Module\LineAudit\LineAuditAnalyzer;
use XcVm\Module\LineAudit\LineAuditCronJob;
use XcVm\Module\LineAudit\LineAuditPage;
use XcVm\Module\LineAudit\LineAuditRepository;
use XcVm\Tests\Support\InstallSchema;

/**
 * Line Audit: what is sampled from the live connections, how a shared account
 * and an unstable client are told, and what each tab lists.
 */
final class LineAuditTest extends TestCase {
	public static function setUpBeforeClass(): void {
		$rDir = glob(dirname(__DIR__, 2) . '/src/Modules/line-audit_*')[0] ?? null;
		if ($rDir === null) {
			self::markTestSkipped('The line-audit module is not installed in this tree.');
		}
		foreach (['LineAuditRepository', 'LineAuditAnalyzer', 'LineAuditPage', 'LineAuditCronJob'] as $rClass) {
			require_once $rDir . '/' . $rClass . '.php';
		}
	}

	private static function conn(int $rUser, string $rIP, string $rCountry, string $rIsp = 'ISP A', string $rUA = 'VLC/3.0.18 LibVLC/3.0.18'): array {
		return ['user_id' => $rUser, 'ip' => $rIP, 'country' => $rCountry, 'isp' => $rIsp, 'ua' => $rUA, 'stream_id' => 1, 'server_id' => 1, 'date_start' => 0];
	}

	public function testOnlyLinesWorthALookAreSampledAndNoIPIsKept(): void {
		$rConns = [
			self::conn(1, '10.0.0.1', 'AL'), self::conn(1, '10.0.0.2', 'IT', 'ISP B', 'TiviMate/4.7.0'), self::conn(1, '10.0.0.2', 'IT', 'ISP B', 'TiviMate/5.0.4'),
			self::conn(2, '10.0.0.3', 'AL'),                                     // one connection, limit 1: at its limit
			self::conn(3, '10.0.0.4', 'AL'),                                     // one connection, limit 2: nothing to see
		];
		$rLines = [1 => ['max_connections' => 3], 2 => ['max_connections' => 1], 3 => ['max_connections' => 2]];
		$rSamples = LineAuditAnalyzer::samples($rConns, $rLines, 1000);
		$this->assertSame([1, 2], array_column($rSamples, 'user_id'));
		$this->assertSame(['sampled_at' => 1000, 'user_id' => 1, 'conns' => 3, 'max_conn' => 3, 'ips' => 2, 'countries' => 2, 'isps' => 2, 'uas' => 2, 'country_list' => 'AL,IT', 'isp_list' => 'ISP A | ISP B', 'ua_list' => 'TiviMate, VLC'], $rSamples[0]);   // two TiviMate versions are one player
		$this->assertStringNotContainsString('10.0.0', json_encode($rSamples));
	}

	public function testHowASharedAccountIsTold(): void {
		$rConcurrent = ['samples' => 10, 'multi_ip' => 6, 'multi_country' => 2, 'max_ips' => 3, 'max_countries' => 2];
		$rLikely = LineAuditAnalyzer::sharing($rConcurrent, ['ips' => 9, 'countries' => 3, 'isps' => 4]);
		$this->assertSame('likely', $rLikely['level']);
		$this->assertStringContainsString('2 countries at the same time (10m)', $rLikely['reasons'][0]);

		$rHomeAndPhone = LineAuditAnalyzer::sharing(null, ['ips' => 3, 'countries' => 1, 'isps' => 2]);   // TV at home, phone on mobile data
		$this->assertSame('none', $rHomeAndPhone['level']);
		$this->assertSame('suspicious', LineAuditAnalyzer::sharing(null, ['ips' => 4, 'countries' => 2, 'isps' => 2])['level']);
		$this->assertSame('note', LineAuditAnalyzer::sharing(null, ['ips' => 12, 'countries' => 1, 'isps' => 1])['level']);

		// Players: families, not versions; a restreaming tool weighs on its own.
		foreach (['TiviMate/4.7.0' => 'TiviMate', 'IPTVSmartersPlayer' => 'IPTV Smarters', 'Lavf/58.76.100' => 'ffmpeg / Lavf', 'curl/8.5.0' => 'curl',
			'Mozilla/5.0 (QtEmbedded; U; Linux; C) AppleWebKit/533.3 (KHTML, like Gecko) MAG200 stbapp ver: 2 rev: 250 Safari/533.3' => 'MAG box',
			'Kodi/20.2 (Linux; Android 11)' => 'Kodi', 'ExoPlayerLib/2.18.1' => 'Android player', '' => '(none)', 'SomethingNew/1.0' => 'Other'] as $rUA => $rFamily) {
			$this->assertSame($rFamily, LineAuditAnalyzer::playerFamily($rUA), $rUA);
		}
		$rTool = LineAuditAnalyzer::sharing(null, ['ips' => 1, 'countries' => 1, 'isps' => 1], ['ffmpeg / Lavf' => 40]);
		$this->assertSame('suspicious', $rTool['level']);
		$this->assertStringContainsString('ffmpeg / Lavf', $rTool['reasons'][0]);
		$rFamily = LineAuditAnalyzer::sharing(null, ['ips' => 2, 'countries' => 1, 'isps' => 1], ['TiviMate' => 9, 'IPTV Smarters' => 3, 'Smart TV' => 2]);
		$this->assertSame('none', $rFamily['level']);                                              // a TV, a phone and a tablet at home
		$rMany = LineAuditAnalyzer::sharing(['samples' => 5, 'multi_ip' => 3, 'multi_country' => 0, 'max_ips' => 3, 'max_countries' => 1, 'max_uas' => 3], ['ips' => 4, 'countries' => 1, 'isps' => 3],
			['TiviMate' => 9, 'IPTV Smarters' => 3, 'VLC' => 2, 'Kodi' => 1, 'Other' => 4]);
		$this->assertSame('likely', $rMany['level']);
		$this->assertContains('3 different players at the same time', $rMany['reasons']);
		$this->assertContains('4 different players', $rMany['reasons']);

		$this->assertTrue(LineAuditAnalyzer::unstable(['sessions' => 30, 'short' => 22]));
		$this->assertFalse(LineAuditAnalyzer::unstable(['sessions' => 100, 'short' => 22]));     // short ones are a minority
		$this->assertFalse(LineAuditAnalyzer::unstable(['sessions' => 12, 'short' => 12]));      // too few to tell
	}

	public function testEachTabListsWhatItShould(): void {
		$rDb = new TestDb();
		foreach (['lines', 'lines_live', 'lines_activity', 'lines_logs', 'users', 'streams'] as $rTable) {
			$rDb->exec(InstallSchema::table($rTable));
		}
		foreach (array_filter(array_map('trim', explode(';', (string) file_get_contents(glob(dirname(__DIR__, 2) . '/src/Modules/line-audit_*')[0] . '/database.sql')))) as $rStatement) {
			$rDb->exec($rStatement);
		}
		DatabaseFactory::set($rDb);
		try {
			$rNow = time();
			$rDb->query("INSERT INTO `users` (`id`, `username`) VALUES (5, 'reseller1')");
			$rDb->query("INSERT INTO `streams` (`id`, `type`, `stream_display_name`) VALUES (1, 1, 'News')");
			$rDb->query(
				"INSERT INTO `lines` (`id`, `member_id`, `username`, `password`, `max_connections`, `exp_date`, `enabled`, `admin_enabled`, `last_activity`, `created_at`, `is_restreamer`) VALUES
				 (1, 5, 'shared', 'x', 2, NULL, 1, 1, ?, ?, 0), (2, 5, 'expiring', 'x', 1, ?, 1, 1, ?, ?, 0), (3, 0, 'idle', 'x', 1, NULL, 1, 1, ?, ?, 0),
				 (4, 0, 'expired', 'x', 1, ?, 1, 1, ?, ?, 0), (5, 0, 'flaky', 'x', 1, NULL, 1, 1, ?, ?, 0)",
				$rNow, $rNow - 90 * 86400, $rNow + 3 * 86400, $rNow - 3600, $rNow - 90 * 86400, $rNow - 60 * 86400, $rNow - 90 * 86400,
				$rNow - 10 * 86400, $rNow - 86400, $rNow - 90 * 86400, $rNow, $rNow - 90 * 86400
			);
			// Live: "shared" from Albania and Italy at once, at its limit of 2.
			$rDb->query("INSERT INTO `lines_live` (`user_id`, `stream_id`, `server_id`, `user_ip`, `geoip_country_code`, `isp`, `user_agent`, `date_start`, `hls_end`) VALUES
				(1, 1, 1, '10.0.0.1', 'AL', 'ISP A', 'VLC', ?, 0), (1, 2, 1, '10.9.9.9', 'IT', 'ISP B', 'Smarters', ?, 0)", $rNow - 600, $rNow - 300);
			$this->assertSame(1, LineAuditCronJob::sample($rNow));
			$this->assertSame(1, LineAuditCronJob::sample($rNow + 300));
			// History: 25 sessions of a few seconds for "flaky".
			for ($i = 0; $i < 25; $i++) {
				$rDb->query("INSERT INTO `lines_activity` (`user_id`, `stream_id`, `server_id`, `user_ip`, `geoip_country_code`, `isp`, `user_agent`, `date_start`, `date_end`) VALUES (5, 1, 1, '10.1.1.1', 'AL', 'ISP A', 'VLC', ?, ?)", $rNow - 3600 + $i * 60, $rNow - 3600 + $i * 60 + 5);
			}
			$rDb->query("INSERT INTO `lines_logs` (`user_id`, `stream_id`, `client_status`, `ip`, `date`) VALUES (4, 1, 'USER_EXPIRED', '10.2.2.2', ?), (4, 1, 'USER_EXPIRED', '10.2.2.2', ?), (1, 1, 'COUNTRY_DISALLOW', '10.9.9.9', ?)", $rNow - 60, $rNow - 50, $rNow - 40);

			$this->assertSame([1 => ['samples' => 2, 'multi_ip' => 2, 'multi_country' => 2, 'at_limit' => 2, 'max_ips' => 2, 'max_countries' => 2, 'max_uas' => 2, 'last_seen' => $rNow + 300, 'country_list' => 'AL,IT', 'isp_list' => 'ISP A | ISP B', 'ua_list' => 'IPTV Smarters, VLC']], LineAuditRepository::sampleStats($rNow - 86400));
			$this->assertSame([5 => ['VLC' => 25]], LineAuditRepository::players($rNow - 86400));

			$rView = static fn (string $v, array $q = []): string => LineAuditPage::render(['view' => $v] + $q, $rNow + 300);
			$rSharing = $rView('sharing');
			$this->assertStringContainsString('>shared</a>', $rSharing);
			$this->assertStringContainsString('2 countries at the same time', $rSharing);
			$this->assertStringContainsString('AL,IT', $rSharing);
			$this->assertStringNotContainsString('10.9.9.9', $rSharing);
			$this->assertStringContainsString('IPTV Smarters, VLC', $rSharing);                      // players seen live
			$this->assertStringContainsString('>shared</a>', $rView('limits'));
			$rRefused = $rView('refused');
			$this->assertStringContainsString('USER_EXPIRED 2', $rRefused);
			$this->assertStringContainsString('COUNTRY_DISALLOW 1', $rRefused);
			$this->assertMatchesRegularExpression('/News <span[^>]*>×2/', $rRefused);                                // what the expired line kept asking for
			$this->assertSame([4 => [['stream_id' => 1, 'name' => 'News', 'type' => 1, 'status' => 'USER_EXPIRED', 'n' => 2]]], LineAuditRepository::refusalStreams($rNow - 86400, [4]));
			$rExpiry = $rView('expiry');
			$this->assertMatchesRegularExpression('/Expiring in the next 7 days.*>expiring<\/a>.*Expired, still trying.*>expired<\/a>/s', $rExpiry);
			$rUnused = $rView('unused');
			$this->assertStringContainsString('>idle</a>', $rUnused);
			$this->assertStringNotContainsString('>shared</a>', $rUnused);
			$this->assertStringContainsString('>flaky</a>', $rView('unstable'));
			$this->assertStringNotContainsString('>flaky</a>', $rView('unstable', ['reseller' => 5]));      // flaky has no owner 5
			$this->assertStringContainsString('reseller1', $rView('overview'));
			$this->assertStringContainsString('USER_EXPIRED: 2', $rView('overview'));
		} finally {
			DatabaseFactory::reset();
		}
	}
}
