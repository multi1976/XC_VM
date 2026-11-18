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

	/**
	 * One client program, whatever its version: the player family, or for an
	 * unknown agent its text with the numbers taken out ("MyPanel/2.1" and
	 * "MyPanel/2.3" are one program).
	 */
	public static function uaKey(string $rUserAgent): string {
		$rFamily = self::playerFamily($rUserAgent);
		if ($rFamily !== 'Other') {
			return $rFamily;
		}
		return 'other:' . strtolower((string) preg_replace('~\d[\d._]*~', '#', mb_substr(trim($rUserAgent), 0, 60)));
	}

	/** Families a viewer watches with: on a restream line, a person is using it. */
	public static function isPlayer(string $rFamily): bool {
		return !in_array($rFamily, self::TOOLS, true) && $rFamily !== 'Other' && $rFamily !== '(none)';
	}

	/**
	 * A restreamer line judged as one: another panel pulling channels, with one
	 * program (one user agent), from one place. Handovers and several
	 * connections are what it is for and count for nothing here. What does
	 * count: a viewer's player on it (the line is watched by a person too),
	 * a second program, pulls from two ISPs at once, and a user agent or an
	 * ISP it never used before the window.
	 *
	 * @param list<array{ua:string, first_seen:int, last_seen:int}> $rUas every user agent the line has pulled with
	 * @param list<array{isp:string, first_seen:int, last_seen:int}> $rIsps every ISP it has been used from
	 * @param list<array{ip:string, isp:string, ua:string}> $rLive its connections now
	 * @return array{score:int, level:string, reasons:list<string>, uas:list<array{ua:string, family:string, new:bool, now:bool}>, isps:list<array{isp:string, new:bool, now:bool}>, ips_now:int, isps_now:int}
	 */
	public static function restreamer(array $rUas, array $rIsps, array $rLive, int $rFrom): array {
		$rNowUas = array_flip(array_map(static fn (array $c): string => trim((string) $c['ua']), $rLive));
		$rNowIsps = array_flip(array_filter(array_map(static fn (array $c): string => trim((string) $c['isp']), $rLive)));
		$rIpsNow = count(array_unique(array_filter(array_column($rLive, 'ip'))));
		// A program counts as new only if no version of it was seen before the window.
		$rOldKeys = [];
		foreach ($rUas as $u) {
			if ($u['first_seen'] < $rFrom) {
				$rOldKeys[self::uaKey($u['ua'])] = true;
			}
		}
		$rHadUa = $rOldKeys !== [];
		$rHadIsp = (bool) array_filter($rIsps, static fn (array $i): bool => $i['first_seen'] < $rFrom);

		$rUaRows = [];
		$rKeys = [];
		foreach ($rUas as $u) {
			if ($u['last_seen'] < $rFrom && !isset($rNowUas[$u['ua']])) {
				continue;
			}
			$rFamily = self::playerFamily($u['ua']);
			$rKeys[self::uaKey($u['ua'])] = $rFamily;
			$rUaRows[] = ['ua' => $u['ua'], 'family' => $rFamily, 'new' => $rHadUa && $u['first_seen'] >= $rFrom && !isset($rOldKeys[self::uaKey($u['ua'])]), 'now' => isset($rNowUas[$u['ua']])];
		}
		$rIspRows = [];
		foreach ($rIsps as $i) {
			if ($i['last_seen'] < $rFrom && !isset($rNowIsps[$i['isp']])) {
				continue;
			}
			$rIspRows[] = ['isp' => $i['isp'], 'new' => $rHadIsp && $i['first_seen'] >= $rFrom, 'now' => isset($rNowIsps[$i['isp']])];
		}

		$rScore = 0;
		$rWhy = [];
		$rPlayers = array_values(array_unique(array_filter($rKeys, [self::class, 'isPlayer'])));
		if ($rPlayers !== []) {
			$rScore += 3;
			$rWhy[] = 'a viewer\'s player on a restream line: ' . implode(', ', $rPlayers);
		}
		if (count($rKeys) >= 2) {
			$rScore += 2;
			$rWhy[] = count($rKeys) . ' different programs (a restream panel pulls with one)';
		}
		if (count($rNowIsps) >= 2) {
			$rScore += 2;
			$rWhy[] = 'pulled from ' . count($rNowIsps) . ' ISPs at once';
		} elseif (count($rIspRows) >= 2) {
			$rScore += 1;
			$rWhy[] = count($rIspRows) . ' ISPs in the window';
		}
		$rNewUa = array_values(array_filter($rUaRows, static fn (array $u): bool => $u['new']));
		if ($rNewUa !== []) {
			$rScore += 1;
			$rWhy[] = 'a user agent it never used before: ' . self::uaKeyLabel($rNewUa[0]['ua']);
		}
		$rNewIsp = array_values(array_filter($rIspRows, static fn (array $i): bool => $i['new']));
		if ($rNewIsp !== []) {
			$rScore += 1;
			$rWhy[] = 'an ISP it never used before: ' . $rNewIsp[0]['isp'];
		}
		return [
			'score' => $rScore, 'level' => $rScore >= 3 ? 'likely' : ($rScore >= 1 ? 'check' : 'ok'), 'reasons' => $rWhy,
			'uas' => $rUaRows, 'isps' => $rIspRows, 'ips_now' => $rIpsNow, 'isps_now' => count($rNowIsps),
		];
	}

	/** Words in an ISP's name that mark a datacenter or a host, not a home connection. */
	public const HOSTING = ['hetzner', 'ovh', 'digitalocean', 'digital ocean', 'amazon', 'aws', 'google cloud', 'google llc', 'microsoft', 'azure', 'linode', 'akamai', 'vultr', 'choopa',
		'contabo', 'leaseweb', 'scaleway', 'online s.a.s', 'hostinger', 'hostwinds', 'm247', 'datacamp', 'cdn77', 'oracle', 'alibaba', 'tencent', 'colocation', 'colo ', 'hosting',
		'datacenter', 'data center', 'data centre', 'server', 'vps', 'cloud', 'ionos', '1&1', 'godaddy', 'serverius', 'worldstream', 'clouvider', 'psychz', 'quadranet', 'zenlayer'];

	/** Does an ISP name look like a datacenter? */
	public static function isHosting(string $rIsp): bool {
		$rIsp = ' ' . strtolower($rIsp) . ' ';
		foreach (self::HOSTING as $rWord) {
			if (str_contains($rIsp, $rWord)) {
				return true;
			}
		}
		return false;
	}

	/**
	 * A MAG or Enigma2 line judged as a box: one channel at a time, from a
	 * home connection, with the box's own user agent. A proxy that takes a box
	 * line to restream the list shows otherwise: a datacenter, several
	 * channels at once, channels opened one after another faster than a person
	 * zaps, a user agent that is not the box's, or two places at once.
	 *
	 * @param string $rKind 'MAG' or 'Enigma2'
	 * @param array{sessions:int, short:int, channels:int, max_hour:int, uas: array<string, int>, isps: array<string, int>}|null $rActivity
	 * @param array<string, mixed>|null $rSamples sampleStats() of the line
	 * @param list<array{ip:string, isp:string, ua:string}> $rLive
	 * @return array{score:int, level:string, reasons:list<string>, at_once:int, families: array<string, int>, isps: list<array{isp:string, hosting:bool, now:bool}>}
	 */
	public static function deviceLine(string $rKind, ?array $rActivity, ?array $rSamples, array $rLive): array {
		$rScore = 0;
		$rWhy = [];
		$rIsps = [];
		foreach (array_keys($rActivity['isps'] ?? []) as $rIsp) {
			$rIsps[$rIsp] = ['isp' => $rIsp, 'hosting' => self::isHosting($rIsp), 'now' => false];
		}
		foreach ($rLive as $c) {
			$rIsp = trim((string) $c['isp']);
			if ($rIsp !== '') {
				$rIsps[$rIsp] = ['isp' => $rIsp, 'hosting' => self::isHosting($rIsp), 'now' => true];
			}
		}
		$rHosting = array_values(array_filter($rIsps, static fn (array $i): bool => $i['hosting']));
		if ($rHosting !== []) {
			$rScore += 3;
			$rWhy[] = 'from a datacenter, not a home: ' . $rHosting[0]['isp'];
		}
		$rAtOnce = max(count($rLive), (int) ($rSamples['max_conns'] ?? 0));
		if ($rAtOnce >= 2) {
			$rScore += 3;
			$rWhy[] = $rAtOnce . ' channels at once (a box plays one)';
		}
		if (($rActivity['max_hour'] ?? 0) >= self::DEVICE_CHANNELS_HOUR) {
			$rScore += 2;
			$rWhy[] = $rActivity['max_hour'] . ' different channels in one hour (one after another, faster than a person zaps)';
		}
		$rFamilies = [];
		foreach ($rActivity['uas'] ?? [] as $rUa => $n) {
			$rFamily = self::playerFamily((string) $rUa);
			$rFamilies[$rFamily] = ($rFamilies[$rFamily] ?? 0) + $n;
		}
		foreach ($rLive as $c) {
			$rFamily = self::playerFamily((string) $c['ua']);
			$rFamilies[$rFamily] = ($rFamilies[$rFamily] ?? 0) + 1;
		}
		$rOwn = $rKind === 'MAG' ? 'MAG box' : 'Enigma2';
		$rForeign = array_keys(array_filter($rFamilies, static fn (int $n, string $f): bool => $f !== $rOwn && $f !== '(none)', ARRAY_FILTER_USE_BOTH));
		if ($rForeign !== []) {
			$rScore += 2;
			$rWhy[] = 'a user agent that is not a ' . $rOwn . "'s: " . implode(', ', $rForeign);
		}
		if ((int) ($rSamples['multi_ip'] ?? 0) > 0 || count(array_unique(array_filter(array_column($rLive, 'ip')))) >= 2) {
			$rScore += 2;
			$rWhy[] = 'used from two IPs at once';
		}
		arsort($rFamilies);
		return [
			'score' => $rScore, 'level' => $rScore >= 3 ? 'likely' : ($rScore >= 1 ? 'check' : 'ok'), 'reasons' => $rWhy,
			'at_once' => $rAtOnce, 'families' => $rFamilies, 'isps' => array_values($rIsps),
		];
	}

	/** Channels in one hour above which a box line is opening them faster than a person zaps. */
	public const DEVICE_CHANNELS_HOUR = 20;

	/** A user agent for a sentence: its family, or its first 40 characters. */
	public static function uaKeyLabel(string $rUserAgent): string {
		$rFamily = self::playerFamily($rUserAgent);
		return $rFamily !== 'Other' ? $rFamily : '"' . mb_strimwidth(trim($rUserAgent), 0, 40, '…') . '"';
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
			$rWhy[] = 'two places took it from each other ' . $rHandovers['returns'] . ' times (' . $rHandovers['handovers'] . ' handovers between IPs'
				. (($rHandovers['isp_returns'] ?? 0) > 0 ? ', ' . $rHandovers['isp_returns'] . ' of them between ISPs' : '') . ')';
			if (($rHandovers['isp_returns'] ?? 0) >= self::HANDOVER_RETURNS) {
				$rScore += 1;   // two providers: two homes, not one router given a new address
			}
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

	/**
	 * A line that started coming from an ISP it never used before $rFrom, while
	 * it had one before. Moved: the earlier ISPs are no longer used (a customer
	 * who changed provider or moved house, or a line passed on). Added: they
	 * still are (a second place).
	 *
	 * @param list<array{isp:string, first_seen:int, last_seen:int}> $rIsps
	 * @return array{kind:string, since:int, new:list<string>, old:list<string>}|null
	 */
	public static function ispChange(array $rIsps, int $rFrom): ?array {
		$rNew = array_values(array_filter($rIsps, static fn (array $i): bool => $i['first_seen'] >= $rFrom));
		$rOld = array_values(array_filter($rIsps, static fn (array $i): bool => $i['first_seen'] < $rFrom));
		if ($rNew === [] || $rOld === []) {
			return null;
		}
		$rSince = min(array_column($rNew, 'first_seen'));
		$rOldUsed = max(array_column($rOld, 'last_seen')) > $rSince + 300;
		usort($rOld, static fn (array $a, array $b): int => $b['last_seen'] <=> $a['last_seen']);
		return [
			'kind' => $rOldUsed ? 'added' : 'moved', 'since' => $rSince,
			'new' => array_column($rNew, 'isp'), 'old' => array_column($rOld, 'isp'),
		];
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
