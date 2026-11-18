<?php

namespace XcVm\Module\SourceHealth;

use XcVm\Infrastructure\Database\DatabaseAware;

/**
 * Probes Auto-rank asks for by itself, on top of the streams the admin opted
 * in to "Probe every source": a few per cron run, the same probe (one short
 * connection reading SourceProber::PROBE_READ_SEC seconds), so that
 *
 *   1. a source Auto-rank has proposed is seen working or not in the day its
 *      record is judged on (every PROPOSED_EVERY_SEC), and
 *   2. backups never on air get a profile: which group of the stream they
 *      belong to, so a drop can fall back on one players need not
 *      re-initialise for (once per PROFILE_EVERY_SEC, only for streams that
 *      have been on air in the last week: those viewers watch).
 *
 * Never the source on air. On a provider account a live stream is using only
 * when the provider's API, freshly asked, leaves a connection to spare (the
 * Source Audit rule). Not a source Source Audit finds dead or down. One per
 * account per run, at most PER_RUN, within BUDGET_SEC.
 */
final class AutoProbe {
	use DatabaseAware;

	/**
	 * Off. 0.6.0 ran these and live connections fell on a production panel
	 * the same evening: until the cause is known, Auto-rank makes no probe of
	 * its own (the admin's "Probe every source" streams are unchanged).
	 */
	public const ENABLED = false;
	public const PER_RUN = 4;
	public const BUDGET_SEC = 60;
	public const PROPOSED_EVERY_SEC = 2 * 3600;
	public const PROFILE_EVERY_SEC = 24 * 3600;

	/** @return int probes made */
	public static function run(int $rNow, string $rProbe, string $rFfmpeg): int {
		if (!self::ENABLED) {
			return 0;
		}
		$rDue = self::plan(self::candidates($rNow), SourceHealthRepository::onAirSources(), SourceHealthRepository::accounts(), SourceHealthRepository::auditState(), $rNow);
		$rStarted = time();
		$rDone = 0;
		foreach ($rDue as $rItem) {
			if (time() - $rStarted > self::BUDGET_SEC) {
				break;
			}
			$rResult = SourceProber::probe($rItem['source'], SourceHealthRepository::fetchOptions($rItem['stream_id']), $rProbe, $rFfmpeg);
			SourceHealthRepository::saveProbe($rItem['stream_id'], $rItem['source'], $rResult, time());
			SourceHealthRepository::saveHosts($rItem['stream_id'], $rItem['source'], $rResult['redirect_hosts'] ?? [], time());
			$rDone++;
		}
		return $rDone;
	}

	/**
	 * What may be probed now, most wanted first. PURE.
	 *
	 * @param list<array{stream_id:int, source:string, why:string, every:int, last:int}> $rCandidates
	 * @param list<string> $rOnAir every source on air on the panel
	 * @param array<string, array<string, mixed>> $rAccounts provider answers by md5(account key)
	 * @param array<string, array<string, mixed>> $rAudit Source Audit state by md5(source)
	 * @return list<array{stream_id:int, source:string, why:string}>
	 */
	public static function plan(array $rCandidates, array $rOnAir, array $rAccounts, array $rAudit, int $rNow, int $rMax = self::PER_RUN): array {
		$rLive = array_flip(array_map('trim', $rOnAir));
		$rBusy = SourceAudit::busyAccounts($rOnAir);
		$rBusyLogins = [];
		foreach ($rOnAir as $rSource) {
			$rLogin = SourceFinder::login((string) $rSource);
			if ($rLogin !== null) {
				$rBusyLogins[$rLogin] = true;
			}
		}
		usort($rCandidates, static fn (array $a, array $b): int => [$a['why'] === 'proposed' ? 0 : 1, $a['last']] <=> [$b['why'] === 'proposed' ? 0 : 1, $b['last']]);
		$rOut = [];
		$rTaken = [];
		foreach ($rCandidates as $c) {
			$rSource = trim($c['source']);
			$rKey = (string) SourceProber::accountKey($rSource);
			if (count($rOut) >= $rMax || $rNow - $c['last'] < $c['every'] || isset($rLive[$rSource]) || isset($rTaken[$rKey])
				|| !in_array(strtolower((string) parse_url($rSource, PHP_URL_SCHEME)), SourceProber::SCHEMES, true)
				|| in_array((string) ($rAudit[md5($rSource)]['status'] ?? ''), ['dead', 'down'], true)
				|| (isset($rBusy[$rKey]) && !ProviderAccount::admits($rAccounts[md5($rKey)] ?? null, $rNow))
				|| (!isset($rBusy[$rKey]) && isset($rBusyLogins[(string) SourceFinder::login($rSource)]))) {   // the same login on air through another domain
				continue;
			}
			$rTaken[$rKey] = true;
			$rOut[] = ['stream_id' => $c['stream_id'], 'source' => $rSource, 'why' => $c['why']];
		}
		return $rOut;
	}

	/** @return list<array{stream_id:int, source:string, why:string, every:int, last:int}> */
	private static function candidates(int $rNow): array {
		$rOut = [];
		$rSeen = [];
		// 1. The source proposed by each record still waiting for its day.
		$rOpen = self::db()->fetchAll('SELECT `stream_id`, `new_hash` FROM `source_health_rank_decisions` WHERE `outcome_at` IS NULL AND `decided_at` >= ?', $rNow - AutoRank::OUTCOME_SEC - 3600) ?: [];
		$rWant = [];
		foreach ($rOpen as $r) {
			$rWant[(int) $r['stream_id']][(string) $r['new_hash']] = true;
		}
		// 2. Sources with no profile yet, in streams viewers watched this week.
		$rWatched = array_map('intval', self::db()->fetchColumn('SELECT DISTINCT `stream_id` FROM `source_health_profiles` WHERE `last_seen` >= ?', $rNow - 7 * 86400) ?: []);
		$rIDs = array_values(array_unique(array_merge(array_keys($rWant), $rWatched)));
		if ($rIDs === []) {
			return [];
		}
		$rLast = SourceHealthRepository::lastProbes($rIDs);
		foreach (self::db()->fetchAll('SELECT `id`, `stream_source` FROM `streams` WHERE `type` = 1 AND `id` IN (' . implode(',', $rIDs) . ')') ?: [] as $rStream) {
			$rID = (int) $rStream['id'];
			$rSources = array_values(array_filter(array_map(static fn ($v): string => is_string($v) ? trim($v) : '', (array) json_decode(SourceHealthRepository::raw((string) $rStream['stream_source']), true))));
			if (count($rSources) < 2) {
				continue;
			}
			$rProfiles = isset($rWant[$rID]) || in_array($rID, $rWatched, true) ? AutoRank::profiles($rID) : [];
			foreach ($rSources as $rSource) {
				$rHash = md5($rSource);
				if (isset($rSeen[$rID . ':' . $rHash])) {
					continue;
				}
				$rLastAt = $rLast[$rID . ':' . $rHash] ?? 0;
				if (isset($rWant[$rID][$rHash])) {
					$rOut[] = ['stream_id' => $rID, 'source' => $rSource, 'why' => 'proposed', 'every' => self::PROPOSED_EVERY_SEC, 'last' => $rLastAt];
					$rSeen[$rID . ':' . $rHash] = true;
				} elseif (in_array($rID, $rWatched, true) && !isset($rProfiles[$rHash])) {
					$rOut[] = ['stream_id' => $rID, 'source' => $rSource, 'why' => 'profile', 'every' => self::PROFILE_EVERY_SEC, 'last' => $rLastAt];
					$rSeen[$rID . ':' . $rHash] = true;
				}
			}
		}
		return $rOut;
	}
}
