<?php

namespace XcVm\Module\SourceHealth;

use XcVm\Core\Events\EventDispatcher;
use XcVm\Core\Events\Stream\StreamsChangedEvent;
use XcVm\Domain\Stream\StreamProcess;
use XcVm\Infrastructure\Database\DatabaseAware;

/**
 * The only part of the module that changes streams: take sources that are off
 * out of a stream's list, or move them to its end, and put them back.
 *
 *   - delete:   only a source the audit calls dead, on a check under 24 h old;
 *   - move_end: a source that is dead or down (it stays, and is still checked,
 *               but the panel tries the working ones first);
 *   - never the source on air, and a delete never leaves a stream without
 *     sources;
 *   - add:      a channel of a Stream Provider picked in Find sources
 *               (SourceFinder), its URL written here from the provider, never
 *               taken from the page; first in the list when nothing in the
 *               stream works, else last;
 *   - every change is logged with the source and its place, and can be undone
 *     for UNDO_SEC.
 *
 * plan() is PURE; apply() and undo() write. A write sets streams.stream_source
 * and dispatches StreamsChangedEvent, as the panel's own stream save does, so
 * the nodes pick the new list up.
 */
final class SourceActions {
	use DatabaseAware;

	public const UNDO_SEC = 7 * 86400;
	public const FRESH_SEC = 86400;
	public const ACTIONS = ['delete', 'move_end'];

	/**
	 * What an action would do to each stream, and what it refuses.
	 *
	 * @param list<array{id:int, name:string, sources:list<string>}> $rStreams the streams concerned, as stored now
	 * @param array<int, array<string, true>> $rWanted stream id => md5(source) => true
	 * @param array<string, array{status:string, checked_at:int}> $rState audit state by md5(source)
	 * @param list<string> $rOnAir every source on air
	 * @return array{changes: list<array{stream_id:int, name:string, before:list<string>, after:list<string>, moved:list<array{source:string, position:int}>}>,
	 *               refused: list<array{stream_id:int, name:string, source:string, why:string}>}
	 */
	public static function plan(string $rAction, array $rStreams, array $rWanted, array $rState, array $rOnAir, int $rNow): array {
		$rLive = array_flip(array_map('trim', $rOnAir));
		$rChanges = [];
		$rRefused = [];
		foreach ($rStreams as $rStream) {
			$rWant = $rWanted[$rStream['id']] ?? [];
			if ($rWant === []) {
				continue;
			}
			$rKeep = [];
			$rTaken = [];
			$rSeen = [];
			foreach ($rStream['sources'] as $i => $rSource) {
				$rHash = md5($rSource);
				if (!isset($rWant[$rHash])) {
					$rKeep[] = $rSource;
					continue;
				}
				$rSeen[$rHash] = true;
				$rAudit = $rState[$rHash] ?? null;
				$rWhy = match (true) {
					isset($rLive[$rSource])                                                                   => 'on air',
					$rAudit === null                                                                          => 'not checked yet',
					$rAction === 'delete' && $rAudit['status'] !== 'dead'                                      => 'not dead (' . $rAudit['status'] . '): only a dead source can be deleted',
					$rAction === 'delete' && $rNow - (int) $rAudit['checked_at'] > self::FRESH_SEC            => 'its last check is over 24 h old: recheck it first',
					$rAction === 'move_end' && !in_array($rAudit['status'], ['dead', 'down'], true)            => 'it is ' . $rAudit['status'] . ', not off',
					default                                                                                   => null,
				};
				if ($rWhy !== null) {
					$rRefused[] = ['stream_id' => $rStream['id'], 'name' => $rStream['name'], 'source' => $rSource, 'why' => $rWhy];
					$rKeep[] = $rSource;
				} else {
					$rTaken[] = ['source' => $rSource, 'position' => $i];
				}
			}
			foreach (array_keys(array_diff_key($rWant, $rSeen)) as $rHash) {
				$rRefused[] = ['stream_id' => $rStream['id'], 'name' => $rStream['name'], 'source' => '', 'why' => 'no longer in this stream'];
			}
			if ($rTaken === []) {
				continue;
			}
			if ($rAction === 'delete' && $rKeep === []) {
				foreach ($rTaken as $rItem) {
					$rRefused[] = ['stream_id' => $rStream['id'], 'name' => $rStream['name'], 'source' => $rItem['source'], 'why' => 'the stream would have no source left'];
				}
				continue;
			}
			$rAfter = $rAction === 'delete' ? $rKeep : array_merge($rKeep, array_column($rTaken, 'source'));
			if ($rAfter === array_values($rStream['sources'])) {
				foreach ($rTaken as $rItem) {
					$rRefused[] = ['stream_id' => $rStream['id'], 'name' => $rStream['name'], 'source' => $rItem['source'], 'why' => 'already at the end'];
				}
				continue;
			}
			$rChanges[] = ['stream_id' => $rStream['id'], 'name' => $rStream['name'], 'before' => array_values($rStream['sources']), 'after' => $rAfter, 'moved' => $rTaken];
		}
		return ['changes' => $rChanges, 'refused' => $rRefused];
	}

	/**
	 * The streams an admin's selection touches, read fresh, and the selection
	 * keyed for plan(). A provider host stands for every source on it that is
	 * off (dead for delete; dead or down for move_end).
	 *
	 * @param list<array{0:int, 1:string}> $rItems [stream id, md5(source)]
	 * @return array{0: list<array{id:int, name:string, sources:list<string>}>, 1: array<int, array<string, true>>}
	 */
	public static function select(string $rAction, array $rItems, string $rHost, array $rState): array {
		$rWanted = [];
		$rStreams = [];
		foreach (SourceHealthRepository::liveStreams() as $rStream) {
			foreach ($rStream['sources'] as $rSource) {
				if ($rSource === '') {
					continue;
				}
				$rHash = md5($rSource);
				$rOff = in_array($rState[$rHash]['status'] ?? '', $rAction === 'delete' ? ['dead'] : ['dead', 'down'], true);
				if ($rHost !== '' && SourceAudit::host($rSource) === $rHost && $rOff) {
					$rWanted[$rStream['id']][$rHash] = true;
				}
			}
			$rStreams[$rStream['id']] = $rStream;
		}
		foreach ($rItems as [$rStreamID, $rHash]) {
			$rWanted[(int) $rStreamID][(string) $rHash] = true;
		}
		return [array_values(array_intersect_key($rStreams, $rWanted)), $rWanted];
	}

	/**
	 * Carry out a plan, re-checked against the streams as they are now.
	 *
	 * @return array{batch:string, streams:int, sources:int, refused:list<array<string, mixed>>}
	 */
	public static function apply(string $rAction, array $rItems, string $rHost, int $rUserID, int $rNow): array {
		$rState = SourceHealthRepository::auditState();
		[$rStreams, $rWanted] = self::select($rAction, $rItems, $rHost, $rState);
		$rPlan = self::plan($rAction, $rStreams, $rWanted, $rState, SourceHealthRepository::onAirSources(), $rNow);
		$rBatch = bin2hex(random_bytes(8));
		$rCount = 0;
		foreach ($rPlan['changes'] as $rChange) {
			if (!self::write($rChange['stream_id'], $rChange['before'], $rChange['after'])) {
				$rPlan['refused'][] = ['stream_id' => $rChange['stream_id'], 'name' => $rChange['name'], 'source' => '', 'why' => 'the stream changed meanwhile: try again'];
				continue;
			}
			foreach ($rChange['moved'] as $rItem) {
				self::db()->query(
					'INSERT INTO `source_health_actions` (`batch`, `created_at`, `user_id`, `stream_id`, `action`, `source`, `position`) VALUES (?, ?, ?, ?, ?, ?, ?)',
					$rBatch, $rNow, $rUserID ?: null, $rChange['stream_id'], $rAction, $rItem['source'], $rItem['position']
				);
				$rCount++;
			}
		}
		return ['batch' => $rBatch, 'streams' => count($rPlan['changes']), 'sources' => $rCount, 'refused' => $rPlan['refused']];
	}

	/** Sources one add may give one stream. */
	public const ADD_PER_STREAM = 3;

	/**
	 * Add provider channels to streams.
	 *
	 * @param list<array{0:int, 1:int, 2:int}> $rItems [stream id, provider id, provider's channel id]
	 * @return array{batch:string, streams:int, sources:int, refused:list<array{stream_id:int, name:string, source:string, why:string}>}
	 */
	public static function add(array $rItems, int $rUserID, int $rNow): array {
		$rProviders = SourceFinder::providers();
		$rState = SourceHealthRepository::auditState();
		$rOnAir = array_flip(array_map(static fn (string $s): string => md5(trim($s)), SourceHealthRepository::onAirSources()));
		$rByStream = [];
		foreach ($rItems as [$rStreamID, $rProviderID, $rChannel]) {
			$rByStream[(int) $rStreamID][] = [(int) $rProviderID, (int) $rChannel];
		}
		$rBatch = bin2hex(random_bytes(8));
		$rRefused = [];
		$rStreams = 0;
		$rCount = 0;
		foreach ($rByStream as $rStreamID => $rPicks) {
			$rName = (string) self::db()->fetchValue('SELECT `stream_display_name` FROM `streams` WHERE `id` = ? AND `type` = 1', $rStreamID);
			$rBefore = $rName === '' ? null : self::sources($rStreamID);
			if ($rBefore === null) {
				$rRefused[] = ['stream_id' => $rStreamID, 'name' => '#' . $rStreamID, 'source' => '', 'why' => 'no such live stream'];
				continue;
			}
			$rName = SourceHealthRepository::raw($rName);
			$rWorks = false;
			$rHave = [];
			foreach ($rBefore as $rSource) {
				$rWorks = $rWorks || isset($rOnAir[md5($rSource)]) || (($rState[md5($rSource)]['status'] ?? '') === 'ok');
				$rHave[SourceFinder::identity($rSource)] = true;
			}
			$rAfter = $rBefore;
			$rAdded = [];
			foreach (array_slice($rPicks, 0, self::ADD_PER_STREAM) as [$rProviderID, $rChannel]) {
				$p = $rProviders[$rProviderID] ?? null;
				$rListed = $p !== null && self::db()->fetchValue('SELECT COUNT(*) FROM `providers_streams` WHERE `provider_id` = ? AND `stream_id` = ? AND `type` = \'live\'', $rProviderID, $rChannel) > 0;
				if (!$rListed) {
					$rRefused[] = ['stream_id' => $rStreamID, 'name' => $rName, 'source' => '', 'why' => 'the provider or its channel is no longer listed'];
					continue;
				}
				$rUrl = SourceFinder::url($p, $rChannel);
				if (isset($rHave[SourceFinder::identity($rUrl)])) {
					$rRefused[] = ['stream_id' => $rStreamID, 'name' => $rName, 'source' => $rUrl, 'why' => 'the stream has this channel already'];
					continue;
				}
				$rHave[SourceFinder::identity($rUrl)] = true;
				$rAt = $rWorks ? count($rAfter) : count($rAdded);
				array_splice($rAfter, $rAt, 0, [$rUrl]);
				$rAdded[] = ['source' => $rUrl, 'position' => $rAt];
			}
			if ($rAdded === []) {
				continue;
			}
			if (!self::write($rStreamID, $rBefore, $rAfter)) {
				$rRefused[] = ['stream_id' => $rStreamID, 'name' => $rName, 'source' => '', 'why' => 'the stream changed meanwhile: try again'];
				continue;
			}
			foreach ($rAdded as $rItem) {
				self::db()->query(
					'INSERT INTO `source_health_actions` (`batch`, `created_at`, `user_id`, `stream_id`, `action`, `source`, `position`) VALUES (?, ?, ?, ?, ?, ?, ?)',
					$rBatch, $rNow, $rUserID ?: null, $rStreamID, 'add', $rItem['source'], $rItem['position']
				);
				$rCount++;
			}
			$rStreams++;
		}
		return ['batch' => $rBatch, 'streams' => $rStreams, 'sources' => $rCount, 'refused' => $rRefused];
	}

	/**
	 * Put a batch back: a deleted source returns to its place (if it is not in
	 * the stream again already), a moved one to where it was, an added one
	 * leaves the stream.
	 *
	 * @return array{streams:int, sources:int}
	 */
	public static function undo(string $rBatch, int $rNow): array {
		$rRows = self::db()->fetchAll('SELECT * FROM `source_health_actions` WHERE `batch` = ? AND `undone_at` IS NULL AND `created_at` >= ? ORDER BY `stream_id`, `position`', $rBatch, $rNow - self::UNDO_SEC) ?: [];
		$rByStream = [];
		foreach ($rRows as $rRow) {
			$rByStream[(int) $rRow['stream_id']][] = $rRow;
		}
		$rStreams = 0;
		$rSources = 0;
		foreach ($rByStream as $rStreamID => $rList) {
			$rBefore = self::sources($rStreamID);
			if ($rBefore === null) {
				continue;
			}
			$rAfter = $rBefore;
			foreach ($rList as $rRow) {
				$rSource = SourceHealthRepository::raw((string) $rRow['source']);
				$rAt = array_search($rSource, $rAfter, true);
				if ($rRow['action'] === 'add') {
					if ($rAt !== false) {
						array_splice($rAfter, (int) $rAt, 1);
						$rSources++;
					}
					continue;
				}
				if ($rRow['action'] === 'move_end' && $rAt !== false) {
					array_splice($rAfter, (int) $rAt, 1);
				} elseif ($rRow['action'] === 'delete' && $rAt !== false) {
					continue; // already back
				}
				array_splice($rAfter, min((int) $rRow['position'], count($rAfter)), 0, [$rSource]);
				$rSources++;
			}
			if ($rAfter !== $rBefore && self::write($rStreamID, $rBefore, $rAfter)) {
				$rStreams++;
			}
		}
		self::db()->query('UPDATE `source_health_actions` SET `undone_at` = ? WHERE `batch` = ? AND `undone_at` IS NULL', $rNow, $rBatch);
		return ['streams' => $rStreams, 'sources' => $rSources];
	}

	/** @return list<array{batch:string, created_at:int, user_id:?int, action:string, streams:int, sources:int, undone_at:?int}> newest first, within UNDO_SEC */
	public static function history(int $rNow): array {
		$rRows = self::db()->fetchAll(
			'SELECT a.`batch`, MIN(a.`created_at`) AS `created_at`, MIN(a.`action`) AS `action`, COUNT(DISTINCT a.`stream_id`) AS `streams`, COUNT(*) AS `sources`, MAX(a.`undone_at`) AS `undone_at`, MIN(a.`user_id`) AS `user_id`
			 FROM `source_health_actions` a WHERE a.`created_at` >= ? GROUP BY a.`batch` ORDER BY `created_at` DESC, MAX(a.`id`) DESC LIMIT 200', $rNow - self::UNDO_SEC
		) ?: [];
		return array_map(static fn (array $r): array => [
			'batch' => (string) $r['batch'], 'created_at' => (int) $r['created_at'], 'action' => (string) $r['action'], 'user_id' => $r['user_id'] === null ? null : (int) $r['user_id'],
			'streams' => (int) $r['streams'], 'sources' => (int) $r['sources'], 'undone_at' => $r['undone_at'] === null ? null : (int) $r['undone_at'],
		], $rRows);
	}

	public static function prune(int $rNow): void {
		self::db()->query('DELETE FROM `source_health_actions` WHERE `created_at` < ?', $rNow - self::UNDO_SEC);
	}

	/** @return list<string>|null the stream's sources as stored, or null when it is gone */
	private static function sources(int $rStreamID): ?array {
		$rJson = self::db()->fetchValue('SELECT `stream_source` FROM `streams` WHERE `id` = ?', $rStreamID);
		if ($rJson === null || $rJson === false) {
			return null;
		}
		return array_values(array_map(static fn ($s): string => is_string($s) ? trim($s) : '', (array) json_decode(SourceHealthRepository::raw((string) $rJson), true)));
	}

	/**
	 * Write a stream's new source list, only if it still holds what the plan
	 * was made from, and tell the nodes.
	 *
	 * @param list<string> $rBefore
	 * @param list<string> $rAfter
	 */
	private static function write(int $rStreamID, array $rBefore, array $rAfter): bool {
		if (self::sources($rStreamID) !== array_values($rBefore)) {
			return false;
		}
		self::db()->query('UPDATE `streams` SET `stream_source` = ? WHERE `id` = ?;', json_encode(array_values($rAfter)), $rStreamID);
		EventDispatcher::dispatch(new StreamsChangedEvent([$rStreamID]));
		if (class_exists(StreamProcess::class)) {
			try {
				StreamProcess::updateStream($rStreamID);
			} catch (\Throwable $e) {
				error_log('source-health: cache signal for stream ' . $rStreamID . ': ' . $e->getMessage());
			}
		}
		return true;
	}
}
