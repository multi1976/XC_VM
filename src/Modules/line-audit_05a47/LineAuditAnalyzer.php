<?php

namespace XcVm\Module\LineAudit;

/**
 * The judgements of Line Audit, from data already read. PURE: no I/O.
 *
 * Shared account: one line used from several places. Concurrent use from two
 * countries is the strongest sign (a line cannot be in two countries at once);
 * several IPs at the same time, many countries, ISPs or IPs over a day add to
 * it. A restreamer is expected to look like this and is judged apart.
 */
final class LineAuditAnalyzer {
	/** Samples are taken every five minutes: a sample stands for that long. */
	public const SAMPLE_SEC = 300;

	/**
	 * The five-minute picture of each line worth recording: two connections or
	 * more, or at its limit. Counts, countries and ISPs only; no IP address.
	 *
	 * @param list<array{user_id:int, ip:string, country:string, isp:string, ua:string}> $rConnections
	 * @param array<int, array<string, mixed>> $rLines line id => line (max_connections)
	 * @return list<array{sampled_at:int, user_id:int, conns:int, max_conn:int, ips:int, countries:int, isps:int, uas:int, country_list:string, isp_list:string}>
	 */
	public static function samples(array $rConnections, array $rLines, int $rNow): array {
		$rBy = [];
		foreach ($rConnections as $c) {
			$rBy[$c['user_id']][] = $c;
		}
		$rOut = [];
		foreach ($rBy as $rUserID => $rList) {
			$rMax = (int) ($rLines[$rUserID]['max_connections'] ?? 0);
			$rConns = count($rList);
			if ($rConns < 2 && !($rMax > 0 && $rConns >= $rMax)) {
				continue;
			}
			$rDistinct = static fn (string $k): array => array_values(array_unique(array_filter(array_column($rList, $k), static fn ($v): bool => $v !== '')));
			$rCountries = $rDistinct('country');
			$rIsps = $rDistinct('isp');
			sort($rCountries);
			sort($rIsps);
			$rOut[] = [
				'sampled_at' => $rNow, 'user_id' => (int) $rUserID, 'conns' => $rConns, 'max_conn' => $rMax,
				'ips' => count($rDistinct('ip')), 'countries' => count($rCountries), 'isps' => count($rIsps), 'uas' => count($rDistinct('ua')),
				'country_list' => implode(',', $rCountries), 'isp_list' => implode(' | ', $rIsps),
			];
		}
		return $rOut;
	}

	/**
	 * How strongly a line looks shared, and why.
	 *
	 * @param array{samples:int, multi_ip:int, multi_country:int, max_ips:int, max_countries:int}|null $rSamples five-minute samples (concurrent use)
	 * @param array{ips:int, countries:int, isps:int}|null $rHistory sessions in the window
	 * @return array{score:int, level:string, reasons:list<string>}
	 */
	public static function sharing(?array $rSamples, ?array $rHistory): array {
		$rScore = 0;
		$rWhy = [];
		if ($rSamples !== null && $rSamples['multi_country'] > 0) {
			$rScore += 3;
			$rWhy[] = 'from ' . $rSamples['max_countries'] . ' countries at the same time (' . self::duration($rSamples['multi_country'] * self::SAMPLE_SEC) . ')';
		}
		if ($rSamples !== null && $rSamples['multi_ip'] >= 3) {
			$rScore += 2;
			$rWhy[] = 'from up to ' . $rSamples['max_ips'] . ' IPs at the same time (' . self::duration($rSamples['multi_ip'] * self::SAMPLE_SEC) . ')';
		}
		if ($rHistory !== null && $rHistory['countries'] >= 2) {
			$rScore += 2;
			$rWhy[] = $rHistory['countries'] . ' countries';
		}
		if ($rHistory !== null && $rHistory['isps'] >= 3) {
			$rScore += 1;
			$rWhy[] = $rHistory['isps'] . ' ISPs';
		}
		if ($rHistory !== null && $rHistory['ips'] >= 8) {
			$rScore += 1;
			$rWhy[] = $rHistory['ips'] . ' IPs';
		}
		$rLevel = match (true) {
			$rScore >= 4 => 'likely',
			$rScore >= 2 => 'suspicious',
			$rScore >= 1 => 'note',
			default      => 'none',
		};
		return ['score' => $rScore, 'level' => $rLevel, 'reasons' => $rWhy];
	}

	/**
	 * A client that keeps dropping: many sessions under a minute. A bad
	 * connection, a player that reconnects in a loop, or a stream that fails
	 * for everyone (look at the stream, then).
	 *
	 * @param array{sessions:int, short:int}|null $rHistory
	 */
	public static function unstable(?array $rHistory): bool {
		return $rHistory !== null && $rHistory['short'] >= 20 && $rHistory['short'] * 2 >= $rHistory['sessions'];
	}

	/** Refusals that say the line itself is the problem (not a stream, not the server). */
	public const LINE_REFUSALS = ['USER_EXPIRED', 'USER_DISABLED', 'USER_BAN', 'AUTH_FAILED', 'IP_BAN', 'IP_MISMATCH', 'COUNTRY_DISALLOW', 'ISP_LOCK_FAILED',
		'USER_AGENT_BAN', 'EMPTY_UA', 'BLOCKED_ASN', 'PROXY_DETECT', 'HOSTING_DETECT', 'RESTREAM_DETECT', 'NOT_IN_BOUQUET', 'USER_DISALLOW_EXT', 'TOKEN_EXPIRED'];

	public static function duration(int $rSeconds): string {
		return match (true) {
			$rSeconds < 3600  => max(1, intdiv($rSeconds, 60)) . 'm',
			$rSeconds < 86400 => intdiv($rSeconds, 3600) . 'h' . ($rSeconds % 3600 >= 60 ? ' ' . intdiv($rSeconds % 3600, 60) . 'm' : ''),
			default           => intdiv($rSeconds, 86400) . 'd ' . intdiv($rSeconds % 86400, 3600) . 'h',
		};
	}
}
