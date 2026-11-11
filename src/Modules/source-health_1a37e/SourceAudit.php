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
 *   - a source on a provider account a live stream is using is checked only
 *     when the provider's own player_api says, within the last five minutes,
 *     that the account has a connection free beyond the check's (and one
 *     spare), one such check per account per run; when the provider cannot
 *     be asked, it is not checked at all.
 * The provider's answer also catches an expired, banned or refused account:
 * its sources fail without a connection being opened.
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
	 * Which sources to check in this run: plan() = admit(due()).
	 *
	 * @param array<string, array{source:string, stream_id:int}> $rSources md5(source) => one stream that has it
	 * @param array<string, array{checked_at:int, status:string}> $rState md5 => last verdict
	 * @param list<string> $rOnAir every source on air on the panel
	 * @param array<string, array<string, mixed>> $rAccounts md5(account key) => what the provider said, with checked_at
	 * @return list<array{hash:string, source:string, stream_id:int, account:?string, busy:bool}>
	 */
	public static function plan(array $rSources, array $rState, array $rOnAir, int $rNow, int $rMax = self::MAX_PER_RUN, int $rPerHost = self::PER_HOST_PER_RUN, array $rAccounts = []): array {
		return self::admit(self::due($rSources, $rState, $rOnAir, $rNow), $rAccounts, $rNow, $rMax, $rPerHost);
	}

	/**
	 * Every source that is due, oldest check first; never one on air. `busy`:
	 * a live stream is using its provider account.
	 *
	 * @return list<array{hash:string, source:string, stream_id:int, account:?string, busy:bool}>
	 */
	public static function due(array $rSources, array $rState, array $rOnAir, int $rNow): array {
		$rLive = array_flip(array_map(static fn (string $s): string => md5(trim($s)), $rOnAir));
		$rBusy = self::busyAccounts($rOnAir);
		$rDue = [];
		foreach ($rSources as $rHash => $rItem) {
			$rHash = (string) $rHash;
			if (isset($rLive[$rHash]) || !self::checkable($rItem['source'])) {
				continue;
			}
			$rPrev = $rState[$rHash] ?? null;
			$rEvery = in_array($rPrev['status'] ?? '', ['ok', 'dead'], true) ? self::OK_INTERVAL_SEC : self::FAIL_INTERVAL_SEC;
			if ($rPrev !== null && $rNow - (int) $rPrev['checked_at'] < $rEvery) {
				continue;
			}
			$rAccount = SourceProber::accountKey($rItem['source']);
			$rDue[] = ['hash' => $rHash, 'source' => $rItem['source'], 'stream_id' => (int) $rItem['stream_id'], 'account' => $rAccount,
				'busy' => $rAccount !== null && isset($rBusy[$rAccount]), 'last' => (int) ($rPrev['checked_at'] ?? 0)];
		}
		usort($rDue, static fn (array $a, array $b): int => $a['last'] <=> $b['last']);
		return array_map(static function (array $r): array {
			unset($r['last']);
			return $r;
		}, $rDue);
	}

	/**
	 * The checks this run may make. A source on an account a live stream is
	 * using goes only on fresh word from the provider that a connection is
	 * free (ProviderAccount::admits), and only one per such account per run.
	 *
	 * @param list<array{hash:string, source:string, stream_id:int, account:?string, busy:bool}> $rDue
	 * @param array<string, array<string, mixed>> $rAccounts md5(account key) => provider info
	 */
	public static function admit(array $rDue, array $rAccounts, int $rNow, int $rMax = self::MAX_PER_RUN, int $rPerHost = self::PER_HOST_PER_RUN): array {
		$rOut = [];
		$rPerHostCount = [];
		$rSharedTaken = [];
		foreach ($rDue as $rItem) {
			if ($rItem['busy']) {
				$rAcct = $rAccounts[md5((string) $rItem['account'])] ?? null;
				if (isset($rSharedTaken[$rItem['account']]) || !ProviderAccount::admits($rAcct, $rNow)) {
					continue;
				}
			}
			$rHost = self::host($rItem['source']);
			if (($rPerHostCount[$rHost] ?? 0) >= $rPerHost) {
				continue;
			}
			$rPerHostCount[$rHost] = ($rPerHostCount[$rHost] ?? 0) + 1;
			if ($rItem['busy']) {
				$rSharedTaken[$rItem['account']] = true;
			}
			$rOut[] = $rItem;
			if (count($rOut) >= $rMax) {
				break;
			}
		}
		return $rOut;
	}

	/** @param list<string> $rOnAir @return array<string, true> account keys a live stream is using */
	public static function busyAccounts(array $rOnAir): array {
		$rBusy = [];
		foreach ($rOnAir as $rSource) {
			$rKey = SourceProber::accountKey($rSource);
			if ($rKey !== null) {
				$rBusy[$rKey] = true;
			}
		}
		return $rBusy;
	}

	/**
	 * Accounts to ask the provider about before admitting, in due order: a
	 * busy one whose word is older than FRESH_SEC, an idle one older than
	 * REFRESH_SEC (an expired account fails every source on it).
	 *
	 * @param list<array{source:string, account:?string, busy:bool}> $rDue
	 * @return list<string> account keys, at most $rMax, at most $rPerHost per host
	 */
	public static function accountsToAsk(array $rDue, array $rAccounts, int $rNow, int $rMax = 20, int $rPerHost = 3): array {
		$rOut = [];
		$rPerHostCount = [];
		foreach ($rDue as $rItem) {
			$rKey = $rItem['account'];
			if ($rKey === null || isset($rOut[$rKey]) || ProviderAccount::parse($rItem['source']) === null) {
				continue;
			}
			$rAge = $rNow - (int) ($rAccounts[md5($rKey)]['checked_at'] ?? 0);
			if ($rAge <= ($rItem['busy'] ? ProviderAccount::FRESH_SEC : ProviderAccount::REFRESH_SEC)) {
				continue;
			}
			$rHost = self::host($rItem['source']);
			if (($rPerHostCount[$rHost] ?? 0) >= $rPerHost) {
				continue;
			}
			$rPerHostCount[$rHost] = ($rPerHostCount[$rHost] ?? 0) + 1;
			$rOut[$rKey] = $rItem['source'];
			if (count($rOut) >= $rMax) {
				break;
			}
		}
		return array_keys($rOut);
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
		$rDue = self::due($rSources, $rState, SourceHealthRepository::onAirSources(), $rNow);
		$rStarted = time();

		// Ask the providers first (player_api: no stream, no connection used).
		$rAccounts = SourceHealthRepository::accounts();
		$rBySource = [];
		foreach ($rDue as $rItem) {
			$rBySource[(string) $rItem['account']] ??= $rItem;
		}
		foreach (self::accountsToAsk($rDue, $rAccounts, $rNow) as $rKey) {
			if (time() - $rStarted > self::RUN_BUDGET_SEC / 3) {
				break;
			}
			$rItem = $rBySource[$rKey];
			$rAcct = ProviderAccount::parse($rItem['source']);
			$rInfo = ProviderAccount::fetch($rAcct, SourceHealthRepository::fetchOptions($rItem['stream_id']));
			SourceHealthRepository::saveAccount(md5($rKey), $rAcct['host'], $rInfo, time());
			$rAccounts[md5($rKey)] = $rInfo + ['checked_at' => time()];
		}
		SourceHealthRepository::pruneAccounts($rNow);

		$rDone = 0;
		foreach (self::admit($rDue, $rAccounts, time()) as $rItem) {
			if (time() - $rStarted > self::RUN_BUDGET_SEC) {
				break;
			}
			self::checkOne($rItem, $rState[$rItem['hash']] ?? null, $rAccounts[md5((string) $rItem['account'])] ?? null);
			$rDone++;
		}
		return $rDone;
	}

	/**
	 * An admin's "recheck" of one source, now, under the same rules as the
	 * cron: never the source on air, and on an account a live stream is using
	 * only when the provider says a connection is free.
	 *
	 * @return array{ok:bool, message:string, verdict?:array<string, mixed>}
	 */
	public static function recheckNow(int $rStreamID, string $rHash): array {
		$rSource = null;
		foreach (SourceHealthRepository::liveStreams() as $rStream) {
			if ($rStream['id'] === $rStreamID) {
				foreach ($rStream['sources'] as $rCandidate) {
					if ($rCandidate !== '' && md5($rCandidate) === $rHash) {
						$rSource = $rCandidate;
					}
				}
			}
		}
		if ($rSource === null) {
			return ['ok' => false, 'message' => 'That source is no longer in this stream.'];
		}
		if (!self::checkable($rSource)) {
			return ['ok' => false, 'message' => 'Only http(s) sources are checked.'];
		}
		$rOnAir = SourceHealthRepository::onAirSources();
		if (in_array($rSource, array_map('trim', $rOnAir), true)) {
			return ['ok' => false, 'message' => 'It is on air right now: up, and not touched.'];
		}
		$rKey = SourceProber::accountKey($rSource);
		$rAcct = ProviderAccount::parse($rSource);
		$rInfo = null;
		if ($rAcct !== null) {
			$rInfo = ProviderAccount::fetch($rAcct, SourceHealthRepository::fetchOptions($rStreamID));
			SourceHealthRepository::saveAccount(md5((string) $rKey), $rAcct['host'], $rInfo, time());
			$rInfo += ['checked_at' => time()];
		}
		if ($rKey !== null && isset(self::busyAccounts($rOnAir)[$rKey]) && !ProviderAccount::admits($rInfo, time())) {
			return ['ok' => false, 'message' => 'Its provider account is in use by a live stream'
				. ($rInfo !== null && $rInfo['api'] && $rInfo['max'] !== null ? ' and has no connection to spare (' . (int) $rInfo['active'] . '/' . (int) $rInfo['max'] . ' in use)' : ', and the provider could not be asked whether a connection is free')
				. ': not checked, so the live line is not disturbed.'];
		}
		$rVerdict = self::checkOne(['hash' => $rHash, 'source' => $rSource, 'stream_id' => $rStreamID], SourceHealthRepository::auditState()[$rHash] ?? null, $rInfo);
		return ['ok' => true, 'message' => strtoupper($rVerdict['status']) . ($rVerdict['reason'] !== '' ? ': ' . $rVerdict['reason'] : ''), 'verdict' => $rVerdict];
	}

	/**
	 * Check one source and store the verdict. An account the provider says is
	 * expired, banned or refused fails its sources without a connection.
	 *
	 * @param array{hash:string, source:string, stream_id:int} $rItem
	 * @return array{status:string, kind:?string, reason:string, fails:int, first_fail:int, last_ok:int}
	 */
	public static function checkOne(array $rItem, ?array $rPrev, ?array $rAccount): array {
		$rDead = ProviderAccount::deadReason($rAccount, time());
		$rCheck = $rDead !== null ? ['ok' => false, 'error' => $rDead, 'ms' => null] : self::check($rItem['source'], SourceHealthRepository::fetchOptions($rItem['stream_id']));
		$rAt = time();
		$rVerdict = self::verdict($rPrev, $rCheck, $rAt);
		SourceHealthRepository::saveAudit($rItem['hash'], self::host($rItem['source']), $rVerdict, $rCheck['ms'], $rAt);
		return $rVerdict;
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
