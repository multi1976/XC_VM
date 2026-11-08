<?php

namespace XcVm\Module\SourceHealth;

/**
 * Snapshot of what each running source looks like.
 *
 * Every run reads the encoders that are up on every server (MAIN's
 * `streams_servers` holds all of them) and records the fingerprint of the
 * source each one runs. Over time this fills in a fingerprint for every
 * source that has ever been on air — which is what the Source Health tab uses
 * to group sources that can replace each other cleanly.
 *
 * Read-only towards core: it never touches a stream, a process or a setting.
 */
final class SourceHealthCron {
	/** @return int number of profiles written */
	public static function run(int $rNow): int {
		$rWritten = 0;
		foreach (SourceHealthRepository::runningEncoders() as $rRow) {
			$rInfo = json_decode((string) $rRow['stream_info'], true);
			$rFp = SourceFingerprint::fromStreamInfo(is_array($rInfo) ? $rInfo : null);
			if ($rFp === null) {
				continue;
			}
			$rBitrate = isset($rRow['bitrate']) && (int) $rRow['bitrate'] > 0 ? (int) $rRow['bitrate'] : null;
			SourceHealthRepository::saveProfile((int) $rRow['stream_id'], (int) $rRow['server_id'], trim((string) $rRow['current_source']), $rFp, $rBitrate, $rNow);
			$rWritten++;
		}
		SourceHealthRepository::pruneProfiles($rNow);
		return $rWritten;
	}
}
