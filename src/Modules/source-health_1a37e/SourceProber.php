<?php

namespace XcVm\Module\SourceHealth;

/**
 * Active probes: every source of a stream the admin opted in is tested on its
 * own, on a schedule, whether the stream uses it or not — so backups that are
 * rarely on air get measured too, on equal terms with the first source.
 *
 * A probe is one short connection: ffprobe opens the source (time to open,
 * output profile), then ffmpeg reads PROBE_READ_SEC of it by stream copy and
 * checks that it arrives at least in real time.
 *
 * Providers often allow one connection per account, so a probe must never
 * take the connection a live stream holds:
 *   - a source that is on air anywhere is never probed;
 *   - nor is a source on the same provider account (same host and path up to
 *     the channel id, e.g. http://host:8080/live/user/pass/) as any source on
 *     air on the panel — that probe would knock the live one off.
 *
 * plan() and accountKey() are PURE; run() and probe() do the I/O.
 */
final class SourceProber {
	/** Each source is probed at most this often. */
	public const INTERVAL_SEC = 1800;
	/** Probes per cron run (every 5 min): ~12 per minute-of-cron at most. */
	public const MAX_PER_RUN = 6;
	/** Stop starting new probes after this long in one run. */
	public const RUN_BUDGET_SEC = 200;
	/** Seconds of wall time a probe measures the read rate over… */
	public const PROBE_READ_SEC = 8;
	/** …after skipping this many: servers send a buffered burst on connect, which says nothing about keeping up. */
	public const BURST_SEC = 4;
	/** Schemes a probe can open from MAIN. */
	public const SCHEMES = ['http', 'https', 'rtmp', 'rtmps', 'rtsp'];

	/**
	 * The provider account a source URL belongs to: scheme, user info, host,
	 * port and the path without its last segment (the channel id).
	 */
	public static function accountKey(string $rUrl): ?string {
		$rParts = parse_url(trim($rUrl));
		if (!is_array($rParts) || empty($rParts['host'])) {
			return null;
		}
		$rPath = (string) ($rParts['path'] ?? '/');
		$rDir = rtrim(str_replace('\\', '/', dirname($rPath)), '/');
		return strtolower(($rParts['scheme'] ?? '') . '://' . (isset($rParts['user']) ? $rParts['user'] . '@' : '') . $rParts['host'] . ':' . ($rParts['port'] ?? ''))
			. $rDir . '/';
	}

	/**
	 * Which sources to probe now: due ones (never probed, or longest ago first),
	 * minus those on air or sharing an account with a source on air.
	 *
	 * @param list<array{stream_id:int, source:string}> $rCandidates sources of opted-in streams
	 * @param array<string, int> $rLastProbe "streamId:md5" => last probe time
	 * @param list<string> $rOnAir every source on air on the panel right now
	 * @return array{0: list<array{stream_id:int, source:string}>, 1: list<array{stream_id:int, source:string, reason:string}>} [to probe, skipped]
	 */
	public static function plan(array $rCandidates, array $rLastProbe, array $rOnAir, int $rNow, int $rMax = self::MAX_PER_RUN): array {
		$rLive = array_flip(array_map('trim', $rOnAir));
		$rBusy = [];
		foreach ($rOnAir as $rSource) {
			$rKey = self::accountKey($rSource);
			if ($rKey !== null) {
				$rBusy[$rKey] = true;
			}
		}

		$rDue = [];
		$rSkipped = [];
		foreach ($rCandidates as $rCandidate) {
			$rSource = trim($rCandidate['source']);
			$rScheme = strtolower((string) parse_url($rSource, PHP_URL_SCHEME));
			$rLast = $rLastProbe[$rCandidate['stream_id'] . ':' . md5($rSource)] ?? 0;
			if ($rNow - $rLast < self::INTERVAL_SEC) {
				continue;
			}
			if (!in_array($rScheme, self::SCHEMES, true)) {
				$rSkipped[] = $rCandidate + ['reason' => 'not a network source'];
			} elseif (isset($rLive[$rSource])) {
				$rSkipped[] = $rCandidate + ['reason' => 'on air'];
			} elseif (isset($rBusy[(string) self::accountKey($rSource)])) {
				$rSkipped[] = $rCandidate + ['reason' => 'its provider account is in use by a live stream'];
			} else {
				$rDue[] = $rCandidate + ['last' => $rLast];
			}
		}
		usort($rDue, static fn (array $a, array $b): int => $a['last'] <=> $b['last']);
		$rDue = array_map(static function (array $r): array {
			unset($r['last']);
			return $r;
		}, array_slice($rDue, 0, $rMax));
		return [$rDue, $rSkipped];
	}

	/** One cron run: probe what is due, record results and skips. @return int probes made */
	public static function run(int $rNow, string $rProbe, string $rFfmpeg): int {
		$rStreams = SourceHealthRepository::probeStreams();
		if ($rStreams === []) {
			return 0;
		}
		[$rDue, $rSkipped] = self::plan(
			SourceHealthRepository::probeCandidates($rStreams),
			SourceHealthRepository::lastProbes($rStreams),
			SourceHealthRepository::onAirSources(),
			$rNow
		);
		foreach ($rSkipped as $rSkip) {
			SourceHealthRepository::saveSkip($rSkip['stream_id'], $rSkip['source'], $rSkip['reason'], $rNow);
		}

		$rStarted = time();
		$rDone = 0;
		foreach ($rDue as $rItem) {
			if (time() - $rStarted > self::RUN_BUDGET_SEC) {
				break;
			}
			$rResult = self::probe($rItem['source'], SourceHealthRepository::fetchOptions($rItem['stream_id']), $rProbe, $rFfmpeg);
			SourceHealthRepository::saveProbe($rItem['stream_id'], $rItem['source'], $rResult, time());
			$rDone++;
		}
		SourceHealthRepository::pruneProbes($rNow);
		return $rDone;
	}

	/**
	 * Probe one source.
	 *
	 * @param array{user_agent?:string, http_proxy?:string, cookies?:string, headers?:string} $rFetch
	 * @return array{ok: bool, open_ms: ?int, speed: ?float, label: string, error: string}
	 */
	public static function probe(string $rUrl, array $rFetch, string $rProbe, string $rFfmpeg): array {
		$rNet = ['-rw_timeout', '10000000'];
		if (preg_match('~^https?://~i', $rUrl)) {
			foreach (['user_agent', 'http_proxy', 'cookies', 'headers'] as $rKey) {
				if (!empty($rFetch[$rKey])) {
					array_push($rNet, '-' . $rKey, (string) $rFetch[$rKey]);
				}
			}
		}

		$rStart = microtime(true);
		[$rCode, $rOut, $rErr] = self::exec(array_merge(['timeout', '20', $rProbe, '-v', 'error'], $rNet, ['-probesize', '5000000', '-analyzeduration', '5000000', '-show_streams', '-of', 'json', $rUrl]));
		$rOpenMs = (int) round((microtime(true) - $rStart) * 1000);
		$rInfo = json_decode($rOut, true);
		if ($rCode !== 0 || !is_array($rInfo) || empty($rInfo['streams'])) {
			return ['ok' => false, 'open_ms' => null, 'speed' => null, 'label' => '', 'error' => self::why($rCode, $rErr, 'could not open')];
		}
		$rCodecs = [];
		foreach ($rInfo['streams'] as $rStream) {
			$rType = $rStream['codec_type'] ?? '';
			if (($rType === 'video' || $rType === 'audio') && !isset($rCodecs[$rType])) {
				$rCodecs[$rType] = $rStream;
			}
		}
		$rFp = SourceFingerprint::fromStreamInfo(['codecs' => ['video' => $rCodecs['video'] ?? [], 'audio' => $rCodecs['audio'] ?? []]]);

		[$rCode, $rSamples, $rErr] = self::read(array_merge([$rFfmpeg, '-nostdin', '-hide_banner', '-v', 'error', '-nostats', '-progress', 'pipe:1'], $rNet, ['-i', $rUrl, '-map', '0:v?', '-map', '0:a?', '-c', 'copy', '-f', 'null', '-']), self::BURST_SEC + self::PROBE_READ_SEC);
		$rLabel = (string) ($rFp['label'] ?? '');
		if ($rCode !== 0 && $rCode !== -1) {
			return ['ok' => false, 'open_ms' => $rOpenMs, 'speed' => null, 'label' => $rLabel, 'error' => self::why($rCode, $rErr, 'stopped while reading')];
		}
		$rSpeed = self::steadyRate($rSamples, $rCode === 0);
		if ($rSpeed === null) {
			return ['ok' => false, 'open_ms' => $rOpenMs, 'speed' => null, 'label' => $rLabel, 'error' => 'no data after the first ' . self::BURST_SEC . ' s'];
		}
		$rSlow = $rSpeed < 0.9;
		return ['ok' => !$rSlow, 'open_ms' => $rOpenMs, 'speed' => $rSpeed, 'label' => $rLabel, 'error' => $rSlow ? 'slower than real time (' . $rSpeed . 'x)' : ''];
	}

	/**
	 * Media seconds received per wall second once the connect burst is over:
	 * from the first progress sample at or after BURST_SEC to the last one.
	 * A source that ended on its own before that (a short file) delivered all
	 * of it faster than real time: its rate is the whole read. PURE.
	 *
	 * @param list<array{0: float, 1: float}> $rSamples [wall seconds since start, media seconds out]
	 */
	public static function steadyRate(array $rSamples, bool $rEnded): ?float {
		if ($rSamples === []) {
			return null;
		}
		$rFirst = null;
		foreach ($rSamples as $rSample) {
			if ($rSample[0] >= self::BURST_SEC) {
				$rFirst = $rSample;
				break;
			}
		}
		$rLast = $rSamples[count($rSamples) - 1];
		if ($rFirst === null || $rLast[0] - $rFirst[0] < 1.0) {
			if (!$rEnded) {
				return null;
			}
			return round(min(99.0, $rLast[1] / max(0.1, $rLast[0])), 2);
		}
		return round(min(99.0, ($rLast[1] - $rFirst[1]) / ($rLast[0] - $rFirst[0])), 2);
	}

	/**
	 * Run ffmpeg with -progress on stdout for at most $rSeconds, sampling its
	 * media position (out_time_us) against wall time.
	 *
	 * @param list<string> $rCommand
	 * @return array{0: int, 1: list<array{0: float, 1: float}>, 2: string} [exit code, -1 when stopped at the time limit; samples; stderr]
	 */
	private static function read(array $rCommand, int $rSeconds): array {
		$rProc = proc_open($rCommand, [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $rPipes);
		if (!is_resource($rProc)) {
			return [127, [], 'could not start ' . $rCommand[0]];
		}
		stream_set_blocking($rPipes[1], false);
		stream_set_blocking($rPipes[2], false);
		$rStart = microtime(true);
		$rSamples = [];
		$rBuffer = $rErr = '';
		$rStopped = false;
		while (true) {
			$rRead = [$rPipes[1], $rPipes[2]];
			$rWrite = $rExcept = null;
			@stream_select($rRead, $rWrite, $rExcept, 0, 200000);
			foreach ($rRead as $rPipe) {
				$rChunk = (string) fread($rPipe, 65536);
				if ($rPipe === $rPipes[2]) {
					$rErr = strlen($rErr) < 65536 ? $rErr . $rChunk : $rErr;
					continue;
				}
				$rBuffer .= $rChunk;
				while (($rPos = strpos($rBuffer, "\n")) !== false) {
					$rLine = substr($rBuffer, 0, $rPos);
					$rBuffer = substr($rBuffer, $rPos + 1);
					if (str_starts_with($rLine, 'out_time_us=') && is_numeric(substr($rLine, 12))) {
						$rSamples[] = [microtime(true) - $rStart, ((int) substr($rLine, 12)) / 1e6];
					}
				}
			}
			if (feof($rPipes[1]) && feof($rPipes[2])) {
				break;
			}
			if (microtime(true) - $rStart >= $rSeconds) {
				$rStopped = true;
				proc_terminate($rProc, 9);
				break;
			}
		}
		fclose($rPipes[1]);
		fclose($rPipes[2]);
		$rCode = proc_close($rProc);
		return [$rStopped ? -1 : $rCode, $rSamples, $rErr];
	}

	private static function why(int $rCode, string $rErr, string $rWhat): string {
		if ($rCode === 124) {
			return $rWhat . ': timed out';
		}
		$rLine = trim((string) strtok(trim($rErr), "\n"));
		// ffmpeg errors repeat the URL, which carries the account: keep it out;
		// and drop its "[tcp @ 0x55…]" context prefix.
		$rLine = (string) preg_replace('~[a-z]+://\S+~i', '<source>', $rLine);
		$rLine = trim((string) preg_replace('~\[[\w-]+ @ 0x[0-9a-f]+\]\s*~i', '', $rLine));
		return mb_substr($rWhat . ($rLine !== '' ? ': ' . $rLine : ' (exit ' . $rCode . ')'), 0, 250);
	}

	/**
	 * @param list<string> $rCommand
	 * @return array{0:int, 1:string, 2:string}
	 */
	private static function exec(array $rCommand): array {
		$rProc = proc_open($rCommand, [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $rPipes);
		if (!is_resource($rProc)) {
			return [127, '', 'could not start ' . $rCommand[0]];
		}
		$rOut = (string) stream_get_contents($rPipes[1]);
		$rErr = (string) stream_get_contents($rPipes[2], 65536);
		fclose($rPipes[1]);
		fclose($rPipes[2]);
		return [proc_close($rProc), $rOut, $rErr];
	}
}
