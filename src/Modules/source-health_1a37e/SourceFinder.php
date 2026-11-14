<?php

namespace XcVm\Module\SourceHealth;

use XcVm\Infrastructure\Database\DatabaseAware;

/**
 * New sources for a stream from the channel lists of the Stream Providers:
 * a provider's live channel whose name, reduced (ProviderImport::normalizeName:
 * case, quality tags, a country prefix and punctuation off), is the stream's.
 * "Tring Sport 2 HD" and "AL: Tring Sport 2" find each other; "Sport 2" and
 * "Tring Sport 2" do not. Only the exact reduced name: a near name is a
 * different channel too often to offer.
 *
 * Left out: a channel the stream already has (same account and channel id,
 * however the URL is written), one of an account the provider's API calls
 * expired, banned or refused, one the audit found dead, and providers that are
 * disabled or whose list the core could not fetch. The rest are ranked: an
 * account with a connection to spare first, then the provider whose checked
 * sources work best.
 *
 * Read-only; SourceActions::add() adds what is picked.
 */
final class SourceFinder {
	use DatabaseAware;

	/** Candidates offered per stream. */
	public const PER_STREAM = 5;

	/**
	 * Enabled Stream Providers with a fetched channel list.
	 *
	 * @return array<int, array{id:int, name:string, host:string, ip:string, port:int, user:string, pass:string, ssl:bool, hls:bool, legacy:bool, max:?int, active:int, exp:?int}>
	 */
	public static function providers(): array {
		$rOut = [];
		foreach (self::db()->fetchAll('SELECT `id`, `name`, `ip`, `port`, `username`, `password`, `ssl`, `hls`, `legacy`, `data` FROM `providers` WHERE `enabled` = 1 AND `status` = 1') ?: [] as $r) {
			$rData = json_decode(SourceHealthRepository::raw((string) $r['data']), true) ?: [];
			$rIp = SourceHealthRepository::raw((string) $r['ip']);
			$rHost = strtolower((string) preg_replace('#:\d+$#', '', rtrim((string) preg_replace('#^https?://#i', '', $rIp), '/')));
			$rExp = $rData['exp_date'] ?? null;
			$rOut[(int) $r['id']] = [
				'id' => (int) $r['id'], 'name' => SourceHealthRepository::raw((string) $r['name']), 'host' => $rHost, 'ip' => $rIp, 'port' => (int) $r['port'],
				'user' => SourceHealthRepository::raw((string) $r['username']), 'pass' => SourceHealthRepository::raw((string) $r['password']),
				'ssl' => (bool) $r['ssl'], 'hls' => (bool) $r['hls'], 'legacy' => (bool) $r['legacy'],
				'max' => isset($rData['max_connections']) && (int) $rData['max_connections'] > 0 ? (int) $rData['max_connections'] : null,
				'active' => (int) ($rData['active_connections'] ?? 0),
				'exp' => is_numeric($rExp) && (int) $rExp > 0 ? (int) $rExp : null,
			];
		}
		return $rOut;
	}

	/** A provider channel's URL, written as the core's Providers picker writes it. */
	public static function url(array $rProvider, int $rChannel): string {
		return ($rProvider['ssl'] ? 'https' : 'http') . '://' . $rProvider['ip'] . ':' . $rProvider['port'] . '/live/' . $rProvider['user'] . '/' . $rProvider['pass'] . '/' . $rChannel
			. ($rProvider['hls'] ? '.m3u8' : ($rProvider['legacy'] ? '.ts' : ''));
	}

	/**
	 * The providers' live channels by reduced name, for the names asked.
	 *
	 * @param list<string> $rNames reduced names
	 * @param array<int, array<string, mixed>> $rProviders
	 * @return array<string, list<array{provider:int, channel:int, name:string}>>
	 */
	public static function channels(array $rNames, array $rProviders): array {
		$rWant = array_flip(array_filter($rNames, static fn (string $n): bool => mb_strlen($n) >= 2));
		if ($rWant === [] || $rProviders === []) {
			return [];
		}
		$rOut = [];
		$rRows = self::db()->fetchAll(
			'SELECT `provider_id`, `stream_id`, `stream_display_name` FROM `providers_streams` WHERE `type` = \'live\' AND `provider_id` IN (' . implode(',', array_map('intval', array_keys($rProviders))) . ')'
		) ?: [];
		foreach ($rRows as $r) {
			$rName = SourceHealthRepository::raw((string) $r['stream_display_name']);
			$rKey = ProviderImport::normalizeName($rName);
			if (isset($rWant[$rKey])) {
				$rOut[$rKey][] = ['provider' => (int) $r['provider_id'], 'channel' => (int) $r['stream_id'], 'name' => $rName];
			}
		}
		return $rOut;
	}

	/**
	 * Candidates for each stream, best first. PURE.
	 *
	 * @param list<array{id:int, name:string, sources:list<string>}> $rStreams
	 * @param array<string, list<array{provider:int, channel:int, name:string}>> $rChannels by reduced name (channels())
	 * @param array<int, array<string, mixed>> $rProviders (providers())
	 * @param array<string, array<string, mixed>> $rAudit audit state by md5(source)
	 * @param array<string, array<string, mixed>> $rAccounts account answers by md5(account key)
	 * @param array<string, float> $rHostHealth host => share of its checked sources that work
	 * @return array<int, list<array{provider:int, provider_name:string, channel:int, name:string, url:string, max:?int, active:int, spare:?int, health:?float, note:string}>>
	 */
	public static function candidates(array $rStreams, array $rChannels, array $rProviders, array $rAudit, array $rAccounts, array $rHostHealth, int $rNow): array {
		$rOut = [];
		foreach ($rStreams as $rStream) {
			$rFound = $rChannels[ProviderImport::normalizeName($rStream['name'])] ?? [];
			if ($rFound === []) {
				continue;
			}
			$rHave = [];
			foreach ($rStream['sources'] as $rSource) {
				$rHave[self::identity($rSource)] = true;
			}
			$rList = [];
			foreach ($rFound as $c) {
				$p = $rProviders[$c['provider']] ?? null;
				if ($p === null) {
					continue;
				}
				$rUrl = self::url($p, $c['channel']);
				$rId = self::identity($rUrl);
				if (isset($rHave[$rId]) || isset($rList[$rId])) {
					continue;
				}
				$rKey = SourceProber::accountKey($rUrl);
				$rAcct = $rKey !== null ? ($rAccounts[md5($rKey)] ?? null) : null;
				if (ProviderAccount::deadReason($rAcct, $rNow) !== null || ($p['exp'] !== null && $p['exp'] < $rNow) || (($rAudit[md5($rUrl)]['status'] ?? '') === 'dead')) {
					continue;
				}
				// The module's own answer is fresher than the core's hourly one.
				$rMax = $rAcct !== null && $rAcct['api'] && $rAcct['max'] !== null ? (int) $rAcct['max'] : $p['max'];
				$rActive = $rAcct !== null && $rAcct['api'] ? (int) ($rAcct['active'] ?? 0) : $p['active'];
				$rSpare = $rMax !== null ? $rMax - $rActive : null;
				$rList[$rId] = [
					'provider' => $p['id'], 'provider_name' => $p['name'], 'channel' => $c['channel'], 'name' => $c['name'], 'url' => $rUrl,
					'max' => $rMax, 'active' => $rActive, 'spare' => $rSpare, 'health' => $rHostHealth[$p['host']] ?? null,
					'note' => (($rAudit[md5($rUrl)]['status'] ?? '') === 'ok') ? 'checked ok' : '',
				];
			}
			if ($rList === []) {
				continue;
			}
			$rList = array_values($rList);
			usort($rList, static fn (array $a, array $b): int => [($a['spare'] ?? 1) <= 0 ? 1 : 0, -($a['health'] ?? 0.5), -($a['spare'] ?? 0)]
				<=> [($b['spare'] ?? 1) <= 0 ? 1 : 0, -($b['health'] ?? 0.5), -($b['spare'] ?? 0)]);
			$rOut[$rStream['id']] = array_slice($rList, 0, self::PER_STREAM);
		}
		return $rOut;
	}

	/** One channel of one account, however its URL is written: account key and channel id. */
	public static function identity(string $rSource): string {
		$rKey = SourceProber::accountKey($rSource);
		$rId = ProviderImport::channelId($rSource);
		return $rKey !== null && $rId !== null ? $rKey . '#' . $rId : 'url:' . trim($rSource);
	}
}
