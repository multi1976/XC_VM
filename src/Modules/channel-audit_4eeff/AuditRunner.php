<?php

namespace XcVm\Module\ChannelAudit;

use XcVm\Streaming\Codec\FfmpegPaths;

/**
 * Runs one audit job from start to end: lists the files, checks them one by
 * one and keeps the job's counters current, so the page can follow it. A job
 * the admin cancels stops at the next poll (the running ffprobe/ffmpeg is
 * killed).
 */
final class AuditRunner {
	/** @param FileScanner|null $rScanner the panel's ffprobe/ffmpeg when null (tests pass their own) */
	public static function run(int $rJobID, ?FileScanner $rScanner = null): int {
		$rJob = AuditRepository::job($rJobID);
		if ($rJob === null || $rJob['status'] !== 'queued') {
			return 1;
		}
		AuditRepository::updateJob($rJobID, ['status' => 'running', 'pid' => getmypid(), 'started_at' => time()]);

		$rSpec = json_decode((string) $rJob['spec'], true);
		if (!is_array($rSpec)) {
			return self::fail($rJobID, 'the job has no standard');
		}

		try {
			if ($rJob['kind'] === 'folder') {
				$rItems = SourceResolver::folder((int) $rJob['server_id'], (string) $rJob['target']);
			} else {
				$rChannel = AuditRepository::createdChannel((int) $rJob['stream_id']);
				if ($rChannel === null) {
					return self::fail($rJobID, 'the created channel no longer exists');
				}
				if ($rChannel['sources'] === []) {
					return self::fail($rJobID, 'the channel has no sources');
				}
				$rItems = SourceResolver::channel($rChannel['sources']);
			}
		} catch (\Throwable $e) {
			return self::fail($rJobID, $e->getMessage());
		}

		AuditRepository::addItems($rJobID, $rItems);
		AuditRepository::updateJob($rJobID, ['total' => count($rItems)]);

		$rScanner ??= new FileScanner((string) FfmpegPaths::probe(), (string) FfmpegPaths::cpu());
		$rCancelled = static fn (): bool => AuditRepository::jobStatus($rJobID) !== 'running';
		$rCounts = ['done' => 0, 'passed' => 0, 'warned' => 0, 'failed' => 0];

		foreach (AuditRepository::items($rJobID) as $rItem) {
			if ($rCancelled()) {
				return 0;
			}
			AuditRepository::markItem((int) $rItem['id'], 'running');
			try {
				$rResult = $rScanner->scan(SourceResolver::input((string) $rItem['source']), $rSpec, (bool) $rJob['deep'], $rCancelled);
			} catch (\Throwable $e) {
				$rResult = FileScanner::error($e->getMessage());
			}
			if ($rCancelled()) {
				AuditRepository::markItem((int) $rItem['id'], 'queued');
				return 0;
			}
			AuditRepository::saveItem((int) $rItem['id'], $rResult['status'], isset($rResult['facts']['duration']) ? (float) $rResult['facts']['duration'] : null, $rResult);

			$rCounts['done']++;
			$rCounts[$rResult['status'] === 'pass' ? 'passed' : ($rResult['status'] === 'warn' ? 'warned' : 'failed')]++;
			AuditRepository::updateJob($rJobID, $rCounts);
		}

		AuditRepository::updateJob($rJobID, ['status' => 'done', 'pid' => null, 'finished_at' => time()]);
		return 0;
	}

	private static function fail(int $rJobID, string $rMessage): int {
		AuditRepository::updateJob($rJobID, ['status' => 'failed', 'error' => mb_substr($rMessage, 0, 255), 'pid' => null, 'finished_at' => time()]);
		return 1;
	}
}
