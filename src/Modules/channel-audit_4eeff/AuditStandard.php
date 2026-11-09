<?php

namespace XcVm\Module\ChannelAudit;

/**
 * A standard every file of a channel must meet, and the strict check of one
 * file against it.
 *
 * PURE: the check takes ffprobe's stream list, the PacketAnalyzer summary and
 * the decoder's error lines, and returns findings — no I/O.
 *
 * Severity: "fail" means the file would break or degrade a channel built by
 * stream copy (a decoder re-init, A/V drift, a stall, a broken segment);
 * "warn" means worth a look but harmless to the concat.
 */
final class AuditStandard {
	/** The form's fields, with the values a new standard starts from. */
	public const DEFAULTS = [
		'video_codec'    => 'h264',
		'video_profile'  => '',
		'width'          => 1920,
		'height'         => 1080,
		'sar'            => '1:1',
		'pix_fmt'        => 'yuv420p',
		'fps'            => '25',
		'scan'           => 'progressive',
		'gop'            => 50,
		'gop_mode'       => 'exact',
		'audio_codec'    => 'aac',
		'audio_rate'     => 48000,
		'audio_channels' => 2,
		'audio_streams'  => 1,
		'av_start_ms'    => 100,
		'av_end_ms'      => 100,
		'max_glitches'   => 0,
		'max_glitch_ms'  => 0,
	];

	/** Audio holes longer than this are heard (and drift the concat). */
	public const AUDIO_GAP_FAIL = 0.05;

	/**
	 * A standard from user input: every field present, typed and in range.
	 *
	 * @param array<string, mixed> $rInput
	 * @return array{0: array<string, mixed>, 1: list<string>} [spec, errors]
	 */
	public static function normalize(array $rInput): array {
		$rSpec = [];
		$rErrors = [];
		$rText = static fn (string $k): string => strtolower(trim((string) ($rInput[$k] ?? '')));
		$rInt = static fn (string $k): int => (int) ($rInput[$k] ?? 0);

		foreach (['video_codec', 'pix_fmt', 'audio_codec'] as $rKey) {
			$rSpec[$rKey] = $rText($rKey);
			if (!preg_match('/^[a-z0-9_]{2,32}$/', $rSpec[$rKey])) {
				$rErrors[] = $rKey . ' must be a codec/format name such as ' . self::DEFAULTS[$rKey];
			}
		}
		$rSpec['video_profile'] = trim((string) ($rInput['video_profile'] ?? ''));
		if ($rSpec['video_profile'] !== '' && !preg_match('/^[A-Za-z0-9 _:-]{1,32}$/', $rSpec['video_profile'])) {
			$rErrors[] = 'video_profile must be empty (any) or a profile name such as High';
		}
		foreach (['width' => [16, 7680], 'height' => [16, 4320], 'gop' => [0, 1000], 'audio_rate' => [8000, 192000], 'audio_channels' => [1, 8], 'audio_streams' => [0, 8], 'av_start_ms' => [0, 5000], 'av_end_ms' => [0, 5000], 'max_glitches' => [0, 1000], 'max_glitch_ms' => [0, 10000]] as $rKey => [$rMin, $rMax]) {
			$rSpec[$rKey] = $rInt($rKey);
			if ($rSpec[$rKey] < $rMin || $rSpec[$rKey] > $rMax) {
				$rErrors[] = $rKey . ' must be between ' . $rMin . ' and ' . $rMax;
			}
		}
		$rSpec['sar'] = $rText('sar');
		if (!preg_match('/^\d{1,5}:\d{1,5}$/', $rSpec['sar'])) {
			$rErrors[] = 'sar must look like 1:1';
		}
		$rSpec['fps'] = $rText('fps');
		if (self::rate($rSpec['fps']) === null) {
			$rErrors[] = 'fps must be a number or a fraction such as 25 or 30000/1001';
		}
		$rSpec['gop_mode'] = $rText('gop_mode') === 'max' ? 'max' : 'exact';
		$rSpec['scan'] = $rText('scan');
		if (!in_array($rSpec['scan'], ['progressive', 'interlaced'], true)) {
			$rErrors[] = 'scan must be progressive or interlaced';
		}
		return [$rSpec, $rErrors];
	}

	/** One line describing a standard: "h264 1920x1080 25p GOP 50 · aac 48k 2ch". */
	public static function describe(array $rSpec): string {
		$rVideo = $rSpec['video_codec'] . ($rSpec['video_profile'] !== '' ? ' ' . $rSpec['video_profile'] : '')
			. ' ' . $rSpec['width'] . 'x' . $rSpec['height'] . ' ' . $rSpec['fps'] . ($rSpec['scan'] === 'progressive' ? 'p' : 'i')
			. ' ' . $rSpec['pix_fmt'] . ' SAR ' . $rSpec['sar'] . ((int) $rSpec['gop'] > 0 ? ' GOP ' . (($rSpec['gop_mode'] ?? 'exact') === 'max' ? '≤' : '') . $rSpec['gop'] : '');
		$rAudio = (int) $rSpec['audio_streams'] === 0 ? 'no audio'
			: $rSpec['audio_codec'] . ' ' . round($rSpec['audio_rate'] / 1000, 1) . 'k ' . $rSpec['audio_channels'] . 'ch'
				. ((int) $rSpec['audio_streams'] > 1 ? ' ×' . $rSpec['audio_streams'] : '');
		$rTolerance = (int) ($rSpec['max_glitches'] ?? 0) > 0 ? ' · tolerates ' . (int) $rSpec['max_glitches'] . ' timestamp glitch(es) ≤ ' . (int) $rSpec['max_glitch_ms'] . ' ms' : '';
		return $rVideo . ' · ' . $rAudio . $rTolerance;
	}

	/**
	 * Check one file.
	 *
	 * @param array<string, mixed>      $rSpec      normalize() output
	 * @param list<array<string, mixed>> $rStreams  ffprobe -show_streams (decoded JSON 'streams')
	 * @param array<string, mixed>|null $rPackets   PacketAnalyzer::finish(), null if the scan could not run
	 * @param list<string>|null         $rDecodeErrors decoder error lines; null when not decoded
	 * @return array{status: string, issues: list<array{0:string,1:string,2:string}>, facts: array<string, mixed>}
	 */
	public static function check(array $rSpec, array $rStreams, ?array $rPackets, ?array $rDecodeErrors = null): array {
		$rSpec += self::DEFAULTS; // a standard saved before a field existed gets its strict default
		$rIssues = [];
		$rFail = static function (string $rField, string $rMessage) use (&$rIssues): void {
			$rIssues[] = ['fail', $rField, $rMessage];
		};
		$rWarn = static function (string $rField, string $rMessage) use (&$rIssues): void {
			$rIssues[] = ['warn', $rField, $rMessage];
		};

		$rVideos = array_values(array_filter($rStreams, static fn (array $s): bool => ($s['codec_type'] ?? '') === 'video' && empty($s['disposition']['attached_pic'])));
		$rAudios = array_values(array_filter($rStreams, static fn (array $s): bool => ($s['codec_type'] ?? '') === 'audio'));
		$rOthers = count($rStreams) - count($rVideos) - count($rAudios);

		if (count($rVideos) !== 1) {
			$rFail('streams', count($rVideos) === 0 ? 'no video stream' : count($rVideos) . ' video streams (exactly 1 expected)');
		}
		if (count($rAudios) !== (int) $rSpec['audio_streams']) {
			$rFail('streams', count($rAudios) . ' audio stream(s), the standard has ' . (int) $rSpec['audio_streams']);
		}
		if ($rOthers > 0) {
			$rWarn('streams', $rOthers . ' other stream(s) (subtitles, data, cover art): not carried into the channel');
		}

		$v = $rVideos[0] ?? null;
		$a = $rAudios[0] ?? null;
		$rFacts = ['video' => null, 'audio' => null];

		if ($v !== null) {
			$rFps = self::rate((string) ($v['r_frame_rate'] ?? ''));
			$rAvg = self::rate((string) ($v['avg_frame_rate'] ?? ''));
			$rScan = self::scan((string) ($v['field_order'] ?? ''));
			$rSar = self::sar((string) ($v['sample_aspect_ratio'] ?? ''));
			$rFacts['video'] = [
				'codec' => (string) ($v['codec_name'] ?? ''), 'profile' => (string) ($v['profile'] ?? ''),
				'width' => (int) ($v['width'] ?? 0), 'height' => (int) ($v['height'] ?? 0), 'sar' => $rSar,
				'pix_fmt' => (string) ($v['pix_fmt'] ?? ''), 'fps' => (string) ($v['r_frame_rate'] ?? ''),
				'avg_fps' => (string) ($v['avg_frame_rate'] ?? ''), 'scan' => $rScan, 'level' => $v['level'] ?? null,
			];
			self::same($rFail, 'video_codec', $rSpec['video_codec'], strtolower($rFacts['video']['codec']));
			if ($rSpec['video_profile'] !== '' && strcasecmp($rSpec['video_profile'], $rFacts['video']['profile']) !== 0) {
				$rFail('video_profile', 'profile ' . self::shown($rFacts['video']['profile']) . ', the standard has ' . $rSpec['video_profile']);
			}
			if ($rFacts['video']['width'] !== (int) $rSpec['width'] || $rFacts['video']['height'] !== (int) $rSpec['height']) {
				$rFail('resolution', $rFacts['video']['width'] . 'x' . $rFacts['video']['height'] . ', the standard has ' . $rSpec['width'] . 'x' . $rSpec['height']);
			}
			if ($rSar === null) {
				$rWarn('sar', 'sample aspect ratio not stored in the file (players assume ' . $rSpec['sar'] . ')');
			} elseif (!self::sameRatio($rSar, $rSpec['sar'])) {
				$rFail('sar', 'SAR ' . $rSar . ', the standard has ' . $rSpec['sar']);
			}
			self::same($rFail, 'pix_fmt', $rSpec['pix_fmt'], $rFacts['video']['pix_fmt']);
			$rWant = self::rate($rSpec['fps']);
			if ($rFps === null || abs($rFps - $rWant) > 0.01) {
				$rFail('fps', 'frame rate ' . self::shown($rFacts['video']['fps']) . ', the standard has ' . $rSpec['fps']);
			} elseif ($rAvg !== null && abs($rAvg - $rWant) > 0.05) {
				$rFail('fps', 'average frame rate ' . $rFacts['video']['avg_fps'] . ' differs from the declared ' . $rFacts['video']['fps'] . ' (variable frame rate)');
			}
			if ($rScan === null) {
				$rWarn('scan', 'field order not stored in the file');
			} elseif ($rScan !== $rSpec['scan']) {
				$rFail('scan', $rScan . ', the standard has ' . $rSpec['scan']);
			}
		}

		if ($a !== null && (int) $rSpec['audio_streams'] > 0) {
			$rFacts['audio'] = [
				'codec' => (string) ($a['codec_name'] ?? ''), 'rate' => (int) ($a['sample_rate'] ?? 0),
				'channels' => (int) ($a['channels'] ?? 0), 'layout' => (string) ($a['channel_layout'] ?? ''),
			];
			self::same($rFail, 'audio_codec', $rSpec['audio_codec'], strtolower($rFacts['audio']['codec']));
			if ($rFacts['audio']['rate'] !== (int) $rSpec['audio_rate']) {
				$rFail('audio_rate', $rFacts['audio']['rate'] . ' Hz, the standard has ' . $rSpec['audio_rate'] . ' Hz');
			}
			if ($rFacts['audio']['channels'] !== (int) $rSpec['audio_channels']) {
				$rFail('audio_channels', $rFacts['audio']['channels'] . ' channel(s), the standard has ' . $rSpec['audio_channels']);
			}
		}

		if ($rPackets === null) {
			$rFail('scan', 'the packet scan could not read the file');
		} elseif ($v !== null) {
			self::checkPackets($rSpec, $rPackets, $a !== null && (int) $rSpec['audio_streams'] > 0, $rFail, $rWarn);
			$rFacts['duration'] = $rPackets['video']['duration'];
			$rFacts['gops'] = $rPackets['video']['gops'];
		}

		if ($rDecodeErrors !== null && $rDecodeErrors !== []) {
			$rFail('decode', count($rDecodeErrors) . ' decoder error line(s), first: ' . mb_substr($rDecodeErrors[0], 0, 160));
		}

		$rStatus = 'pass';
		foreach ($rIssues as [$rSeverity]) {
			if ($rSeverity === 'fail') {
				$rStatus = 'fail';
				break;
			}
			$rStatus = 'warn';
		}
		return ['status' => $rStatus, 'issues' => $rIssues, 'facts' => $rFacts];
	}

	/** @param array<string, mixed> $rPackets */
	private static function checkPackets(array $rSpec, array $rPackets, bool $rWithAudio, callable $rFail, callable $rWarn): void {
		$rV = $rPackets['video'];
		if ((int) $rV['frames'] === 0) {
			$rFail('video', 'no video frames with timestamps');
			return;
		}
		if ($rV['duration'] < 1.0) {
			$rFail('duration', 'only ' . round($rV['duration'], 2) . ' s of video');
		}
		if (!$rV['first_key']) {
			$rFail('keyframe', 'the first video packet is not a keyframe (the item would start on broken frames)');
		}
		if ($rV['no_timestamp'] > 0) {
			$rFail('timestamps', $rV['no_timestamp'] . ' video packet(s) without timestamps');
		}
		if ($rV['corrupt'] > 0) {
			$rFail('corrupt', $rV['corrupt'] . ' video packet(s) flagged corrupt by the demuxer');
		}
		$rStep = round(1000 * (float) ($rV['frame_step'] ?? 0), 2);
		$rGlitches = (int) $rV['dts_backwards'] + (int) $rV['irregular'] + (int) $rV['duplicates'];
		$rWorst = (int) round(1000 * (float) ($rV['max_dev'] ?? 0));
		if ($rGlitches > 0 && $rGlitches <= (int) $rSpec['max_glitches'] && $rWorst <= (int) $rSpec['max_glitch_ms']) {
			// Within what the standard tolerates: the small timestamp seams files
			// joined by stream copy carry. Reported, not failed.
			$rWarn('timestamps', $rGlitches . ' timestamp glitch(es) within the tolerance (largest ' . $rWorst . ' ms off the ' . $rStep . ' ms step, at '
				. self::clock((float) ($rV['max_dev_at'] ?? 0)) . '): typical of files joined by stream copy');
		} else {
			if ($rV['dts_backwards'] > 0) {
				$rFail('timestamps', $rV['dts_backwards'] . ' video packet(s) with decode timestamps going backwards');
			}
			if ($rV['irregular'] > 0) {
				$rFail('cfr', 'not constant frame rate: ' . $rV['irregular'] . ' frame(s) off the ' . $rStep . ' ms step, biggest jump '
					. round($rV['max_step'], 3) . ' s at ' . self::clock((float) $rV['max_step_at']));
			}
			if ($rV['duplicates'] > 0) {
				$rFail('cfr', $rV['duplicates'] . ' frame(s) sharing a timestamp');
			}
		}

		$rGop = (int) $rSpec['gop'];
		$rGops = $rV['gops'];
		if ($rGop > 0 && $rSpec['gop_mode'] === 'max') {
			// Maximum: no GOP longer than the standard (it would stretch an HLS
			// segment); a shorter one — the end of a joined part — is only noted.
			$rLonger = array_filter($rGops, static fn (int $n, int $len): bool => $len > $rGop, ARRAY_FILTER_USE_BOTH);
			$rShorter = array_filter($rGops, static fn (int $n, int $len): bool => $len < $rGop, ARRAY_FILTER_USE_BOTH);
			if ($rLonger !== [] || $rV['last_gop'] > $rGop) {
				$rFail('gop', 'keyframe interval longer than ' . $rGop . ' frames: ' . self::gopSummary($rGops, (int) $rV['last_gop']));
			} elseif ($rShorter !== []) {
				$rWarn('gop', array_sum($rShorter) . ' GOP(s) shorter than ' . $rGop . ' frames (' . implode(', ', array_keys($rShorter)) . '): harmless, typical where parts were joined');
			}
		} elseif ($rGop > 0) {
			$rOff = array_filter($rGops, static fn (int $n, int $len): bool => $len !== $rGop, ARRAY_FILTER_USE_BOTH);
			if ($rOff !== [] || $rV['last_gop'] > $rGop) {
				$rFail('gop', 'keyframe interval not ' . $rGop . ' frames: ' . self::gopSummary($rGops, (int) $rV['last_gop']));
			}
		}

		$a = $rPackets['audio'];
		if (!$rWithAudio || $a === null) {
			return;
		}
		if ($a['start'] === null) {
			$rFail('audio', 'no audio packets with timestamps');
			return;
		}
		$rStartGap = abs((float) $a['start'] - (float) $rV['start']) * 1000;
		if ($rStartGap > (int) $rSpec['av_start_ms']) {
			$rFail('av_start', 'audio starts ' . self::offset((float) $a['start'] - (float) $rV['start']) . ' the video (limit ' . $rSpec['av_start_ms'] . ' ms)');
		}
		$rEndGap = abs((float) $a['end'] - (float) $rV['end']) * 1000;
		if ($rEndGap > (int) $rSpec['av_end_ms']) {
			$rFail('av_end', 'audio ends ' . self::offset((float) $a['end'] - (float) $rV['end']) . ' the video (limit ' . $rSpec['av_end_ms'] . ' ms): the difference adds up from item to item');
		}
		if ($a['no_timestamp'] > 0) {
			$rFail('timestamps', $a['no_timestamp'] . ' audio packet(s) without timestamps');
		}
		if ($a['gaps'] > 0) {
			$rMessage = $a['gaps'] . ' hole(s) in the audio, longest ' . round($a['max_gap'] * 1000) . ' ms at ' . self::clock((float) $a['max_gap_at']);
			$a['max_gap'] > self::AUDIO_GAP_FAIL ? $rFail('audio_gaps', $rMessage) : $rWarn('audio_gaps', $rMessage);
		}
		if ($a['overlaps'] > 0) {
			$rMessage = $a['overlaps'] . ' overlapping audio packet(s), up to ' . round($a['max_overlap'] * 1000) . ' ms';
			$a['max_overlap'] > self::AUDIO_GAP_FAIL ? $rFail('audio_gaps', $rMessage) : $rWarn('audio_gaps', $rMessage);
		}
	}

	/** "50 ×212, 37 ×1 (+ last 12)" — most common lengths first, at most 5. */
	public static function gopSummary(array $rGops, int $rLast): string {
		arsort($rGops);
		$rParts = [];
		foreach (array_slice($rGops, 0, 5, true) as $rLen => $rN) {
			$rParts[] = $rLen . ' ×' . $rN;
		}
		if (count($rGops) > 5) {
			$rParts[] = '…' . (count($rGops) - 5) . ' more lengths';
		}
		return ($rParts === [] ? 'one GOP only' : implode(', ', $rParts)) . ($rLast > 0 ? ' (last ' . $rLast . ')' : '');
	}

	/** "25", "25/1", "29.97", "30000/1001" → frames per second; null when not a positive rate. */
	public static function rate(string $rValue): ?float {
		$rValue = trim($rValue);
		if (preg_match('~^(\d+)/(\d+)$~', $rValue, $m)) {
			return ((int) $m[2] > 0 && (int) $m[1] > 0) ? ((int) $m[1]) / ((int) $m[2]) : null;
		}
		return (is_numeric($rValue) && (float) $rValue > 0 && (float) $rValue <= 300) ? (float) $rValue : null;
	}

	public static function clock(float $rSeconds): string {
		$rSeconds = max(0, (int) round($rSeconds));
		return sprintf('%02d:%02d:%02d', intdiv($rSeconds, 3600), intdiv($rSeconds % 3600, 60), $rSeconds % 60);
	}

	/** "120 ms after" / "40 ms before" */
	private static function offset(float $rSeconds): string {
		return abs((int) round($rSeconds * 1000)) . ' ms ' . ($rSeconds >= 0 ? 'after' : 'before');
	}

	private static function same(callable $rFail, string $rField, string $rWant, string $rHave): void {
		if (strcasecmp($rWant, $rHave) !== 0) {
			$rFail($rField, self::shown($rHave) . ', the standard has ' . $rWant);
		}
	}

	private static function shown(string $rValue): string {
		return $rValue === '' ? '(none)' : $rValue;
	}

	/** progressive / interlaced, or null when the file does not say. */
	private static function scan(string $rFieldOrder): ?string {
		$rFieldOrder = strtolower($rFieldOrder);
		if ($rFieldOrder === 'progressive') {
			return 'progressive';
		}
		return in_array($rFieldOrder, ['tt', 'bb', 'tb', 'bt'], true) ? 'interlaced' : null;
	}

	/** "16:15"; null for unset ("0:1", "N/A", empty). */
	private static function sar(string $rValue): ?string {
		return preg_match('/^([1-9]\d*):([1-9]\d*)$/', $rValue) ? $rValue : null;
	}

	private static function sameRatio(string $rA, string $rB): bool {
		[$rAn, $rAd] = array_map('intval', explode(':', $rA));
		[$rBn, $rBd] = array_map('intval', explode(':', $rB));
		return $rAd > 0 && $rBd > 0 && $rAn * $rBd === $rBn * $rAd;
	}
}
