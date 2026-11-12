<?php

namespace XcVm\Module\LineAudit;

use XcVm\Core\Config\SettingsManager;
use XcVm\Domain\Stream\ConnectionTracker;
use XcVm\Infrastructure\Database\DatabaseAware;

/**
 * What Line Audit reads: the live connections (from Redis or `lines_live`,
 * whichever the panel uses), the connection history (`lines_activity`), the
 * refused attempts (`lines_logs`), the lines and their resellers. Read-only
 * on every core table; it writes only its own `line_audit_samples`.
 */
final class LineAuditRepository {
	/** Seconds between one session's end and the next's start that still make a handover. */
	public const HANDOVER_GAP_SEC = 20;
	/** …and how far the next may start before the previous was closed. */
	public const HANDOVER_OVERLAP_SEC = 60;

	use DatabaseAware;

	public const SAMPLE_TTL_SEC = 7 * 86400;

	/**
	 * Every live connection on the panel, one row each.
	 *
	 * @return list<array{user_id:int, ip:string, country:string, isp:string, ua:string, stream_id:int, server_id:int, date_start:int}>
	 */
	public static function liveConnections(): array {
		$rRows = [];
		if (SettingsManager::get('redis_handler')) {
			[, $rData] = ConnectionTracker::getConnections() + [[], []];
			$rRows = array_values(array_filter((array) $rData, 'is_array'));
		} else {
			$rRows = self::db()->fetchAll(
				'SELECT `user_id`, `user_ip`, `geoip_country_code`, `isp`, `user_agent`, `stream_id`, `server_id`, `date_start` FROM `lines_live` WHERE `user_id` > 0 AND (`hls_end` = 0 OR `hls_end` IS NULL)'
			) ?: [];
		}
		$rOut = [];
		foreach ($rRows as $rRow) {
			if ((int) ($rRow['user_id'] ?? 0) <= 0 || !empty($rRow['hls_end'])) {
				continue;
			}
			$rOut[] = [
				'user_id' => (int) $rRow['user_id'], 'ip' => (string) ($rRow['user_ip'] ?? ''), 'country' => strtoupper((string) ($rRow['geoip_country_code'] ?? '')),
				'isp' => self::raw((string) ($rRow['isp'] ?? '')), 'ua' => self::raw((string) ($rRow['user_agent'] ?? '')),
				'stream_id' => (int) ($rRow['stream_id'] ?? 0), 'server_id' => (int) ($rRow['server_id'] ?? 0), 'date_start' => (int) ($rRow['date_start'] ?? 0),
			];
		}
		return $rOut;
	}

	/**
	 * @param list<int> $rIDs
	 * @return array<int, array<string, mixed>> line id => line (no credentials beyond the username)
	 */
	public static function lines(array $rIDs = []): array {
		$rOut = [];
		$rSql = 'SELECT `id`, `username`, `member_id`, `max_connections`, `exp_date`, `enabled`, `admin_enabled`, `is_trial`, `is_restreamer`, `is_mag`, `is_e2`, `is_isplock`, `isp_desc`, `last_activity`, `created_at` FROM `lines`';
		foreach ($rIDs === [] ? [null] : array_chunk(array_values(array_unique(array_map('intval', $rIDs))), 1000) as $rChunk) {
			$rRows = $rChunk === null ? self::db()->fetchAll($rSql) : self::db()->fetchAll($rSql . ' WHERE `id` IN (' . implode(',', $rChunk) . ')');
			foreach ($rRows ?: [] as $rRow) {
				$rRow['username'] = self::raw((string) $rRow['username']);
				$rRow['isp_desc'] = self::raw((string) ($rRow['isp_desc'] ?? ''));
				$rOut[(int) $rRow['id']] = $rRow;
			}
		}
		return $rOut;
	}

	/** @return array<int, string> user id => username, for the resellers (owners) of lines */
	public static function owners(array $rIDs): array {
		$rIDs = array_values(array_filter(array_unique(array_map('intval', $rIDs))));
		if ($rIDs === []) {
			return [];
		}
		$rOut = [];
		foreach (self::db()->fetchAll('SELECT `id`, `username` FROM `users` WHERE `id` IN (' . implode(',', $rIDs) . ')') ?: [] as $rRow) {
			$rOut[(int) $rRow['id']] = self::raw((string) $rRow['username']);
		}
		return $rOut;
	}

	/**
	 * Per line, what its sessions since $rFrom looked like.
	 *
	 * @return array<int, array{sessions:int, ips:int, countries:int, isps:int, uas:int, short:int, watched:int, country_list:string}>
	 */
	public static function history(int $rFrom): array {
		$rOut = [];
		$rRows = self::db()->fetchAll(
			'SELECT `user_id`, COUNT(*) AS `sessions`, COUNT(DISTINCT `user_ip`) AS `ips`, COUNT(DISTINCT `geoip_country_code`) AS `countries`,
			        COUNT(DISTINCT `isp`) AS `isps`, COUNT(DISTINCT `user_agent`) AS `uas`,
			        SUM(`date_end` IS NOT NULL AND `date_end` - `date_start` BETWEEN 0 AND 59) AS `short`,
			        SUM(GREATEST(0, COALESCE(`date_end`, `date_start`) - `date_start`)) AS `watched`,
			        SUBSTRING(GROUP_CONCAT(DISTINCT `geoip_country_code` ORDER BY `geoip_country_code` SEPARATOR \',\'), 1, 64) AS `country_list`
			 FROM `lines_activity` WHERE `date_start` >= ? AND `user_id` > 0 GROUP BY `user_id`',
			$rFrom
		) ?: [];
		foreach ($rRows as $rRow) {
			$rOut[(int) $rRow['user_id']] = [
				'sessions' => (int) $rRow['sessions'], 'ips' => (int) $rRow['ips'], 'countries' => (int) $rRow['countries'], 'isps' => (int) $rRow['isps'],
				'uas' => (int) $rRow['uas'], 'short' => (int) $rRow['short'], 'watched' => (int) $rRow['watched'], 'country_list' => (string) $rRow['country_list'],
			];
		}
		return $rOut;
	}

	/**
	 * Per line, how often one place took it over from another since $rFrom.
	 * At its connection limit the panel closes the oldest connection when a new
	 * one opens: two households on a one-connection line show as sessions from
	 * two IPs that end the moment the other starts, back and forth (A, B, A…).
	 * A handover is the next session from another IP starting within
	 * HANDOVER_GAP_SEC of the previous one's end (or up to HANDOVER_OVERLAP_SEC
	 * before it: an HLS session is closed a little after it is replaced); a
	 * return is a handover back to the IP of the session before.
	 *
	 * isp_returns are the returns that also change ISP: two homes, not one
	 * router given a new address.
	 *
	 * @return array<int, array{handovers:int, returns:int, isp_returns:int}>
	 */
	public static function handovers(int $rFrom): array {
		$rOut = [];
		$rRows = self::db()->fetchAll(
			'SELECT `user_id`, SUM(`h`) AS `handovers`, SUM(`h` AND `ip` = `ip2`) AS `returns`, SUM(`h` AND `ip` = `ip2` AND `isp` <> `isp1`) AS `isp_returns` FROM (
			   SELECT `user_id`, `user_ip` AS `ip`, LAG(`user_ip`, 2) OVER w AS `ip2`, COALESCE(`isp`, \'\') AS `isp`, COALESCE(LAG(`isp`) OVER w, \'\') AS `isp1`,
			          (`user_ip` <> LAG(`user_ip`) OVER w AND `date_start` - LAG(`date_end`) OVER w BETWEEN ? AND ?) AS `h`
			   FROM `lines_activity` WHERE `date_start` >= ? AND `user_id` > 0 AND `date_end` IS NOT NULL
			   WINDOW w AS (PARTITION BY `user_id` ORDER BY `date_start`, `activity_id`)
			 ) t GROUP BY `user_id` HAVING `handovers` > 0',
			-self::HANDOVER_OVERLAP_SEC, self::HANDOVER_GAP_SEC, $rFrom
		) ?: [];
		foreach ($rRows as $rRow) {
			$rOut[(int) $rRow['user_id']] = ['handovers' => (int) $rRow['handovers'], 'returns' => (int) $rRow['returns'], 'isp_returns' => (int) $rRow['isp_returns']];
		}
		return $rOut;
	}

	/**
	 * Remember the ISPs lines are used from: those of the sessions that ended
	 * since $rSince, of the lines used since then (their current ISP) and of
	 * the connections live now. The first run reads the whole activity log.
	 *
	 * @param list<array{user_id:int, isp:string, date_start:int}> $rLive
	 * @return int rows written
	 */
	public static function recordIsps(int $rNow, int $rSince, array $rLive): int {
		$rRows = [];
		$rAdd = static function (int $rUser, string $rIsp, int $rFirst, int $rLast) use (&$rRows): void {
			$rIsp = trim(self::raw($rIsp));
			if ($rUser <= 0 || $rIsp === '') {
				return;
			}
			$rKey = $rUser . ':' . md5(mb_strtolower($rIsp));
			$rFirst = $rFirst > 0 ? $rFirst : $rLast;
			$rRows[$rKey] = isset($rRows[$rKey])
				? [$rUser, $rRows[$rKey][1], $rRows[$rKey][2], min($rRows[$rKey][3], $rFirst), max($rRows[$rKey][4], $rLast)]
				: [$rUser, md5(mb_strtolower($rIsp)), mb_substr($rIsp, 0, 255), $rFirst, $rLast];
		};
		$rEmpty = (int) self::db()->fetchValue('SELECT COUNT(*) FROM `line_audit_isps`') === 0;
		$rSessions = self::db()->fetchAll(
			'SELECT `user_id`, `isp`, MIN(`date_start`) AS `f`, MAX(COALESCE(`date_end`, `date_start`)) AS `l` FROM `lines_activity` '
			. "WHERE `user_id` > 0 AND `isp` IS NOT NULL AND `isp` <> ''" . ($rEmpty ? '' : ' AND `date_end` >= ?') . ' GROUP BY `user_id`, `isp`',
			...($rEmpty ? [] : [$rSince])
		) ?: [];
		foreach ($rSessions as $r) {
			$rAdd((int) $r['user_id'], (string) $r['isp'], (int) $r['f'], (int) $r['l']);
		}
		foreach (self::db()->fetchAll("SELECT `id`, `isp_desc`, `last_activity` FROM `lines` WHERE `isp_desc` IS NOT NULL AND `isp_desc` <> '' AND `last_activity` >= ?", $rEmpty ? 0 : $rSince) ?: [] as $r) {
			$rAdd((int) $r['id'], (string) $r['isp_desc'], (int) $r['last_activity'], (int) $r['last_activity']);
		}
		foreach ($rLive as $c) {
			$rAdd((int) $c['user_id'], (string) ($c['isp'] ?? ''), (int) ($c['date_start'] ?? 0), $rNow);
		}
		foreach (array_chunk(array_values($rRows), 500) as $rChunk) {
			self::db()->query(
				'INSERT INTO `line_audit_isps` (`user_id`, `isp_hash`, `isp`, `first_seen`, `last_seen`) VALUES ' . implode(',', array_fill(0, count($rChunk), '(?, ?, ?, ?, ?)'))
				. ' ON DUPLICATE KEY UPDATE `first_seen` = LEAST(`first_seen`, VALUES(`first_seen`)), `last_seen` = GREATEST(`last_seen`, VALUES(`last_seen`))',
				...array_merge(...$rChunk)
			);
		}
		self::db()->query('DELETE i FROM `line_audit_isps` i LEFT JOIN `lines` l ON l.`id` = i.`user_id` WHERE l.`id` IS NULL');
		return count($rRows);
	}

	/**
	 * The ISPs of every line that started using a new one since $rFrom.
	 *
	 * @return array<int, list<array{isp:string, first_seen:int, last_seen:int}>> line id => its ISPs, oldest first
	 */
	public static function ispHistory(int $rFrom): array {
		$rOut = [];
		$rRows = self::db()->fetchAll(
			'SELECT i.`user_id`, i.`isp`, i.`first_seen`, i.`last_seen` FROM `line_audit_isps` i '
			. 'JOIN (SELECT DISTINCT `user_id` FROM `line_audit_isps` WHERE `first_seen` >= ?) n ON n.`user_id` = i.`user_id` ORDER BY i.`user_id`, i.`first_seen`',
			$rFrom
		) ?: [];
		foreach ($rRows as $r) {
			$rOut[(int) $r['user_id']][] = ['isp' => self::raw((string) $r['isp']), 'first_seen' => (int) $r['first_seen'], 'last_seen' => (int) $r['last_seen']];
		}
		return $rOut;
	}

	/**
	 * Refused attempts since $rFrom, per line and reason (the panel's client
	 * log; empty when client logs are not saved).
	 *
	 * @return array<int, array<string, int>> line id => status => count
	 */
	public static function refusals(int $rFrom): array {
		$rOut = [];
		foreach (self::db()->fetchAll('SELECT `user_id`, `client_status`, COUNT(*) AS `n` FROM `lines_logs` WHERE `date` >= ? AND `user_id` > 0 GROUP BY `user_id`, `client_status`', $rFrom) ?: [] as $rRow) {
			$rOut[(int) $rRow['user_id']][(string) $rRow['client_status']] = (int) $rRow['n'];
		}
		return $rOut;
	}

	/**
	 * Per line, the player families its sessions since $rFrom used, and how
	 * many sessions each.
	 *
	 * @return array<int, array<string, int>> line id => family => sessions
	 */
	public static function players(int $rFrom): array {
		$rOut = [];
		foreach (self::db()->fetchAll('SELECT `user_id`, `user_agent`, COUNT(*) AS `n` FROM `lines_activity` WHERE `date_start` >= ? AND `user_id` > 0 GROUP BY `user_id`, `user_agent`', $rFrom) ?: [] as $rRow) {
			$rFamily = LineAuditAnalyzer::playerFamily(self::raw((string) $rRow['user_agent']));
			$rOut[(int) $rRow['user_id']][$rFamily] = ($rOut[(int) $rRow['user_id']][$rFamily] ?? 0) + (int) $rRow['n'];
		}
		foreach ($rOut as &$rFamilies) {
			arsort($rFamilies);
		}
		return $rOut;
	}

	/**
	 * What the refused attempts of these lines asked for: per line, the
	 * streams (channels, films) and reasons, most refused first.
	 *
	 * @param list<int> $rUserIDs
	 * @return array<int, list<array{stream_id:int, name:string, type:int, status:string, n:int}>> line id => refusals by stream
	 */
	public static function refusalStreams(int $rFrom, array $rUserIDs): array {
		$rUserIDs = array_values(array_filter(array_unique(array_map('intval', $rUserIDs))));
		if ($rUserIDs === []) {
			return [];
		}
		$rOut = [];
		$rStreamIDs = [];
		foreach (array_chunk($rUserIDs, 500) as $rChunk) {
			$rRows = self::db()->fetchAll(
				'SELECT `user_id`, `stream_id`, `client_status`, COUNT(*) AS `n` FROM `lines_logs` WHERE `date` >= ? AND `user_id` IN (' . implode(',', $rChunk) . ')
				 GROUP BY `user_id`, `stream_id`, `client_status` ORDER BY `n` DESC',
				$rFrom
			) ?: [];
			foreach ($rRows as $rRow) {
				$rOut[(int) $rRow['user_id']][] = ['stream_id' => (int) $rRow['stream_id'], 'name' => '', 'type' => 0, 'status' => (string) $rRow['client_status'], 'n' => (int) $rRow['n']];
				$rStreamIDs[(int) $rRow['stream_id']] = true;
			}
		}
		unset($rStreamIDs[0]);
		$rNames = [];
		foreach (array_chunk(array_keys($rStreamIDs), 1000) as $rChunk) {
			foreach (self::db()->fetchAll('SELECT `id`, `type`, `stream_display_name` FROM `streams` WHERE `id` IN (' . implode(',', $rChunk) . ')') ?: [] as $rRow) {
				$rNames[(int) $rRow['id']] = [self::raw((string) $rRow['stream_display_name']), (int) $rRow['type']];
			}
		}
		foreach ($rOut as &$rList) {
			foreach ($rList as &$rItem) {
				[$rItem['name'], $rItem['type']] = $rNames[$rItem['stream_id']] ?? ['', 0];
			}
		}
		return $rOut;
	}

	/** @param list<array<string, mixed>> $rSamples rows for line_audit_samples */
	public static function saveSamples(array $rSamples): void {
		foreach ($rSamples as $r) {
			self::db()->query(
				'INSERT INTO `line_audit_samples` (`sampled_at`, `user_id`, `conns`, `max_conn`, `ips`, `countries`, `isps`, `uas`, `country_list`, `isp_list`, `ua_list`) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
				$r['sampled_at'], $r['user_id'], min(65535, $r['conns']), min(65535, $r['max_conn']), min(65535, $r['ips']), min(65535, $r['countries']),
				min(65535, $r['isps']), min(65535, $r['uas']), mb_substr($r['country_list'], 0, 64), mb_substr($r['isp_list'], 0, 255), mb_substr($r['ua_list'] ?? '', 0, 255)
			);
		}
	}

	public static function pruneSamples(int $rNow): void {
		self::db()->query('DELETE FROM `line_audit_samples` WHERE `sampled_at` < ?', $rNow - self::SAMPLE_TTL_SEC);
	}

	/**
	 * Per line, its samples since $rFrom.
	 *
	 * @return array<int, array{samples:int, multi_ip:int, multi_country:int, at_limit:int, max_ips:int, max_countries:int, max_uas:int, last_seen:int, country_list:string, isp_list:string, ua_list:string}>
	 */
	public static function sampleStats(int $rFrom): array {
		$rOut = [];
		$rRows = self::db()->fetchAll(
			'SELECT `user_id`, COUNT(*) AS `samples`, SUM(`ips` >= 2) AS `multi_ip`, SUM(`countries` >= 2) AS `multi_country`,
			        SUM(`max_conn` > 0 AND `conns` >= `max_conn`) AS `at_limit`, MAX(`ips`) AS `max_ips`, MAX(`countries`) AS `max_countries`, MAX(`uas`) AS `max_uas`, MAX(`sampled_at`) AS `last_seen`
			 FROM `line_audit_samples` WHERE `sampled_at` >= ? GROUP BY `user_id`',
			$rFrom
		) ?: [];
		foreach ($rRows as $rRow) {
			$rOut[(int) $rRow['user_id']] = [
				'samples' => (int) $rRow['samples'], 'multi_ip' => (int) $rRow['multi_ip'], 'multi_country' => (int) $rRow['multi_country'], 'at_limit' => (int) $rRow['at_limit'],
				'max_ips' => (int) $rRow['max_ips'], 'max_countries' => (int) $rRow['max_countries'], 'max_uas' => (int) $rRow['max_uas'], 'last_seen' => (int) $rRow['last_seen'],
				'country_list' => '', 'isp_list' => '', 'ua_list' => '',
			];
		}
		// The countries and ISPs of the widest sample of each line, for display.
		if ($rOut !== []) {
			$rWidest = self::db()->fetchAll(
				'SELECT s.`user_id`, s.`country_list`, s.`isp_list`, s.`ua_list` FROM `line_audit_samples` s
				 JOIN (SELECT `user_id`, MAX(`ips` * 1000 + `countries`) AS `w` FROM `line_audit_samples` WHERE `sampled_at` >= ? GROUP BY `user_id`) m
				   ON m.`user_id` = s.`user_id` AND s.`ips` * 1000 + s.`countries` = m.`w`
				 WHERE s.`sampled_at` >= ?',
				$rFrom, $rFrom
			) ?: [];
			foreach ($rWidest as $rRow) {
				$rOut[(int) $rRow['user_id']]['country_list'] = self::raw((string) $rRow['country_list']);
				$rOut[(int) $rRow['user_id']]['isp_list'] = self::raw((string) $rRow['isp_list']);
				$rOut[(int) $rRow['user_id']]['ua_list'] = self::raw((string) $rRow['ua_list']);
			}
		}
		return $rOut;
	}

	/** The DB layer HTML-escapes < and > on read; put them back (the page escapes what it prints). */
	public static function raw(string $rValue): string {
		return str_replace(['&lt;', '&gt;'], ['<', '>'], $rValue);
	}
}
