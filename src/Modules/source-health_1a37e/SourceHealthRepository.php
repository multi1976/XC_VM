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
		$rRows = self::db()->fetchAll(
			'SELECT `server_id`, `current_source` FROM `streams_servers` WHERE `stream_id` = ? AND `pid` > 0 AND `stream_status` = 0 AND `current_source` IS NOT NULL',
			$rStreamID
		) ?: [];
		$rRunning = [];
		foreach ($rRows as $rRow) {
			$rRunning[(int) $rRow['server_id']] = trim((string) $rRow['current_source']);
		}
		return $rRunning;
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
			self::db()->query('DELETE FROM `source_health_profiles` WHERE `stream_id` IN (' . implode(',', $rChunk) . ')');
		}
	}
}
