<?php

namespace XcVm\Module\SourceHealth;

use XcVm\Infrastructure\Database\DatabaseAware;

/**
 * Every query the module makes. Reads core tables (`streams_logs`,
 * `streams_servers`) and owns one table of its own, `source_health_profiles`
 * (one row per stream × source × server: the source's last seen fingerprint).
 *
 * Sources are keyed by md5 of the source line, the same key core uses for its
 * per-source probe cache (StreamProcess::deleteCache()).
 */
final class SourceHealthRepository {
	use DatabaseAware;

	/** Profiles not refreshed for this long are pruned (a source taken off a stream). */
	public const PROFILE_TTL_SEC = 30 * 86400;

	/**
	 * The stream's log since $rFrom, plus — per server — the last event before
	 * it when that event opened a run (the run on air as the window opened).
	 *
	 * @return list<array{server_id:int, action:string, source:?string, date:int}>
	 */
	public static function events(int $rStreamID, int $rFrom): array {
		$rSeed = self::db()->fetchAll(
			'SELECT l.`server_id`, l.`action`, l.`source`, l.`date` FROM `streams_logs` l '
			. 'INNER JOIN (SELECT `server_id`, MAX(`id`) AS `id` FROM `streams_logs` WHERE `stream_id` = ? AND `date` < ? GROUP BY `server_id`) m ON m.`id` = l.`id` '
			. "WHERE l.`action` IN ('STREAM_START', 'STREAM_RESTART')",
			$rStreamID,
			$rFrom
		) ?: [];
		$rWindow = self::db()->fetchAll(
			'SELECT `server_id`, `action`, `source`, `date` FROM `streams_logs` WHERE `stream_id` = ? AND `date` >= ? ORDER BY `date` ASC, `id` ASC',
			$rStreamID,
			$rFrom
		) ?: [];
		usort($rSeed, static fn (array $a, array $b): int => (int) $a['date'] <=> (int) $b['date']);
		return array_merge($rSeed, $rWindow);
	}

	/**
	 * Servers whose encoder for this stream is up right now, and the source it runs.
	 *
	 * @return array<int, string> server id => source
	 */
	public static function running(int $rStreamID): array {
		$rRunning = [];
		foreach (self::onAirRows($rStreamID) as $rRow) {
			$rRunning[$rRow['server_id']] = $rRow['source'];
		}
		return $rRunning;
	}

	/**
	 * Every encoder up right now, with the source it runs resolved to the URL the
	 * stream lists (SourceProber::resolveOnAir): a load balancer reports it with
	 * the account blanked, and that must still count against the account.
	 *
	 * @return list<array{stream_id:int, server_id:int, source:string}>
	 */
	public static function onAirRows(?int $rStreamID = null): array {
		$rRows = self::db()->fetchAll(
			'SELECT ss.`stream_id`, ss.`server_id`, ss.`current_source`, s.`stream_source` FROM `streams_servers` ss JOIN `streams` s ON s.`id` = ss.`stream_id` '
			. "WHERE ss.`pid` > 0 AND ss.`stream_status` = 0 AND ss.`current_source` IS NOT NULL AND ss.`current_source` <> ''"
			. ($rStreamID === null ? '' : ' AND ss.`stream_id` = ' . (int) $rStreamID)
		) ?: [];
		$rOut = [];
		foreach ($rRows as $rRow) {
			$rSources = array_map(static fn ($v): string => is_string($v) ? trim($v) : '', (array) json_decode(self::raw((string) $rRow['stream_source']), true));
			$rOut[] = ['stream_id' => (int) $rRow['stream_id'], 'server_id' => (int) $rRow['server_id'], 'source' => SourceProber::resolveOnAir(self::raw((string) $rRow['current_source']), $rSources)];
		}
		return $rOut;
	}

	/**
	 * The newest fingerprint seen for each source of a stream, on any server.
	 *
	 * @return array<string, array<string, mixed>> md5(source) => profile row
	 */
	public static function profiles(int $rStreamID): array {
		$rRows = self::db()->fetchAll(
			'SELECT * FROM `source_health_profiles` WHERE `stream_id` = ? ORDER BY `last_seen` ASC',
			$rStreamID
		) ?: [];
		$rByHash = [];
		foreach ($rRows as $rRow) {
			$rByHash[(string) $rRow['source_hash']] = $rRow; // newest wins
		}
		return $rByHash;
	}

	/**
	 * Every encoder running a source of its own right now (not a child relaying
	 * its parent server), with what it reported about its input.
	 *
	 * @return list<array{stream_id:int, server_id:int, current_source:string, stream_info:?string, bitrate:?int}>
	 */
	public static function runningEncoders(): array {
		return self::db()->fetchAll(
			'SELECT `stream_id`, `server_id`, `current_source`, `stream_info`, `bitrate` FROM `streams_servers` '
			. "WHERE `pid` > 0 AND `stream_status` = 0 AND (`parent_id` IS NULL OR `parent_id` = 0) "
			. "AND `stream_info` IS NOT NULL AND `stream_info` <> '' AND `current_source` IS NOT NULL AND `current_source` <> ''"
		) ?: [];
	}

	/**
	 * Record what a source looked like when it was last on air.
	 *
	 * @param array<string, mixed> $rFp SourceFingerprint::fromStreamInfo() output
	 */
	public static function saveProfile(int $rStreamID, int $rServerID, string $rSource, array $rFp, ?int $rBitrate, int $rNow): void {
		self::db()->query(
			'INSERT INTO `source_health_profiles` (`stream_id`, `source_hash`, `server_id`, `fingerprint`, `label`, `video_codec`, `video_profile`, `width`, `height`, `fps`, `scan`, `audio_codec`, `audio_rate`, `audio_channels`, `bitrate`, `first_seen`, `last_seen`) '
			. 'VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?) '
			. 'ON DUPLICATE KEY UPDATE `fingerprint` = VALUES(`fingerprint`), `label` = VALUES(`label`), `video_codec` = VALUES(`video_codec`), '
			. '`video_profile` = VALUES(`video_profile`), `width` = VALUES(`width`), `height` = VALUES(`height`), `fps` = VALUES(`fps`), `scan` = VALUES(`scan`), '
			. '`audio_codec` = VALUES(`audio_codec`), `audio_rate` = VALUES(`audio_rate`), `audio_channels` = VALUES(`audio_channels`), '
			. '`bitrate` = VALUES(`bitrate`), `last_seen` = VALUES(`last_seen`)',
			$rStreamID,
			md5($rSource),
			$rServerID,
			mb_substr((string) $rFp['key'], 0, 190),
			mb_substr((string) $rFp['label'], 0, 190),
			mb_substr((string) $rFp['video_codec'], 0, 32),
			mb_substr((string) $rFp['video_profile'], 0, 64),
			(int) $rFp['width'],
			(int) $rFp['height'],
			(float) $rFp['fps'],
			(string) $rFp['scan'],
			mb_substr((string) $rFp['audio_codec'], 0, 32),
			(int) $rFp['audio_rate'],
			(int) $rFp['audio_channels'],
			$rBitrate,
			$rNow,
			$rNow
		);
	}

	public static function pruneProfiles(int $rNow): void {
		self::db()->query('DELETE FROM `source_health_profiles` WHERE `last_seen` < ?', $rNow - self::PROFILE_TTL_SEC);
	}

	/** @param list<int> $rStreamIDs */
	public static function deleteForStreams(array $rStreamIDs): void {
		$rIDs = array_values(array_unique(array_filter(array_map('intval', $rStreamIDs), static fn (int $id): bool => $id > 0)));
		foreach (array_chunk($rIDs, 500) as $rChunk) {
			foreach (['source_health_profiles', 'source_health_probes', 'source_health_probe_streams', 'source_health_hosts'] as $rTable) {
				self::db()->query('DELETE FROM `' . $rTable . '` WHERE `stream_id` IN (' . implode(',', $rChunk) . ')');
			}
		}
	}

	/** @return array{id:int, stream_display_name:string, stream_source:string}|null a live stream */
	public static function stream(int $rStreamID): ?array {
		$rRow = self::db()->fetchOne('SELECT `id`, `stream_display_name`, `stream_source` FROM `streams` WHERE `id` = ? AND `type` = 1', $rStreamID);
		return $rRow ?: null;
	}

	/**
	 * The stream's error lines (Stream Error Logs) since $rFrom, oldest first,
	 * at most the newest $rLimit.
	 *
	 * @return list<array{server_id:int, date:int, error:string}>
	 */
	public static function errors(int $rStreamID, int $rFrom, int $rLimit = 20000): array {
		$rRows = self::db()->fetchAll('SELECT `server_id`, `date`, `error` FROM `streams_errors` WHERE `stream_id` = ? AND `date` >= ? ORDER BY `id` DESC LIMIT ' . max(1, $rLimit), $rStreamID, $rFrom) ?: [];
		return array_reverse($rRows);
	}

	/** The DB layer HTML-escapes < and > in every value it reads; text shown again must not be escaped twice. */
	public static function raw(string $rValue): string {
		return str_replace(['&lt;', '&gt;'], ['<', '>'], $rValue);
	}

	// ---- active probes ----

	/** Probe results are kept this long (the report window). */
	public const PROBE_TTL_SEC = 7 * 86400;

	public static function probeEnabled(int $rStreamID): bool {
		return (bool) self::db()->fetchValue('SELECT 1 FROM `source_health_probe_streams` WHERE `stream_id` = ?', $rStreamID);
	}

	public static function setProbe(int $rStreamID, bool $rEnabled, ?int $rUserID): void {
		if ($rEnabled) {
			self::db()->query('INSERT IGNORE INTO `source_health_probe_streams` (`stream_id`, `enabled_at`, `enabled_by`) VALUES (?, ?, ?)', $rStreamID, time(), $rUserID);
		} else {
			self::db()->query('DELETE FROM `source_health_probe_streams` WHERE `stream_id` = ?', $rStreamID);
		}
	}

	/** @return list<int> streams whose sources are probed */
	public static function probeStreams(): array {
		return array_map('intval', self::db()->fetchColumn('SELECT `stream_id` FROM `source_health_probe_streams`') ?: []);
	}

	/**
	 * Every source of the given live streams.
	 *
	 * @param list<int> $rStreamIDs
	 * @return list<array{stream_id:int, source:string}>
	 */
	public static function probeCandidates(array $rStreamIDs): array {
		if ($rStreamIDs === []) {
			return [];
		}
		$rRows = self::db()->fetchAll('SELECT `id`, `stream_source` FROM `streams` WHERE `type` = 1 AND `id` IN (' . implode(',', array_map('intval', $rStreamIDs)) . ')') ?: [];
		$rOut = [];
		foreach ($rRows as $rRow) {
			foreach ((array) json_decode((string) $rRow['stream_source'], true) as $rSource) {
				if (is_string($rSource) && trim($rSource) !== '') {
					$rOut[] = ['stream_id' => (int) $rRow['id'], 'source' => trim($rSource)];
				}
			}
		}
		return $rOut;
	}

	/**
	 * @param list<int> $rStreamIDs
	 * @return array<string, int> "streamId:md5" => last probe or skip time
	 */
	public static function lastProbes(array $rStreamIDs): array {
		if ($rStreamIDs === []) {
			return [];
		}
		$rRows = self::db()->fetchAll('SELECT `stream_id`, `source_hash`, MAX(`checked_at`) AS `last` FROM `source_health_probes` WHERE `stream_id` IN (' . implode(',', array_map('intval', $rStreamIDs)) . ') GROUP BY `stream_id`, `source_hash`') ?: [];
		$rLast = [];
		foreach ($rRows as $rRow) {
			$rLast[$rRow['stream_id'] . ':' . $rRow['source_hash']] = (int) $rRow['last'];
		}
		return $rLast;
	}

	/**
	 * Live connections per provider account right now: every encoder on air
	 * on any server counts (one channel on two servers is two connections).
	 *
	 * @return array<string, int> SourceProber::accountKey() => connections
	 */
	public static function accountConnections(): array {
		$rCounts = [];
		foreach (self::onAirRows() as $rRow) {
			$rKey = SourceProber::accountKey($rRow['source']);
			if ($rKey !== null) {
				$rCounts[$rKey] = ($rCounts[$rKey] ?? 0) + 1;
			}
		}
		return $rCounts;
	}

	/**
	 * Every source of every live stream, once each.
	 *
	 * @return array<string, array{source:string, stream_id:int}> md5(source) => the source and one stream that has it
	 */
	public static function allLiveSources(): array {
		$rOut = [];
		foreach (self::liveStreams() as $rRow) {
			foreach ($rRow['sources'] as $rSource) {
				$rOut[md5($rSource)] ??= ['source' => $rSource, 'stream_id' => (int) $rRow['id']];
			}
		}
		return $rOut;
	}

	/** @return list<array{id:int, name:string, sources:list<string>}> every live stream with its sources, in list order */
	public static function liveStreams(): array {
		$rOut = [];
		foreach (self::db()->fetchAll('SELECT `id`, `stream_display_name`, `stream_source` FROM `streams` WHERE `type` = 1 ORDER BY `id`') ?: [] as $rRow) {
			$rSources = [];
			foreach ((array) json_decode(self::raw((string) $rRow['stream_source']), true) as $rSource) {
				$rSources[] = is_string($rSource) ? trim($rSource) : '';
			}
			$rOut[] = ['id' => (int) $rRow['id'], 'name' => self::raw((string) $rRow['stream_display_name']), 'sources' => $rSources];
		}
		return $rOut;
	}

	/** @return array<string, array<string, mixed>> md5(source) => last audit verdict */
	public static function auditState(): array {
		$rOut = [];
		foreach (self::db()->fetchAll('SELECT * FROM `source_health_audit`') ?: [] as $rRow) {
			$rRow['reason'] = self::raw((string) $rRow['reason']);
			$rOut[(string) $rRow['source_hash']] = $rRow;
		}
		return $rOut;
	}

	/** @param array{status:string, kind:?string, reason:string, fails:int, first_fail:int, last_ok:int} $rVerdict */
	public static function saveAudit(string $rHash, string $rHost, array $rVerdict, ?int $rMs, int $rNow): void {
		self::db()->query(
			'REPLACE INTO `source_health_audit` (`source_hash`, `host`, `checked_at`, `status`, `kind`, `reason`, `fails`, `first_fail`, `last_ok`, `ms`) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
			$rHash, mb_substr($rHost, 0, 190), $rNow, $rVerdict['status'], $rVerdict['kind'], mb_substr($rVerdict['reason'], 0, 250),
			min(65535, $rVerdict['fails']), $rVerdict['first_fail'], $rVerdict['last_ok'], $rMs
		);
	}

	/** @return array<string, array{api:bool, auth:?bool, status:string, max:?int, active:?int, exp:?int, checked_at:int, host:string}> md5(account key) => what the provider said */
	public static function accounts(): array {
		$rOut = [];
		foreach (self::db()->fetchAll('SELECT * FROM `source_health_accounts`') ?: [] as $rRow) {
			$rOut[(string) $rRow['account_hash']] = [
				'api' => (bool) $rRow['api'], 'auth' => $rRow['auth'] === null ? null : (bool) $rRow['auth'], 'status' => self::raw((string) $rRow['status']),
				'max' => $rRow['max_conn'] === null ? null : (int) $rRow['max_conn'], 'active' => $rRow['active_cons'] === null ? null : (int) $rRow['active_cons'],
				'exp' => $rRow['exp_date'] === null ? null : (int) $rRow['exp_date'], 'checked_at' => (int) $rRow['checked_at'], 'host' => self::raw((string) $rRow['host']),
			];
		}
		return $rOut;
	}

	/** @param array{api:bool, auth:?bool, status:string, max:?int, active:?int, exp:?int} $rInfo */
	public static function saveAccount(string $rHash, string $rHost, array $rInfo, int $rNow): void {
		self::db()->query(
			'REPLACE INTO `source_health_accounts` (`account_hash`, `host`, `checked_at`, `api`, `auth`, `status`, `max_conn`, `active_cons`, `exp_date`) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)',
			$rHash, mb_substr($rHost, 0, 190), $rNow, $rInfo['api'] ? 1 : 0, $rInfo['auth'] === null ? null : ($rInfo['auth'] ? 1 : 0),
			$rInfo['status'], $rInfo['max'], $rInfo['active'], $rInfo['exp']
		);
	}

	public static function pruneAccounts(int $rNow): void {
		self::db()->query('DELETE FROM `source_health_accounts` WHERE `checked_at` < ?', $rNow - self::PROBE_TTL_SEC);
	}

	/** Put sources at the front of the audit queue (checked within the next runs, provider caps still apply). @param list<string> $rHashes */
	public static function requeueAudit(array $rHashes): int {
		$n = 0;
		foreach (array_chunk(array_values(array_unique(array_filter($rHashes, static fn ($h): bool => is_string($h) && preg_match('/^[0-9a-f]{32}$/', $h) === 1))), 500) as $rChunk) {
			self::db()->query('UPDATE `source_health_audit` SET `checked_at` = 0 WHERE `source_hash` IN (' . implode(',', array_fill(0, count($rChunk), '?')) . ')', ...$rChunk);
			$n += count($rChunk);
		}
		return $n;
	}

	/** Forget sources no live stream has any more. @param list<string> $rKeep md5 of every current source */
	public static function pruneAudit(array $rKeep): void {
		$rKeep = array_flip($rKeep);
		$rGone = array_values(array_filter(self::db()->fetchColumn('SELECT `source_hash` FROM `source_health_audit`') ?: [], static fn ($h): bool => !isset($rKeep[(string) $h])));
		foreach (array_chunk($rGone, 500) as $rChunk) {
			self::db()->query('DELETE FROM `source_health_audit` WHERE `source_hash` IN (' . implode(',', array_fill(0, count($rChunk), '?')) . ')', ...$rChunk);
		}
	}

	/** @return list<string> every source on air on the panel right now (all streams, all servers) */
	public static function onAirSources(): array {
		return array_values(array_unique(array_column(self::onAirRows(), 'source')));
	}

	/**
	 * The stream's fetch identity, as the panel's own puller uses it.
	 *
	 * @return array{user_agent?:string, http_proxy?:string, cookies?:string, headers?:string}
	 */
	public static function fetchOptions(int $rStreamID): array {
		try {
			$rArgs = \XcVm\Domain\Stream\StreamSource::arguments($rStreamID, true);
			$rSource = \XcVm\Streaming\Fanout\FanoutClient::buildSource([], $rArgs);
		} catch (\Throwable $e) {
			return [];
		}
		return array_filter([
			'user_agent' => (string) $rSource['ua'],
			'http_proxy' => $rSource['proxy'] !== '' ? 'http://' . $rSource['proxy'] : '',
			'cookies'    => (string) $rSource['cookie'],
			'headers'    => (string) ($rArgs['headers']['value'] ?? ''),
		], static fn (string $v): bool => $v !== '');
	}

	/** @param array{ok: bool, open_ms: ?int, speed: ?float, label: string, error: string} $rResult */
	public static function saveProbe(int $rStreamID, string $rSource, array $rResult, int $rNow): void {
		self::db()->query(
			'INSERT INTO `source_health_probes` (`stream_id`, `source_hash`, `checked_at`, `kind`, `ok`, `open_ms`, `speed`, `label`, `error`) VALUES (?, ?, ?, \'probe\', ?, ?, ?, ?, ?)',
			$rStreamID, md5($rSource), $rNow, $rResult['ok'] ? 1 : 0, $rResult['open_ms'], $rResult['speed'],
			mb_substr($rResult['label'], 0, 190), mb_substr($rResult['error'], 0, 250)
		);
	}

	/** A probe that was due but not made, and why: shown in the tab, counted as no result. */
	public static function saveSkip(int $rStreamID, string $rSource, string $rReason, int $rNow): void {
		self::db()->query(
			'INSERT INTO `source_health_probes` (`stream_id`, `source_hash`, `checked_at`, `kind`, `ok`, `error`) VALUES (?, ?, ?, \'skip\', 0, ?)',
			$rStreamID, md5($rSource), $rNow, mb_substr($rReason, 0, 250)
		);
	}

	/**
	 * Probe results per source since $rFrom.
	 *
	 * @return array<string, array{ok:int, total:int, skipped:int, skip_reason:string, open_ms:?int, speed:?float, last_at:int, last_ok:bool, last_error:string, last_kind:string, label:string}>
	 */
	/**
	 * A probe can only run while the source is off air, and a source that is
	 * normally on air is off air mostly because it has just dropped: its
	 * probes are taken in its worst minutes. Probes within this many seconds
	 * after a drop of that source are counted apart (after_drop, after_drop_ok).
	 */
	public const AFTER_DROP_SEC = 900;

	/** @param array<string, list<int>> $rDrops md5(source) => times it dropped or failed to start */
	public static function probeStats(int $rStreamID, int $rFrom, array $rDrops = []): array {
		$rRows = self::db()->fetchAll('SELECT `source_hash`, `checked_at`, `kind`, `ok`, `open_ms`, `speed`, `label`, `error` FROM `source_health_probes` WHERE `stream_id` = ? AND `checked_at` >= ? ORDER BY `checked_at` ASC', $rStreamID, $rFrom) ?: [];
		$rStats = [];
		$rOpen = [];
		foreach ($rRows as $rRow) {
			$rHash = (string) $rRow['source_hash'];
			$rStats[$rHash] ??= ['ok' => 0, 'total' => 0, 'skipped' => 0, 'skip_reason' => '', 'open_ms' => null, 'speed' => null, 'last_at' => 0, 'last_ok' => false, 'last_error' => '', 'last_kind' => '', 'label' => '', 'after_drop' => 0, 'after_drop_ok' => 0];
			$rStats[$rHash]['last_at'] = (int) $rRow['checked_at'];
			$rStats[$rHash]['last_kind'] = (string) $rRow['kind'];
			$rStats[$rHash]['last_error'] = self::raw((string) $rRow['error']);
			if ($rRow['kind'] !== 'probe') {
				$rStats[$rHash]['skipped']++;
				$rStats[$rHash]['skip_reason'] = self::raw((string) $rRow['error']);
				continue;
			}
			$rStats[$rHash]['total']++;
			$rStats[$rHash]['last_ok'] = (bool) $rRow['ok'];
			$rAt = (int) $rRow['checked_at'];
			foreach ($rDrops[$rHash] ?? [] as $rDrop) {
				if ($rDrop <= $rAt && $rAt - $rDrop <= self::AFTER_DROP_SEC) {
					$rStats[$rHash]['after_drop']++;
					$rStats[$rHash]['after_drop_ok'] += (int) $rRow['ok'];
					break;
				}
			}
			if ((int) $rRow['ok'] === 1) {
				$rStats[$rHash]['ok']++;
				$rOpen[$rHash][] = (int) $rRow['open_ms'];
				$rStats[$rHash]['speed'] = $rRow['speed'] !== null ? (float) $rRow['speed'] : null;
			}
			if ((string) $rRow['label'] !== '') {
				$rStats[$rHash]['label'] = self::raw((string) $rRow['label']);
			}
		}
		foreach ($rOpen as $rHash => $rTimes) {
			sort($rTimes);
			$rStats[$rHash]['open_ms'] = $rTimes[intdiv(count($rTimes), 2)];
		}
		return $rStats;
	}

	/** @param list<string> $rHosts redirect targets a probe of this source saw */
	public static function saveHosts(int $rStreamID, string $rSource, array $rHosts, int $rNow): void {
		foreach (array_slice($rHosts, 0, 5) as $rHost) {
			self::db()->query(
				'INSERT INTO `source_health_hosts` (`stream_id`, `source_hash`, `host`, `last_seen`) VALUES (?, ?, ?, ?) ON DUPLICATE KEY UPDATE `last_seen` = VALUES(`last_seen`)',
				$rStreamID, md5($rSource), mb_substr(strtolower($rHost), 0, 190), $rNow
			);
		}
	}

	/** @return array<string, list<string>> md5(source) => hosts it redirected to (seen in the report window) */
	public static function hosts(int $rStreamID, int $rFrom): array {
		$rOut = [];
		foreach (self::db()->fetchAll('SELECT `source_hash`, `host` FROM `source_health_hosts` WHERE `stream_id` = ? AND `last_seen` >= ? ORDER BY `last_seen` DESC', $rStreamID, $rFrom) ?: [] as $rRow) {
			$rOut[(string) $rRow['source_hash']][] = self::raw((string) $rRow['host']);
		}
		return $rOut;
	}

	public static function pruneProbes(int $rNow): void {
		self::db()->query('DELETE FROM `source_health_probes` WHERE `checked_at` < ?', $rNow - self::PROBE_TTL_SEC);
		self::db()->query('DELETE FROM `source_health_hosts` WHERE `last_seen` < ?', $rNow - self::PROBE_TTL_SEC);
	}
}
