<?php

namespace XcVm\Module\UsageAudit;

use XcVm\Core\Config\SettingsManager;
use XcVm\Domain\Stream\ConnectionTracker;
use XcVm\Infrastructure\Database\DatabaseAware;

/**
 * What the Usage Audit reads: the activity log (`lines_activity`, one row per
 * ended session), the live connections (Redis or `lines_live`), the streams,
 * series, categories and bouquets, and which streams are running now.
 * Read-only on every table.
 */
final class UsageAuditRepository {
	use DatabaseAware;

	/** Most sessions read for one page; past it the oldest are left out and the page says so. */
	public const MAX_SESSIONS = 200000;

	public const LIVE_TYPES = [1, 3];
	public const RADIO_TYPE = 4;
	public const MOVIE_TYPE = 2;
	public const EPISODE_TYPE = 5;

	/**
	 * Every session that overlaps [from, to): ended ones from the activity
	 * log, then the ones still running (date_end 0).
	 *
	 * @return array{sessions: list<array{0:int,1:int,2:int,3:int}>, truncated: bool}
	 */
	public static function sessions(int $rFrom, int $rTo): array {
		$rRows = self::db()->fetchAll(
			'SELECT `user_id`, `stream_id`, `date_start`, `date_end` FROM `lines_activity` WHERE `date_end` > ? AND `date_start` < ? AND `user_id` > 0 AND `stream_id` > 0 ORDER BY `date_end` DESC LIMIT ' . (self::MAX_SESSIONS + 1),
			$rFrom, $rTo
		) ?: [];
		$rTruncated = count($rRows) > self::MAX_SESSIONS;
		$rOut = [];
		foreach (array_slice($rRows, 0, self::MAX_SESSIONS) as $r) {
			$rOut[] = [(int) $r['user_id'], (int) $r['stream_id'], (int) $r['date_start'], (int) $r['date_end']];
		}
		foreach (self::live() as $r) {
			$rOut[] = $r;
		}
		return ['sessions' => $rOut, 'truncated' => $rTruncated];
	}

	/** @return list<array{0:int,1:int,2:int,3:int}> the connections open now, date_end 0 */
	public static function live(): array {
		if (SettingsManager::get('redis_handler')) {
			[, $rData] = ConnectionTracker::getConnections() + [[], []];
			$rRows = array_values(array_filter((array) $rData, 'is_array'));
		} else {
			$rRows = self::db()->fetchAll('SELECT `user_id`, `stream_id`, `date_start`, `hls_end` FROM `lines_live` WHERE `user_id` > 0 AND (`hls_end` = 0 OR `hls_end` IS NULL)') ?: [];
		}
		$rOut = [];
		foreach ($rRows as $r) {
			if ((int) ($r['user_id'] ?? 0) > 0 && (int) ($r['stream_id'] ?? 0) > 0 && empty($r['hls_end'])) {
				$rOut[] = [(int) $r['user_id'], (int) $r['stream_id'], (int) ($r['date_start'] ?? 0), 0];
			}
		}
		return $rOut;
	}

	/** @return array<int, true> restreamer line ids */
	public static function restreamers(): array {
		return array_fill_keys(array_map('intval', self::db()->fetchColumn('SELECT `id` FROM `lines` WHERE `is_restreamer` = 1') ?: []), true);
	}

	/**
	 * Every stream, without its sources: for live channels and radio only how many it has.
	 *
	 * @return array<int, array{id:int, type:int, name:string, categories:list<int>, sources:int, added:int}>
	 */
	public static function streams(): array {
		$rOut = [];
		foreach (self::db()->fetchAll('SELECT `id`, `type`, `stream_display_name`, `category_id`, `added`, IF(`type` IN (1, 4), `stream_source`, NULL) AS `stream_source` FROM `streams`') ?: [] as $r) {
			$rSources = json_decode(self::raw((string) ($r['stream_source'] ?? '')), true);
			$rOut[(int) $r['id']] = [
				'id' => (int) $r['id'], 'type' => (int) $r['type'], 'name' => self::raw((string) $r['stream_display_name']),
				'categories' => UsageAuditAnalyzer::ids($r['category_id'] ?? null),
				'sources' => is_array($rSources) ? count(array_filter($rSources, static fn ($s): bool => is_string($s) && trim($s) !== '')) : 0,
				'added' => (int) ($r['added'] ?? 0),
			];
		}
		return $rOut;
	}

	/** @return array{episodes: array<int, int>, series: array<int, array{name:string, categories:list<int>}>} episode stream id => series id; series id => title */
	public static function series(): array {
		$rEpisodes = [];
		foreach (self::db()->fetchAll('SELECT `stream_id`, `series_id` FROM `streams_episodes` WHERE `series_id` > 0') ?: [] as $r) {
			$rEpisodes[(int) $r['stream_id']] = (int) $r['series_id'];
		}
		$rSeries = [];
		foreach (self::db()->fetchAll('SELECT `id`, `title`, `category_id` FROM `streams_series`') ?: [] as $r) {
			$rSeries[(int) $r['id']] = ['name' => self::raw((string) $r['title']), 'categories' => UsageAuditAnalyzer::ids($r['category_id'] ?? null)];
		}
		return ['episodes' => $rEpisodes, 'series' => $rSeries];
	}

	/** @return array<int, array{name:string, type:string}> */
	public static function categories(): array {
		$rOut = [];
		foreach (self::db()->fetchAll('SELECT `id`, `category_name`, `category_type` FROM `streams_categories`') ?: [] as $r) {
			$rOut[(int) $r['id']] = ['name' => self::raw((string) $r['category_name']), 'type' => (string) $r['category_type']];
		}
		return $rOut;
	}

	/**
	 * In how many bouquets each stream and series is: one in none is offered to no line.
	 *
	 * @return array{streams: array<int, int>, series: array<int, int>}
	 */
	public static function bouquets(): array {
		$rStreams = [];
		$rSeries = [];
		foreach (self::db()->fetchAll('SELECT `bouquet_channels`, `bouquet_movies`, `bouquet_radios`, `bouquet_series` FROM `bouquets`') ?: [] as $r) {
			foreach (array_unique(array_merge(UsageAuditAnalyzer::ids($r['bouquet_channels']), UsageAuditAnalyzer::ids($r['bouquet_movies']), UsageAuditAnalyzer::ids($r['bouquet_radios']))) as $rID) {
				$rStreams[$rID] = ($rStreams[$rID] ?? 0) + 1;
			}
			foreach (array_unique(UsageAuditAnalyzer::ids($r['bouquet_series'])) as $rID) {
				$rSeries[$rID] = ($rSeries[$rID] ?? 0) + 1;
			}
		}
		return ['streams' => $rStreams, 'series' => $rSeries];
	}

	/** @return array<int, array{running:bool, on_demand:bool}> stream id => whether it runs now on any server, and whether it is on demand on all */
	public static function running(): array {
		$rOut = [];
		foreach (self::db()->fetchAll('SELECT `stream_id`, MAX(`pid` > 0) AS `running`, MIN(`on_demand`) AS `on_demand` FROM `streams_servers` GROUP BY `stream_id`') ?: [] as $r) {
			$rOut[(int) $r['stream_id']] = ['running' => (int) $r['running'] === 1, 'on_demand' => (int) $r['on_demand'] === 1];
		}
		return $rOut;
	}

	/**
	 * When each stream was last watched, as far back as the activity log goes.
	 *
	 * @param list<int> $rIDs
	 * @return array<int, int> stream id => unix time
	 */
	public static function lastWatched(array $rIDs): array {
		$rOut = [];
		foreach (array_chunk(array_values(array_unique(array_map('intval', $rIDs))), 1000) as $rChunk) {
			foreach (self::db()->fetchAll('SELECT `stream_id`, MAX(`date_end`) AS `last` FROM `lines_activity` WHERE `stream_id` IN (' . implode(',', $rChunk) . ') AND `user_id` > 0 GROUP BY `stream_id`') ?: [] as $r) {
				$rOut[(int) $r['stream_id']] = (int) $r['last'];
			}
		}
		return $rOut;
	}

	/** When the activity log starts: nothing before it can be told. */
	public static function coverage(): int {
		return (int) self::db()->fetchValue('SELECT MIN(`date_start`) FROM `lines_activity` WHERE `date_start` > 0');
	}

	public static function raw(string $rValue): string {
		return str_replace(['&lt;', '&gt;'], ['<', '>'], $rValue);
	}
}
