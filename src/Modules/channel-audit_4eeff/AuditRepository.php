<?php

namespace XcVm\Module\ChannelAudit;

use XcVm\Infrastructure\Database\DatabaseAware;

/**
 * Every query the module makes, on its own three tables plus a read of the
 * created channels in `streams`.
 */
final class AuditRepository {
	use DatabaseAware;

	/** Audits kept; older ones are dropped when a new one starts. */
	public const KEEP_JOBS = 100;

	// ---- standards ----

	/** @return list<array{id:int, name:string, spec:array<string, mixed>}> */
	public static function standards(): array {
		$rRows = self::db()->fetchAll('SELECT `id`, `name`, `spec` FROM `channel_audit_standards` ORDER BY `name` ASC') ?: [];
		return array_map(static fn (array $r): array => ['id' => (int) $r['id'], 'name' => (string) $r['name'], 'spec' => self::spec((string) $r['spec'])], $rRows);
	}

	/** @return array{id:int, name:string, spec:array<string, mixed>}|null */
	public static function standard(int $rID): ?array {
		$rRow = self::db()->fetchOne('SELECT `id`, `name`, `spec` FROM `channel_audit_standards` WHERE `id` = ?', $rID);
		return $rRow ? ['id' => (int) $rRow['id'], 'name' => (string) $rRow['name'], 'spec' => self::spec((string) $rRow['spec'])] : null;
	}

	/** @param array<string, mixed> $rSpec @return int the standard's id */
	public static function saveStandard(int $rID, string $rName, array $rSpec): int {
		$rNow = time();
		if ($rID > 0) {
			self::db()->query('UPDATE `channel_audit_standards` SET `name` = ?, `spec` = ?, `updated_at` = ? WHERE `id` = ?', $rName, json_encode($rSpec), $rNow, $rID);
			return $rID;
		}
		return (int) self::db()->insert('channel_audit_standards', ['name' => $rName, 'spec' => json_encode($rSpec), 'created_at' => $rNow, 'updated_at' => $rNow]);
	}

	public static function deleteStandard(int $rID): void {
		self::db()->query('DELETE FROM `channel_audit_standards` WHERE `id` = ?', $rID);
	}

	// ---- jobs ----

	/** @param array<string, mixed> $rJob */
	public static function createJob(array $rJob): int {
		self::prune();
		return (int) self::db()->insert('channel_audit_jobs', $rJob + ['status' => 'queued', 'created_at' => time()]);
	}

	/** @return array<string, mixed>|null */
	public static function job(int $rID): ?array {
		$rRow = self::db()->fetchOne('SELECT * FROM `channel_audit_jobs` WHERE `id` = ?', $rID);
		return $rRow ?: null;
	}

	/** @return list<array<string, mixed>> newest first */
	public static function jobs(int $rLimit = 30): array {
		return self::db()->fetchAll('SELECT `id`, `kind`, `server_id`, `target`, `stream_id`, `label`, `standard_name`, `deep`, `status`, `total`, `done`, `passed`, `warned`, `failed`, `error`, `created_at`, `started_at`, `finished_at`, `pid` FROM `channel_audit_jobs` ORDER BY `id` DESC LIMIT ' . max(1, min(200, $rLimit))) ?: [];
	}

	/** @param array<string, mixed> $rFields */
	public static function updateJob(int $rID, array $rFields): void {
		$rSet = [];
		$rValues = [];
		foreach ($rFields as $rKey => $rValue) {
			$rSet[] = '`' . preg_replace('/[^a-z_]/', '', (string) $rKey) . '` = ?';
			$rValues[] = $rValue;
		}
		$rValues[] = $rID;
		self::db()->query('UPDATE `channel_audit_jobs` SET ' . implode(', ', $rSet) . ' WHERE `id` = ?', ...$rValues);
	}

	public static function jobStatus(int $rID): ?string {
		$rValue = self::db()->fetchValue('SELECT `status` FROM `channel_audit_jobs` WHERE `id` = ?', $rID);
		return $rValue === false || $rValue === null ? null : (string) $rValue;
	}

	public static function deleteJob(int $rID): void {
		self::db()->query('DELETE FROM `channel_audit_items` WHERE `job_id` = ?', $rID);
		self::db()->query('DELETE FROM `channel_audit_jobs` WHERE `id` = ?', $rID);
	}

	/** Drop all but the newest KEEP_JOBS audits. */
	public static function prune(): void {
		$rOld = self::db()->fetchColumn('SELECT `id` FROM `channel_audit_jobs` ORDER BY `id` DESC LIMIT 1000 OFFSET ' . self::KEEP_JOBS) ?: [];
		foreach ($rOld as $rID) {
			self::deleteJob((int) $rID);
		}
	}

	// ---- items ----

	/** @param list<array{source:string, name:string}> $rItems */
	public static function addItems(int $rJobID, array $rItems): void {
		foreach ($rItems as $i => $rItem) {
			self::db()->insert('channel_audit_items', ['job_id' => $rJobID, 'position' => $i, 'source' => mb_substr($rItem['source'], 0, 2048), 'name' => mb_substr($rItem['name'], 0, 255)]);
		}
	}

	/** @return list<array<string, mixed>> */
	public static function items(int $rJobID): array {
		return self::db()->fetchAll('SELECT `id`, `position`, `source`, `name`, `status`, `duration`, `report` FROM `channel_audit_items` WHERE `job_id` = ? ORDER BY `position` ASC', $rJobID) ?: [];
	}

	/** @param array<string, mixed> $rReport */
	public static function saveItem(int $rItemID, string $rStatus, ?float $rDuration, array $rReport): void {
		self::db()->query(
			'UPDATE `channel_audit_items` SET `status` = ?, `duration` = ?, `report` = ?, `checked_at` = ? WHERE `id` = ?',
			$rStatus,
			$rDuration,
			json_encode($rReport, JSON_PARTIAL_OUTPUT_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE),
			time(),
			$rItemID
		);
	}

	public static function markItem(int $rItemID, string $rStatus): void {
		self::db()->query('UPDATE `channel_audit_items` SET `status` = ? WHERE `id` = ?', $rStatus, $rItemID);
	}

	// ---- created channels ----

	/** @return list<array{id:int, name:string}> */
	public static function createdChannels(): array {
		$rRows = self::db()->fetchAll('SELECT `id`, `stream_display_name` FROM `streams` WHERE `type` = 3 ORDER BY `stream_display_name` ASC') ?: [];
		return array_map(static fn (array $r): array => ['id' => (int) $r['id'], 'name' => (string) $r['stream_display_name']], $rRows);
	}

	/** @return array{name:string, sources:list<string>}|null */
	public static function createdChannel(int $rStreamID): ?array {
		$rRow = self::db()->fetchOne('SELECT `stream_display_name`, `stream_source` FROM `streams` WHERE `id` = ? AND `type` = 3', $rStreamID);
		if (!$rRow) {
			return null;
		}
		$rSources = json_decode((string) $rRow['stream_source'], true);
		return ['name' => (string) $rRow['stream_display_name'], 'sources' => is_array($rSources) ? array_values(array_filter(array_map('strval', $rSources), static fn (string $s): bool => trim($s) !== '')) : []];
	}

	/** @return array<string, mixed> */
	private static function spec(string $rJson): array {
		$rSpec = json_decode($rJson, true);
		return is_array($rSpec) ? $rSpec + AuditStandard::DEFAULTS : AuditStandard::DEFAULTS;
	}
}
