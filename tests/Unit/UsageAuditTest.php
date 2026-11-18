<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use XcVm\Infrastructure\Database\DatabaseFactory;
use XcVm\Module\UsageAudit\UsageAuditAnalyzer;
use XcVm\Module\UsageAudit\UsageAuditPage;
use XcVm\Module\UsageAudit\UsageAuditRepository;
use XcVm\Tests\Support\InstallSchema;

/**
 * Usage Audit: how viewing is summed and peaked, and what each tab lists.
 */
final class UsageAuditTest extends TestCase {
	public static function setUpBeforeClass(): void {
		$rDir = glob(dirname(__DIR__, 2) . '/src/Modules/usage-audit_*')[0] ?? null;
		if ($rDir === null) {
			self::markTestSkipped('The usage-audit module is not installed in this tree.');
		}
		foreach (['UsageAuditAnalyzer', 'UsageAuditRepository', 'UsageAuditPage'] as $rClass) {
			require_once $rDir . '/' . $rClass . '.php';
		}
	}

	public function testPeakCountsViewersAtTheSameMomentOnly(): void {
		$this->assertSame([2, 50], UsageAuditAnalyzer::peak([[0, 100], [50, 150], [100, 200]]));   // the first ends as the third starts
		$this->assertSame([1, 0], UsageAuditAnalyzer::peak([[0, 10], [10, 20], [20, 30]]));       // one viewer zapping
		$this->assertSame([0, 0], UsageAuditAnalyzer::peak([]));
	}

	public function testUsageIsClippedToTheWindowAndRestreamersAreApart(): void {
		$rFrom = 1_000_000;
		$rTo = $rFrom + 3600;
		$rSessions = [
			[1, 10, $rFrom - 600, $rFrom + 600],     // began before the window: 600 s inside
			[2, 10, $rFrom + 300, $rFrom + 900],     // with line 1 for 300 s
			[3, 10, $rFrom + 100, $rFrom + 130],     // 30 s: zapped past, not a viewer (but two at once with line 1)
			[4, 10, $rFrom, 0],                      // a restreamer, still connected
			[1, 20, $rFrom + 3000, 0],               // still watching: runs to the end of the window
			[5, 30, $rFrom + 10, $rFrom + 70],       // an episode
			[5, 31, $rFrom + 70, $rFrom + 200],      // the next one of the same series
			[1, 99, $rFrom - 900, $rFrom - 100],     // ended before the window
		];
		$u = UsageAuditAnalyzer::usage($rSessions, $rFrom, $rTo, [4 => true], [30 => 's7', 31 => 's7']);
		$this->assertSame(['secs' => 1230, 'sessions' => 3, 'lines' => 2, 'peak' => 2, 'peak_at' => $rFrom + 100, 'restream' => 1], $u['groups'][10]);
		$this->assertSame(600, $u['groups'][20]['secs']);
		$this->assertSame(['secs' => 190, 'sessions' => 2, 'lines' => 1, 'peak' => 1, 'peak_at' => $rFrom + 10, 'restream' => 0], $u['groups']['s7']);
		$this->assertArrayNotHasKey(99, $u['groups']);
		$this->assertSame([10, 20, 's7'], array_keys($u['groups']));                              // most watched first
		$this->assertSame(1230 + 600 + 190, $u['total']['secs']);
		$this->assertSame(3, $u['total']['lines']);                                               // 1, 2, 5: not 3 (30 s), not the restreamer
		$this->assertSame(array_sum($u['hours']), $u['total']['secs']);

		// A restreamer alone on a stream still marks it as pulled.
		$rOnly = UsageAuditAnalyzer::usage([[4, 40, $rFrom, $rFrom + 60]], $rFrom, $rTo, [4 => true]);
		$this->assertSame(['secs' => 0, 'sessions' => 0, 'lines' => 0, 'peak' => 0, 'peak_at' => 0, 'restream' => 1], $rOnly['groups'][40]);
	}

	public function testHourlyAverageIsViewersPerHourOfTheDay(): void {
		$rFrom = mktime(0, 0, 0, 1, 10, 2026);
		$rHours = array_fill(0, 24, 0);
		$rHours[20] = 2 * 3 * 3600;   // two viewers through 20:00-21:00 on each of three days
		$rAvg = UsageAuditAnalyzer::hourly($rHours, $rFrom, $rFrom + 3 * 86400);
		$this->assertSame(2.0, $rAvg[20]);
		$this->assertSame(0.0, $rAvg[3]);
		$this->assertSame([3, 7], UsageAuditAnalyzer::ids('[3,"7",0]'));
		$this->assertSame([5], UsageAuditAnalyzer::ids('5'));
		$this->assertSame([], UsageAuditAnalyzer::ids(null));
		$this->assertSame('2h 5m', UsageAuditAnalyzer::duration(7500));
	}

	public function testEachTabListsWhatItShould(): void {
		$rDb = new TestDb();
		foreach (['lines', 'lines_live', 'lines_activity', 'streams', 'streams_servers', 'streams_series', 'streams_episodes', 'streams_categories', 'bouquets'] as $rTable) {
			$rDb->exec(InstallSchema::table($rTable));
		}
		DatabaseFactory::set($rDb);
		try {
			$rNow = time();
			$rDb->query("INSERT INTO `streams_categories` (`id`, `category_type`, `category_name`) VALUES (1, 'live', 'News'), (2, 'live', 'Sport'), (3, 'movie', 'Films'), (4, 'series', 'Drama')");
			$rDb->query(
				"INSERT INTO `streams` (`id`, `type`, `stream_display_name`, `category_id`, `stream_source`, `added`) VALUES
				 (1, 1, 'News 24', '[1]', '[\"http://a/1\"]', ?), (2, 1, 'Sport <HD>', '[2]', '[\"http://a/2\",\"http://b/2\"]', ?), (3, 1, 'Idle Always On', '[2]', '[\"http://a/3\"]', ?),
				 (4, 1, 'Hidden', '[1]', '[]', ?), (5, 2, 'A Movie', '[3]', NULL, ?), (6, 2, 'Unseen Movie', '[3]', NULL, ?), (7, 5, 'S01E01', NULL, NULL, ?), (8, 1, '=SPORT=', '[2]', '[]', ?)",
				$rNow, $rNow, $rNow, $rNow, $rNow, $rNow, $rNow, $rNow
			);
			$rDb->query("INSERT INTO `streams_series` (`id`, `title`, `category_id`) VALUES (1, 'Watched Show', '[4]'), (2, 'Unseen Show', '[4]')");
			$rDb->query('INSERT INTO `streams_episodes` (`series_id`, `stream_id`) VALUES (1, 7)');
			$rDb->query("INSERT INTO `bouquets` (`bouquet_name`, `bouquet_channels`, `bouquet_movies`, `bouquet_radios`, `bouquet_series`) VALUES ('Main', '[1,2,3]', '[5,6]', '[]', '[1,2]')");
			$rDb->query('INSERT INTO `streams_servers` (`stream_id`, `server_id`, `pid`, `on_demand`) VALUES (1, 1, 100, 0), (2, 1, 0, 1), (3, 1, 300, 0), (4, 1, 0, 0)');
			$rDb->query("INSERT INTO `lines` (`id`, `username`, `password`, `is_restreamer`) VALUES (1, 'a', 'x', 0), (2, 'b', 'x', 0), (9, 'rs', 'x', 1)");
			$rDb->query(
				'INSERT INTO `lines_activity` (`user_id`, `stream_id`, `server_id`, `date_start`, `date_end`) VALUES
				 (1, 1, 1, ?, ?), (2, 1, 1, ?, ?), (1, 2, 1, ?, ?), (2, 5, 1, ?, ?), (1, 7, 1, ?, ?), (9, 2, 1, ?, ?), (1, 4, 1, ?, ?)',
				$rNow - 7200, $rNow - 3600, $rNow - 5400, $rNow - 3000, $rNow - 900, $rNow - 300, $rNow - 6000, $rNow - 1000, $rNow - 4000, $rNow - 2000,
				$rNow - 8000, $rNow - 100, $rNow - 40 * 86400, $rNow - 40 * 86400 + 600
			);
			$rDb->query('INSERT INTO `lines_live` (`user_id`, `stream_id`, `server_id`, `date_start`, `hls_end`) VALUES (2, 2, 1, ?, 0)', $rNow - 600);

			$rView = static fn (string $v, array $q = []): string => UsageAuditPage::render(['view' => $v] + $q, $rNow);
			$rLive = $rView('live');
			$this->assertMatchesRegularExpression('/News 24<\/a>.*Sport &lt;HD&gt;<\/a>/s', $rLive);   // most watched first, names escaped
			$this->assertStringNotContainsString('Idle Always On', $rLive);
			$this->assertStringContainsString('>2</td>', $rLive);                                       // News 24: two at once
			$this->assertStringContainsString('always on', $rLive);

			$rNever = $rView('never');
			$this->assertMatchesRegularExpression('/Idle Always On.*Hidden/s', $rNever);               // always on first: it costs something
			$this->assertStringContainsString('<strong>1</strong> running all the time', $rNever);
			$this->assertStringContainsString('<strong>1</strong> in no bouquet', $rNever);
			$this->assertStringContainsString('<strong>1</strong> without a source', $rNever);
			$this->assertStringNotContainsString('Sport &lt;HD', $rNever);                                   // a restreamer and a live viewer pull it
			$this->assertMatchesRegularExpression('/Hidden.*days ago/s', $rNever);                      // last watched 40 days ago
			$this->assertStringContainsString('Unseen Movie', $rView('never', ['kind' => 'movies']));
			$rSeries = $rView('never', ['kind' => 'series']);
			$this->assertStringContainsString('Unseen Show', $rSeries);
			$this->assertStringNotContainsString('Watched Show', $rSeries);
			$this->assertStringContainsString('Watched Show', $rView('series'));
			$this->assertStringContainsString('A Movie', $rView('movies'));

			$rCats = $rView('categories');
			$this->assertMatchesRegularExpression('/News<\/td>.*Sport<\/td>/s', $rCats);
			$this->assertStringContainsString('1 of 2</td>', $rCats);                                  // News: one of its two channels watched

			$rOverview = $rView('overview');
			$this->assertStringContainsString('Most watched channels', $rOverview);
			$this->assertStringContainsString('Restreamer lines (1)', $rOverview);
			$this->assertStringContainsString('1 of them running all the time', $rOverview);
			$this->assertSame($rNow - 40 * 86400, UsageAuditRepository::coverage());
			$this->assertStringContainsString('News 24', $rView('live', ['q' => 'news']));
			$this->assertStringNotContainsString('Sport &lt;HD', $rView('live', ['q' => 'news']));

			// Category separators count nowhere, even when a player opened one.
			$rDb->query('INSERT INTO `lines_activity` (`user_id`, `stream_id`, `server_id`, `date_start`, `date_end`) VALUES (1, 8, 1, ?, ?)', $rNow - 500, $rNow - 400);
			foreach (['live', 'never', 'overview'] as $v) {
				$this->assertStringNotContainsString('=SPORT=', $rView($v), $v);
			}
			$this->assertStringContainsString('1 category separators', $rView('live'));
			$this->assertTrue(UsageAuditAnalyzer::isSeparator('=== NEWS ==='));
			$this->assertFalse(UsageAuditAnalyzer::isSeparator('News = Live'));

			// The category filter: one or more categories.
			$rSport = $rView('live', ['cat' => ['2']]);
			$this->assertStringContainsString('Sport &lt;HD', $rSport);
			$this->assertStringNotContainsString('News 24</a>', $rSport);
			$this->assertMatchesRegularExpression('/<option value="2" selected>Sport<\/option>/', $rSport);
			$this->assertStringNotContainsString('>Films</option>', $rSport);                       // a movie category is no choice for channels
			$this->assertMatchesRegularExpression('/News 24<\/a>.*Sport &lt;HD/s', $rView('live', ['cat' => ['1', '2']]));
			$rNeverNews = $rView('never', ['cat' => ['1']]);
			$this->assertStringContainsString('Hidden', $rNeverNews);
			$this->assertStringNotContainsString('Idle Always On', $rNeverNews);
		} finally {
			DatabaseFactory::reset();
		}
	}
}
