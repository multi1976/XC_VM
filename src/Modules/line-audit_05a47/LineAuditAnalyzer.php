<?php

namespace XcVm\Module\LineAudit;

/**
 * The judgements of Line Audit, from data already read. PURE: no I/O.
 *
 * Shared account: one line used from several places. Concurrent use from two
 * countries is the strongest sign (a line cannot be in two countries at once);
 * several IPs at the same time, many countries, ISPs or IPs over a day add to
 * it. On a line with few connections sharing rarely shows as use at once: the
 * panel closes the older connection when a new one opens, so two places take
 * the line from each other, back and forth (handovers). A restreamer is
 * expected to look like this and is judged apart.
 *
 * Players: user agents are grouped into families (all versions of TiviMate are
 * one), since a household with a TV, a phone and a tablet legitimately shows
 * two or three; many families, or several at once, add to the score. A
 * restreaming tool (ffmpeg/Lavf, curl, wget, a script) on a line that is not a
 * restreamer is a strong sign on its own.
 */
final class LineAuditAnalyzer {
	/** Returns (IP A, then B, then A again, each opening as the other closes) that make a line suspicious… */
	public const HANDOVER_RETURNS = 3;
	/** …and that are enough on their own: few devices go back and forth that often within seconds. */
	public const HANDOVER_RETURNS_MANY = 10;

	/** User agent => player family, first match wins. */
	private const PLAYER_FAMILIES = [
		'~lavf|libavformat|ffmpeg|ffprobe~i'         => 'ffmpeg / Lavf',
		'~^curl/~i'                                  => 'curl',
		'~^wget/~i'                                  => 'wget',
		'~python-requests|python-urllib|aiohttp~i'   => 'Python script',
		'~^go-http-client~i'                         => 'Go script',
		'~^(java/|apache-httpclient)~i'              => 'Java client',
		'~tivimate~i'                                => 'TiviMate',
		'~smarters~i'                                => 'IPTV Smarters',
		'~ott ?navigator~i'                          => 'OTT Navigator',
		'~perfect ?player~i'                         => 'Perfect Player',
		'~kodi|xbmc~i'                               => 'Kodi',
		'~\bvlc\b|libvlc~i'                        => 'VLC',
		'~\bgse\b~i'                               => 'GSE Smart IPTV',
		'~mag ?\d{3}|stbapp|infomir~i'              => 'MAG box',
		'~enigma2|gstreamer|dreambox|vu\+~i'        => 'Enigma2',
		'~applecoremedia|appletv|tvos~i'             => 'Apple player',
		'~exoplayer|okhttp|dalvik~i'                 => 'Android player',
		'~smart-?tv|tizen|webos|netcast|hbbtv~i'     => 'Smart TV',
		'~chrome|firefox|safari|edg/|opera~i'        => 'Web browser',
	];
	/** Families that are tools, not players: what restreaming uses. */
	public const TOOLS = ['ffmpeg / Lavf', 'curl', 'wget', 'Python script', 'Go script', 'Java client'];

	/** The player family of a user agent ("(none)" when it sends none). */
	public static function playerFamily(string $rUserAgent): string {
		$rUserAgent = trim($rUserAgent);
		if ($rUserAgent === '') {
			return '(none)';
		}
		foreach (self::PLAYER_FAMILIES as $rPattern => $rFamily) {
			if (preg_match($rPattern, $rUserAgent) === 1) {
				return $rFamily;
			}
		}
		return 'Other';
	}

	/** Samples are taken every five minutes: a sample stands for that long. */
	public const SAMPLE_SEC = 300;

	/**
	 * The five-minute picture of each line worth recording: two connections or
	 * more, or at its limit. Counts, countries and ISPs only; no IP address.
	 *
	 * @param list<array{user_id:int, ip:string, country:string, isp:string, ua:string}> $rConnections
	 * @param array<int, array<string, mixed>> $rLines line id => line (max_connections)
	 * `uas` counts player families, not raw user agents (ua_list names them).
	 *
	 * @return list<array{sampled_at:int, user_id:int, conns:int, max_conn:int, ips:int, countries:int, isps:int, uas:int, country_list:string, isp_list:string, ua_list:string}>
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
			$rPlayers = array_values(array_unique(array_map([self::class, 'playerFamily'], array_column($rList, 'ua'))));
			sort($rPlayers);
			$rCountries = $rDistinct('country');
			$rIsps = $rDistinct('isp');
			sort($rCountries);
			sort($rIsps);
			$rOut[] = [
				'sampled_at' => $rNow, 'user_id' => (int) $rUserID, 'conns' => $rConns, 'max_conn' => $rMax,
				'ips' => count($rDistinct('ip')), 'countries' => count($rCountries), 'isps' => count($rIsps), 'uas' => count($rPlayers),
				'country_list' => implode(',', $rCountries), 'isp_list' => implode(' | ', $rIsps), 'ua_list' => implode(', ', $rPlayers),
			];
		}
		return $rOut;
	}

	/**
	 * How strongly a line looks shared, and why.
	 *
	 * @param array{samples:int, multi_ip:int, multi_country:int, max_ips:int, max_countries:int}|null $rSamples five-minute samples (concurrent use)
	 * @param array{ips:int, countries:int, isps:int}|null $rHistory sessions in the window
	 * @param array<string, int> $rPlayers player family => sessions in the window
	 * @param array{handovers:int, returns:int}|null $rHandovers places taking the line from each other
	 * @return array{score:int, level:string, reasons:list<string>}
	 */
	public static function sharing(?array $rSamples, ?array $rHistory, array $rPlayers = [], ?array $rHandovers = null): array {
		$rScore = 0;
		$rWhy = [];
		$rTools = array_values(array_intersect(array_keys($rPlayers), self::TOOLS));
		if ($rTools !== []) {
			$rScore += 3;
			$rWhy[] = 'a restreaming tool or an ffmpeg-based player (' . implode(', ', $rTools) . ')';
		}
		if ($rSamples !== null && $rSamples['multi_country'] > 0) {
			$rScore += 3;
			$rWhy[] = 'from ' . $rSamples['max_countries'] . ' countries at the same time (' . self::duration($rSamples['multi_country'] * self::SAMPLE_SEC) . ')';
		}
		if ($rSamples !== null && $rSamples['multi_ip'] >= 3) {
			$rScore += 2;
			$rWhy[] = 'from up to ' . $rSamples['max_ips'] . ' IPs at the same time (' . self::duration($rSamples['multi_ip'] * self::SAMPLE_SEC) . ')';
		}
		if ($rSamples !== null && ($rSamples['max_uas'] ?? 0) >= 3) {
			$rScore += 1;
			$rWhy[] = $rSamples['max_uas'] . ' different players at the same time';
		}
		$rFamilies = array_values(array_diff(array_keys($rPlayers), ['(none)', 'Other']));
		if (count($rFamilies) >= 4) {
			$rScore += 1;
			$rWhy[] = count($rFamilies) . ' different players';
		}
		// The usual sign on a line with few connections: it is never used from
		// two places at once, the panel closes one when the other opens. Going
		// back and forth is not a phone moving from Wi-Fi to mobile data.
		if ($rHandovers !== null && $rHandovers['returns'] >= self::HANDOVER_RETURNS) {
			$rScore += $rHandovers['returns'] >= self::HANDOVER_RETURNS_MANY ? 4 : 2;
			$rWhy[] = 'two places took it from each other ' . $rHandovers['returns'] . ' times (' . $rHandovers['handovers'] . ' handovers between IPs)';
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

	/**
	 * Why a line keeps dropping, as far as its sessions tell.
	 *
	 * @param array{ips:int}|array<string, int> $rHistory
	 * @param array{handovers:int, returns:int}|null $rHand
	 */
	public static function unstableCause(array $rHistory, ?array $rHand): string {
		return match (true) {
			($rHand['returns'] ?? 0) >= self::HANDOVER_RETURNS => 'two places take the line from each other',
			(int) ($rHistory['ips'] ?? 0) <= 1                  => 'one place: its connection or its player',
			default                                            => 'a few IPs: a moving device, or look at the logs',
		};
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
