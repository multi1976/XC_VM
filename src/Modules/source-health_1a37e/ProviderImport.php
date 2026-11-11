<?php

namespace XcVm\Module\SourceHealth;

use XcVm\Domain\Stream\ProviderService;
use XcVm\Infrastructure\Database\DatabaseAware;

/**
 * The provider accounts found in live-stream sources, and their link to the
 * panel's Stream Providers (the core `providers` table: one row per account).
 *
 * Adding one goes through ProviderService::process(), the same path as the
 * Add Provider form — its validation, its duplicate rule (same host and
 * username) and the admin's own permission — so the module writes nothing
 * into core tables by itself. Once added, the core providers cron keeps the
 * account's channel list in providers_streams, which channelCheck() reads to
 * tell a channel the provider has dropped (and the one that took its name)
 * without opening a stream.
 */
final class ProviderImport {
	use DatabaseAware;

	/**
	 * @return array<string, array{key:string, host:string, scheme:string, port:int, user:string, pass:string, hls:bool, sources:int, streams:list<int>, provider:?array<string, mixed>}>
	 *         md5(account key) => the account
	 */
	public static function discover(): array {
		$rProviders = self::providers();
		$rOut = [];
		foreach (SourceHealthRepository::liveStreams() as $rStream) {
			foreach ($rStream['sources'] as $rSource) {
				$rAcct = ProviderAccount::parse($rSource);
				if ($rAcct === null) {
					continue;
				}
				$rHash = md5($rAcct['key']);
				if (!isset($rOut[$rHash])) {
					$rParts = parse_url($rSource);
					$rScheme = strtolower((string) ($rParts['scheme'] ?? 'http'));
					$rOut[$rHash] = [
						'key' => $rAcct['key'], 'host' => $rAcct['host'], 'scheme' => $rScheme,
						'port' => (int) ($rParts['port'] ?? ($rScheme === 'https' ? 443 : 80)),
						'user' => $rAcct['user'], 'pass' => $rAcct['pass'], 'hls' => false, 'sources' => 0, 'streams' => [],
						'provider' => $rProviders[self::matchKey($rAcct['host'], $rAcct['user'])] ?? null,
					];
				}
				$rOut[$rHash]['sources']++;
				$rOut[$rHash]['hls'] = $rOut[$rHash]['hls'] || str_ends_with(strtolower((string) parse_url($rSource, PHP_URL_PATH)), '.m3u8');
				$rOut[$rHash]['streams'][$rStream['id']] = $rStream['id'];
			}
		}
		foreach ($rOut as &$rRow) {
			$rRow['streams'] = array_values($rRow['streams']);
		}
		return $rOut;
	}

	/**
	 * Add accounts to Stream Providers. Must run in an admin request: the
	 * core service checks the admin's permission itself.
	 *
	 * @param list<string> $rHashes md5(account key), as discover() keys them
	 * @return array{added:int, existing:int, failed:int}
	 */
	public static function add(array $rHashes, bool $rEnabled = true): array {
		if (!defined('STATUS_SUCCESS') && class_exists(\XC_Bootstrap::class)) {
			\XC_Bootstrap::defineStatusConstants();
		}
		$rFound = self::discover();
		$rResult = ['added' => 0, 'existing' => 0, 'failed' => 0];
		foreach (array_unique($rHashes) as $rHash) {
			$rAcct = $rFound[$rHash] ?? null;
			if ($rAcct === null) {
				$rResult['failed']++;
				continue;
			}
			if ($rAcct['provider'] !== null) {
				$rResult['existing']++;
				continue;
			}
			$rData = self::formData($rAcct, $rEnabled);
			$rReturn = ProviderService::process($rData);
			$rStatus = is_array($rReturn) ? ($rReturn['status'] ?? null) : null;
			if (defined('STATUS_SUCCESS') && $rStatus === constant('STATUS_SUCCESS')) {
				$rResult['added']++;
			} elseif (defined('STATUS_EXISTS_IP') && $rStatus === constant('STATUS_EXISTS_IP')) {
				$rResult['existing']++;
			} else {
				$rResult['failed']++;
			}
		}
		return $rResult;
	}

	/**
	 * What the Add Provider form would post for this account. Third-party
	 * Xtream/XUI panels answer the classic player_api.php, which the core
	 * calls "legacy".
	 *
	 * @param array{host:string, scheme:string, port:int, user:string, pass:string, hls:bool} $rAcct
	 * @return array<string, mixed>
	 */
	public static function formData(array $rAcct, bool $rEnabled = true): array {
		$rData = [
			'name' => mb_substr($rAcct['host'] . ' – ' . $rAcct['user'], 0, 128), 'ip' => $rAcct['host'], 'port' => (string) $rAcct['port'],
			'username' => $rAcct['user'], 'password' => $rAcct['pass'], 'legacy' => 1,
		];
		if ($rEnabled) {
			$rData['enabled'] = 1;
		}
		// Live channels only: the audit needs the channel list, not the
		// provider's VOD and series. A core without per-list sync ignores these.
		$rData['sync_form'] = 1;
		$rData['sync_live'] = 1;
		if ($rAcct['scheme'] === 'https') {
			$rData['ssl'] = 1;
		}
		if ($rAcct['hls']) {
			$rData['hls'] = 1;
		}
		return $rData;
	}

	/** @return array<string, array<string, mixed>> "host|username" => provider row (id, name, enabled, status, data, last_changed) */
	public static function providers(): array {
		$rOut = [];
		foreach (self::db()->fetchAll('SELECT `id`, `name`, `ip`, `port`, `username`, `enabled`, `status`, `data`, `last_changed` FROM `providers`') ?: [] as $rRow) {
			$rHost = strtolower(rtrim((string) preg_replace('#^https?://#i', '', SourceHealthRepository::raw((string) $rRow['ip'])), '/'));
			$rHost = (string) preg_replace('#:\d+$#', '', $rHost);
			$rOut[self::matchKey($rHost, SourceHealthRepository::raw((string) $rRow['username']))] = [
				'id' => (int) $rRow['id'], 'name' => SourceHealthRepository::raw((string) $rRow['name']), 'enabled' => (bool) $rRow['enabled'], 'status' => (bool) $rRow['status'],
				'data' => json_decode(SourceHealthRepository::raw((string) $rRow['data']), true) ?: [], 'last_changed' => (int) $rRow['last_changed'],
			];
		}
		return $rOut;
	}

	private static function matchKey(string $rHost, string $rUser): string {
		return strtolower($rHost) . '|' . $rUser;
	}

	/** The provider's channel id in a source URL: its last path segment, extension off. */
	public static function channelId(string $rSource): ?int {
		$rLast = basename((string) parse_url(trim($rSource), PHP_URL_PATH));
		return preg_match('/^(\d+)(\.[a-z0-9]+)?$/i', $rLast, $m) === 1 ? (int) $m[1] : null;
	}

	/** A channel name reduced for matching: case, quality tags and punctuation off. */
	public static function normalizeName(string $rName): string {
		$rName = mb_strtolower($rName);
		$rName = (string) preg_replace('/\b(uhd|fhd|hd|sd|4k|hevc|h265|raw|backup|bk)\b|\|.*?\||^[a-z]{2,3}\s*[:|-]\s*/u', ' ', $rName);
		return trim((string) preg_replace('/[^\p{L}\p{N}]+/u', ' ', $rName));
	}

	/**
	 * For sources of accounts that are Stream Providers with a synced channel
	 * list: is the source's channel id still in that list, and if not, which
	 * channel of the provider carries the stream's name now.
	 *
	 * @param list<array{source:string, name:string}> $rItems
	 * @return array<string, array{provider:string, listed:bool, suggest:list<array{id:int, name:string}>}> md5(source) => verdict
	 */
	public static function channelCheck(array $rItems): array {
		$rProviders = self::providers();
		$rWant = [];
		foreach ($rItems as $rItem) {
			$rAcct = ProviderAccount::parse($rItem['source']);
			$rId = self::channelId($rItem['source']);
			$rProvider = $rAcct !== null ? ($rProviders[self::matchKey($rAcct['host'], $rAcct['user'])] ?? null) : null;
			// Only a provider whose list the core has fetched can say a channel is gone.
			if ($rId === null || $rProvider === null || !$rProvider['status'] || (int) ($rProvider['data']['streams'] ?? 0) === 0) {
				continue;
			}
			$rWant[$rProvider['id']]['provider'] = $rProvider['name'];
			$rWant[$rProvider['id']]['items'][md5($rItem['source'])] = ['id' => $rId, 'name' => $rItem['name']];
		}
		$rOut = [];
		foreach ($rWant as $rProviderID => $rGroup) {
			$rIds = array_values(array_unique(array_column($rGroup['items'], 'id')));
			$rListed = [];
			foreach (array_chunk($rIds, 500) as $rChunk) {
				foreach (self::db()->fetchColumn('SELECT `stream_id` FROM `providers_streams` WHERE `provider_id` = ? AND `type` = \'live\' AND `stream_id` IN (' . implode(',', array_map('intval', $rChunk)) . ')', $rProviderID) ?: [] as $rId) {
					$rListed[(int) $rId] = true;
				}
			}
			foreach ($rGroup['items'] as $rHash => $rItem) {
				$rIsListed = isset($rListed[$rItem['id']]);
				$rOut[$rHash] = ['provider' => $rGroup['provider'], 'listed' => $rIsListed, 'suggest' => $rIsListed ? [] : self::suggest($rProviderID, $rItem['name'], $rItem['id'])];
			}
		}
		return $rOut;
	}

	/** @return list<array{id:int, name:string}> the provider's live channels named like the stream, best first */
	private static function suggest(int $rProviderID, string $rName, int $rNot): array {
		$rCore = self::normalizeName($rName);
		if (mb_strlen($rCore) < 2) {
			return [];
		}
		$rWords = array_filter(explode(' ', $rCore), static fn (string $w): bool => mb_strlen($w) >= 2);
		$rLongest = array_reduce($rWords, static fn (string $a, string $w): string => mb_strlen($w) > mb_strlen($a) ? $w : $a, '');
		if ($rLongest === '') {
			return [];
		}
		$rRows = self::db()->fetchAll('SELECT `stream_id`, `stream_display_name` FROM `providers_streams` WHERE `provider_id` = ? AND `type` = \'live\' AND `stream_display_name` LIKE ? LIMIT 200', $rProviderID, '%' . $rLongest . '%') ?: [];
		$rOut = [];
		foreach ($rRows as $rRow) {
			$rTheirs = SourceHealthRepository::raw((string) $rRow['stream_display_name']);
			if ((int) $rRow['stream_id'] !== $rNot && self::normalizeName($rTheirs) === $rCore) {
				$rOut[] = ['id' => (int) $rRow['stream_id'], 'name' => $rTheirs];
			}
		}
		return array_slice($rOut, 0, 3);
	}
}
