<?php

namespace XcVm\Module\SourceHealth;

/**
 * A provider account behind a source, as an Xtream-Codes-style panel
 * (Xtream, XUI.one, XC_VM itself) reports it: player_api.php answers with
 * user_info — auth, status, max_connections, active_cons, exp_date — without
 * opening a stream, so it costs the account no connection.
 *
 * Sources look like http://host:port/live/<user>/<pass>/<id>.ts or
 * http://host:port/<user>/<pass>/<id>; anything else has no account we can
 * ask about, and the safe rule (never touch an account a live stream uses)
 * stays in force for it.
 *
 * parse(), fromApi() and admits() are PURE; fetch() does the I/O.
 */
final class ProviderAccount {
	/** Account info older than this is not trusted to let a check share an account with a live stream. */
	public const FRESH_SEC = 300;
	/** Account info is refreshed this often for accounts nothing is on air on (expiry, status). */
	public const REFRESH_SEC = 6 * 3600;
	/** Connections kept free beyond the check's own: providers count a closed connection for a few seconds more. */
	public const SPARE = 1;

	/** @return array{key:string, host:string, base:string, user:string, pass:string}|null */
	public static function parse(string $rUrl): ?array {
		$rParts = parse_url(trim($rUrl));
		if (!is_array($rParts) || empty($rParts['host']) || !in_array(strtolower((string) ($rParts['scheme'] ?? '')), ['http', 'https'], true)) {
			return null;
		}
		$rSegs = array_values(array_filter(explode('/', (string) ($rParts['path'] ?? '')), static fn (string $s): bool => $s !== ''));
		if (count($rSegs) >= 4 && in_array(strtolower($rSegs[0]), SourceProber::KINDS, true)) {
			[$rUser, $rPass] = [$rSegs[1], $rSegs[2]];
		} elseif (count($rSegs) === 3) {
			[$rUser, $rPass] = [$rSegs[0], $rSegs[1]];
		} else {
			return null;
		}
		$rKey = SourceProber::accountKey($rUrl);
		if ($rKey === null) {
			return null;
		}
		$rBase = strtolower((string) $rParts['scheme']) . '://' . $rParts['host'] . (isset($rParts['port']) ? ':' . $rParts['port'] : '');
		return ['key' => $rKey, 'host' => strtolower((string) $rParts['host']), 'base' => $rBase, 'user' => rawurldecode($rUser), 'pass' => rawurldecode($rPass)];
	}

	/**
	 * @param mixed $rJson decoded player_api answer
	 * @return array{api:bool, auth:?bool, status:string, max:?int, active:?int, exp:?int}
	 */
	public static function fromApi(mixed $rJson): array {
		$rInfo = is_array($rJson) && is_array($rJson['user_info'] ?? null) ? $rJson['user_info'] : null;
		if ($rInfo === null) {
			return ['api' => false, 'auth' => null, 'status' => '', 'max' => null, 'active' => null, 'exp' => null];
		}
		$rNum = static fn ($v): ?int => is_numeric($v) ? (int) $v : null;
		return [
			'api'    => true,
			'auth'   => isset($rInfo['auth']) ? (bool) (int) $rInfo['auth'] : true,
			'status' => mb_substr(trim((string) ($rInfo['status'] ?? '')), 0, 20),
			'max'    => $rNum($rInfo['max_connections'] ?? null),
			'active' => $rNum($rInfo['active_cons'] ?? null),
			'exp'    => ($e = $rNum($rInfo['exp_date'] ?? null)) !== null && $e > 0 ? $e : null,
		];
	}

	/**
	 * Why a source on this account cannot work whatever the stream does, or
	 * null: the account itself is refused, expired, banned or disabled. Worded
	 * so SourceHealthCalculator::reasonKind() calls it 'gone'.
	 *
	 * @param array{api:bool, auth:?bool, status:string, exp:?int}|null $rAcct
	 */
	public static function deadReason(?array $rAcct, int $rNow): ?string {
		if ($rAcct === null || !$rAcct['api']) {
			return null;
		}
		if ($rAcct['auth'] === false) {
			return 'account: invalid login (the provider refused it)';
		}
		$rStatus = strtolower($rAcct['status']);
		if ($rStatus !== '' && $rStatus !== 'active') {
			return 'account: ' . ($rStatus === 'expired' ? 'expired' : ($rStatus === 'banned' ? 'banned' : ($rStatus === 'disabled' ? 'disabled' : 'not allowed (' . $rStatus . ')')));
		}
		if ($rAcct['exp'] !== null && $rAcct['exp'] < $rNow) {
			return 'account: expired on ' . date('Y-m-d', $rAcct['exp']);
		}
		return null;
	}

	/**
	 * Whether this panel's own streams crowd a provider account: providers cap
	 * connections per account, and past the cap they cut a connection or refuse
	 * the next one, so streams on one account knock each other off ("source
	 * idle", "use of closed network connection", "source ended", 404, an empty
	 * body). $rOurs is how many live connections this panel holds on it.
	 *
	 * @param array{api:bool, max:?int, active:?int}|null $rAcct
	 * @return array{level:string, text:string}|null over | full | shared, or null when there is room or nothing is known
	 */
	public static function contention(int $rOurs, ?array $rAcct): ?array {
		if ($rAcct === null || !$rAcct['api'] || $rAcct['max'] === null || $rAcct['max'] <= 0) {
			return null;
		}
		$rMax = $rAcct['max'];
		$rActive = (int) ($rAcct['active'] ?? 0);
		return match (true) {
			$rOurs > $rMax   => ['level' => 'over', 'text' => 'your streams hold ' . $rOurs . ' connections on an account allowed ' . $rMax . ': they cut each other off'],
			$rOurs === $rMax => ['level' => 'full', 'text' => 'your streams use all ' . $rMax . ' connection(s) of the account: a restart, or a backup on it, is refused or cuts one'],
			$rActive >= $rMax => ['level' => 'shared', 'text' => 'the account is full (' . $rActive . '/' . $rMax . '), ' . max(0, $rActive - $rOurs) . ' of them not from this panel: something else uses it too'],
			default          => null,
		};
	}

	/**
	 * May a check take one connection on an account a live stream is using?
	 * Only on fresh word from the provider that a connection is free beyond
	 * the check's own (and SPARE more).
	 *
	 * @param array{api:bool, max:?int, active:?int, checked_at:int}|null $rAcct
	 */
	public static function admits(?array $rAcct, int $rNow): bool {
		return $rAcct !== null && $rAcct['api'] && $rNow - (int) $rAcct['checked_at'] <= self::FRESH_SEC
			&& $rAcct['max'] !== null && $rAcct['max'] > 0 && $rAcct['active'] !== null
			&& $rAcct['active'] + 1 + self::SPARE <= $rAcct['max'];
	}

	/**
	 * Ask the provider. The URL carries the account, so it is never logged or
	 * stored; only the answer is.
	 *
	 * @param array{base:string, user:string, pass:string} $rAcct
	 * @param array{user_agent?:string, http_proxy?:string} $rFetch
	 * @return array{api:bool, auth:?bool, status:string, max:?int, active:?int, exp:?int}
	 */
	public static function fetch(array $rAcct, array $rFetch = []): array {
		if (!function_exists('curl_init')) {
			return self::fromApi(null);
		}
		$ch = curl_init($rAcct['base'] . '/player_api.php?username=' . rawurlencode($rAcct['user']) . '&password=' . rawurlencode($rAcct['pass']));
		curl_setopt_array($ch, [
			CURLOPT_RETURNTRANSFER => true,
			CURLOPT_FOLLOWLOCATION => true,
			CURLOPT_MAXREDIRS      => 3,
			CURLOPT_CONNECTTIMEOUT => 5,
			CURLOPT_TIMEOUT        => 8,
			CURLOPT_USERAGENT      => (string) ($rFetch['user_agent'] ?? 'Mozilla/5.0'),
		]);
		if (!empty($rFetch['http_proxy'])) {
			curl_setopt($ch, CURLOPT_PROXY, (string) $rFetch['http_proxy']);
		}
		$rBody = curl_exec($ch);
		$rCode = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
		curl_close($ch);
		return self::fromApi($rCode === 200 && is_string($rBody) && strlen($rBody) < 65536 ? json_decode($rBody, true) : null);
	}
}
