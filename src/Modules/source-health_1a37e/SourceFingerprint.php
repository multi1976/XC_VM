<?php

namespace XcVm\Module\SourceHealth;

/**
 * The "shape" of a source's output — what a player's decoder has to be set up
 * for. Two sources with the same fingerprint can replace each other mid-stream
 * with only a timestamp jump; a different fingerprint means the player has to
 * re-initialise its decoder (where many set-top boxes freeze or lose audio).
 *
 * PURE: built from the parsed ffprobe array the panel stores in
 * `streams_servers.stream_info` (FFprobeRunner::parseFFProbe()).
 */
final class SourceFingerprint {
	/**
	 * @param array<string, mixed>|null $rInfo parsed ffprobe output.
	 * @return array{key: string, label: string, video_codec: string, video_profile: string, width: int, height: int, fps: float, scan: string, audio_codec: string, audio_rate: int, audio_channels: int}|null
	 *         null when the info carries no video and no audio.
	 */
	public static function fromStreamInfo(?array $rInfo): ?array {
		$rVideo = is_array($rInfo['codecs']['video'] ?? null) ? $rInfo['codecs']['video'] : [];
		$rAudio = is_array($rInfo['codecs']['audio'] ?? null) ? $rInfo['codecs']['audio'] : [];
		if ($rVideo === [] && $rAudio === []) {
			return null;
		}

		$rFp = [
			'video_codec'    => strtolower((string) ($rVideo['codec_name'] ?? '')),
			'video_profile'  => (string) ($rVideo['profile'] ?? ''),
			'width'          => (int) ($rVideo['width'] ?? 0),
			'height'         => (int) ($rVideo['height'] ?? 0),
			'fps'            => self::fps($rVideo),
			'scan'           => self::scan((string) ($rVideo['field_order'] ?? '')),
			'audio_codec'    => strtolower((string) ($rAudio['codec_name'] ?? '')),
			'audio_rate'     => (int) ($rAudio['sample_rate'] ?? 0),
			'audio_channels' => (int) ($rAudio['channels'] ?? 0),
		];
		// The profile stays out of the key: High vs Main H.264 does not make a
		// decoder re-initialise on its own, and providers report it inconsistently.
		$rFp['key'] = implode('|', [
			$rFp['video_codec'], $rFp['width'] . 'x' . $rFp['height'], self::fpsKey($rFp['fps']), $rFp['scan'],
			$rFp['audio_codec'], $rFp['audio_rate'], $rFp['audio_channels'],
		]);
		$rFp['label'] = self::label($rFp);
		return $rFp;
	}

	/** Frames per second from ffprobe's avg_frame_rate, else r_frame_rate ("25/1", "30000/1001"). */
	private static function fps(array $rVideo): float {
		foreach (['avg_frame_rate', 'r_frame_rate'] as $rKey) {
			$rRate = (string) ($rVideo[$rKey] ?? '');
			if (preg_match('~^(\d+)/(\d+)$~', $rRate, $m) && (int) $m[2] > 0 && (int) $m[1] > 0) {
				return round(((int) $m[1]) / ((int) $m[2]), 3);
			}
		}
		return 0.0;
	}

	/** 29.97 and 30 count as different (a cadence change), 25.0 and 25.002 as the same. */
	private static function fpsKey(float $rFps): string {
		return $rFps > 0 ? number_format($rFps, 2, '.', '') : '?';
	}

	/** ffprobe's field_order → p (progressive), i (interlaced) or ? (not reported). */
	private static function scan(string $rFieldOrder): string {
		$rFieldOrder = strtolower($rFieldOrder);
		if ($rFieldOrder === 'progressive') {
			return 'p';
		}
		return in_array($rFieldOrder, ['tt', 'bb', 'tb', 'bt'], true) ? 'i' : '?';
	}

	/** @param array<string, mixed> $rFp */
	private static function label(array $rFp): string {
		$rParts = [];
		if ($rFp['video_codec'] !== '') {
			$rVideo = $rFp['video_codec'];
			if ($rFp['width'] > 0 && $rFp['height'] > 0) {
				$rVideo .= ' ' . $rFp['width'] . 'x' . $rFp['height'];
			}
			if ($rFp['fps'] > 0) {
				$rVideo .= ' ' . rtrim(rtrim(number_format($rFp['fps'], 2, '.', ''), '0'), '.') . ($rFp['scan'] === '?' ? '' : $rFp['scan']);
			}
			$rParts[] = $rVideo;
		}
		if ($rFp['audio_codec'] !== '') {
			$rAudio = $rFp['audio_codec'];
			if ($rFp['audio_rate'] > 0) {
				$rAudio .= ' ' . round($rFp['audio_rate'] / 1000, 1) . 'k';
			}
			if ($rFp['audio_channels'] > 0) {
				$rAudio .= ' ' . $rFp['audio_channels'] . 'ch';
			}
			$rParts[] = $rAudio;
		}
		return implode(' · ', $rParts);
	}
}
