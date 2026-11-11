<?php

namespace XcVm\Module\SourceHealth;

/**
 * Source Audit: every source of every live stream on the panel, checked
 * lightly and seldom, so the sources that are off stand out — including the
 * backups far down a list that the panel never reaches (its on-demand scanner
 * stops at the first source that answers).
 *
 * A check is one HTTP request: follow redirects, read the first 2 KB (8 KB of
 * a web page) and close. TS sync bytes or a playlist is a source that is up;
 * a web page says why it is not (XUI.one: "Stream ID doesn't exist."). No
 * ffmpeg, a few KB of traffic, about a second.
 *
 * The line on air is never touched:
 *   - a source on air anywhere on the panel is not checked at all: it is up;
 *   - nor is a source on a provider account a live stream is using, since the
 *     check would take a connection from that account's limit.
 * Providers are spared too: a few checks per provider host per run, runs every
 * five minutes, a source that is up is checked once a day.
 *
 * A source is called dead only when the evidence is repeated: a reason that
 * says it is gone (no such host, an expired account, a removed channel) seen
 * twice, or failures spanning 12 hours; until then it is down.
 *
 * plan() and verdict() are PURE; run() and check() do the I/O.
 */
final class SourceAudit {
	public const OK_INTERVAL_SEC = 86400;
	public const FAIL_INTERVAL_SEC = 4 * 3600;
	public const DEAD_AFTER_SEC = 12 * 3600;
	public const MAX_PER_RUN = 60;
	public const PER_HOST_PER_RUN = 5;
	public const RUN_BUDGET_SEC = 45;
	private const HEAD_BYTES = 2048;
	private const PAGE_BYTES = 8192;

	/**
	 * Which sources to check in this run.
	 *
	 * @param array<string, array{source:string, stream_id:int}> $rSources md5(source) => one stream that has it
	 * @param array<string, array{checked_at:int, status:string}> $rState md5 => last verdict
	 * @param list<string> $rOnAir every source on air on the panel
	 * @return list<array{hash:string, source:string, stream_id:int}> oldest-due first
	 */
	public static function plan(array $rSources, array $rState, array $rOnAir, int $rNow, int $rMax = self::MAX_PER_RUN, int $rPerHost = self::PER_HOST_PER_RUN): array {
		$rLive = array_flip(array_map(static fn (string $s): string => md5(trim($s)), $rOnAir));
		$rBusy = [];
		foreach ($rOnAir as $rSource) {
			$rKey = SourceProber::accountKey($rSource);
			if ($rKey !== null) {
				$rBusy[$rKey] = true;
			}
		}
		$rDue = [];
		foreach ($rSources as $rHash => $rItem) {
			$rHash = (string) $rHash;
			if (isset($rLive[$rHash]) || !self::checkable($rItem['source']) || isset($rBusy[(string) SourceProber::accountKey($rItem['source'])])) {
				continue;
			}
			$rPrev = $rState[$rHash] ?? null;
			$rEvery = ($rPrev['status'] ?? '') === 'ok' || ($rPrev['status'] ?? '') === 'dead' ? self::OK_INTERVAL_SEC : self::FAIL_INTERVAL_SEC;
			if ($rPrev !== null && $rNow - (int) $rPrev['checked_at'] < $rEvery) {
				continue;
			}
			$rDue[] = ['hash' => $rHash, 'source' => $rItem['source'], 'stream_id' => (int) $rItem['stream_id'], 'last' => (int) ($rPrev['checked_at'] ?? 0)];
		}
		usort($rDue, static fn (array $a, array $b): int => $a['last'] <=> $b['last']);
		$rOut = [];
		$rPerHostCount = [];
		foreach ($rDue as $rItem) {
			$rHost = self::host($rItem['source']);
			if (($rPerHostCount[$rHost] ?? 0) >= $rPerHost) {
				continue;
			}
			$rPerHostCount[$rHost] = ($rPerHostCount[$rHost] ?? 0) + 1;
			unset($rItem['last']);
			$rOut[] = $rItem;
			if (count($rOut) >= $rMax) {
				break;
			}
		}
		return $rOut;
	}

	public static function checkable(string $rSource): bool {
		return in_array(strtolower((string) parse_url(trim($rSource), PHP_URL_SCHEME)), ['http', 'https'], true);
	}

	public static function host(string $rSource): string {
		return strtolower((string) parse_url(trim($rSource), PHP_URL_HOST));
	}

	/**
	 * The new state of a source after a check.
	 *
	 * @param array{fails?:int, first_fail?:int, last_ok?:int}|null $rPrev
	 * @param array{ok:bool, error:string} $rCheck
	 * @return array{status:string, kind:?string, reason:string, fails:int, first_fail:int, last_ok:int}
	 */
	public static function verdict(?array $rPrev, array $rCheck, int $rNow): array {
		if ($rCheck['ok']) {
			return ['status' => 'ok', 'kind' => null, 'reason' => $rCheck['error'], 'fails' => 0, 'first_fail' => 0, 'last_ok' => $rNow];
		}
		$rFails = (int) ($rPrev['fails'] ?? 0) + 1;
		$rFirst = (int) ($rPrev['first_fail'] ?? 0) ?: $rNow;
		$rKind = SourceHealthCalculator::reasonKind($rCheck['error']);
		$rDead = ($rKind === 'gone' && $rFails >= 2) || ($rFails >= 3 && $rNow - $rFirst >= self::DEAD_AFTER_SEC);
		return ['status' => $rDead ? 'dead' : 'down', 'kind' => $rKind, 'reason' => $rCheck['error'], 'fails' => $rFails, 'first_fail' => $rFirst, 'last_ok' => (int) ($rPrev['last_ok'] ?? 0)];
	}

	public static function run(int $rNow): int {
		$rSources = SourceHealthRepository::allLiveSources();
		SourceHealthRepository::pruneAudit(array_keys($rSources));
		$rState = SourceHealthRepository::auditState();
		$rDue = self::plan($rSources, $rState, SourceHealthRepository::onAirSources(), $rNow);
		$rStarted = time();
		$rDone = 0;
		foreach ($rDue as $rItem) {
			if (time() - $rStarted > self::RUN_BUDGET_SEC) {
				break;
			}
			$rCheck = self::check($rItem['source'], SourceHealthRepository::fetchOptions($rItem['stream_id']));
			$rAt = time();
			SourceHealthRepository::saveAudit($rItem['hash'], self::host($rItem['source']), self::verdict($rState[$rItem['hash']] ?? null, $rCheck, $rAt), $rCheck['ms'], $rAt);
			$rDone++;
		}
		return $rDone;
	}

	/**
	 * One light request: is the source serving media right now, and if not,
	 * what does it say. The error wording follows the encoder's own lines
	 * ("no such host", "http 404", "a web page, not a stream (…)") so one
	 * classification serves both.
	 *
	 * @param array{user_agent?:string, http_proxy?:string, cookies?:string} $rFetch
	 * @return array{ok: bool, error: string, ms: ?int}
	 */
	public static function check(string $rUrl, array $rFetch, int $rTimeout = 10): array {
		if (!function_exists('curl_init')) {
			return ['ok' => false, 'error' => 'curl is not available', 'ms' => null];
		}
		$rBody = '';
		$ch = curl_init($rUrl);
		curl_setopt_array($ch, [
			CURLOPT_FOLLOWLOCATION => true,
			CURLOPT_MAXREDIRS      => 5,
			CURLOPT_CONNECTTIMEOUT => 5,
			CURLOPT_TIMEOUT        => $rTimeout,
			CURLOPT_USERAGENT      => (string) ($rFetch['user_agent'] ?? 'Mozilla/5.0'),
			CURLOPT_WRITEFUNCTION  => static function ($ch, string $rData) use (&$rBody): int {
				$rBody .= $rData;
				$rPage = str_starts_with(strtolower((string) curl_getinfo($ch, CURLINFO_CONTENT_TYPE)), 'text/') || str_starts_with(ltrim($rBody, "\xef\xbb\xbf \t\r\n"), '<');
				return strlen($rBody) < ($rPage ? self::PAGE_BYTES : self::HEAD_BYTES) ? strlen($rData) : 0;
			},
		]);
		if (!empty($rFetch['http_proxy'])) {
			curl_setopt($ch, CURLOPT_PROXY, (string) $rFetch['http_proxy']);
		}
		if (!empty($rFetch['cookies'])) {
			curl_setopt($ch, CURLOPT_COOKIE, (string) $rFetch['cookies']);
		}
		$rStart = microtime(true);
		curl_exec($ch);
		$rErrno = curl_errno($ch);
		$rError = curl_error($ch);
		$rCode = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
		$rType = strtolower((string) curl_getinfo($ch, CURLINFO_CONTENT_TYPE));
		$rFirst = curl_getinfo($ch, CURLINFO_STARTTRANSFER_TIME);
		curl_close($ch);
		$rMs = $rCode > 0 ? (int) round(1000 * (float) ($rFirst ?: (microtime(true) - $rStart))) : null;
		return ['ms' => $rMs] + self::classify($rErrno, $rError, $rCode, $rType, $rBody);
	}

	/** @return array{ok: bool, error: string} */
	public static function classify(int $rErrno, string $rError, int $rCode, string $rType, string $rBody): array {
		// 23: the write callback stopped the transfer once it had enough — not an error.
		if ($rErrno !== 0 && $rErrno !== 23 && !($rErrno === 28 && $rBody !== '')) {
			return ['ok' => false, 'error' => match ($rErrno) {
				6       => 'no such host',
				7       => 'connection refused',
				28      => $rCode > 0 ? 'i/o timeout' : 'timeout awaiting response headers',
				47      => 'too many redirects',
				52      => 'empty reply from server',
				56      => 'connection reset by peer',
				default => 'network: ' . (string) preg_replace('~[a-z][a-z0-9+.-]*://\S+~i', '(url)', $rError),
			}];
		}
		if ($rCode >= 400 || $rCode === 0) {
			return ['ok' => false, 'error' => 'http ' . $rCode];
		}
		$rHead = ltrim($rBody, "\xef\xbb\xbf \t\r\n");
		if ($rBody === '') {
			return ['ok' => false, 'error' => 'empty body'];
		}
		if (strlen($rBody) >= 189 && $rBody[0] === "\x47" && $rBody[188] === "\x47") {
			return ['ok' => true, 'error' => ''];
		}
		if (str_starts_with($rHead, '#EXTM3U')) {
			return ['ok' => true, 'error' => 'playlist'];
		}
		if (str_starts_with($rType, 'text/') || str_starts_with($rHead, '<')) {
			$rWhy = SourceProber::pageReason($rBody);
			return ['ok' => false, 'error' => 'a web page, not a stream' . ($rWhy !== '' ? ' (' . $rWhy . ')' : '')];
		}
		// Media, but not MPEG-TS (FLV, MP4, a TS that does not start on a packet):
		// it answers; whether the panel can use it is the encoder's business.
		return ['ok' => true, 'error' => 'not mpegts' . ($rType !== '' ? ' (' . mb_substr($rType, 0, 40) . ')' : '')];
	}
}
