<?php

namespace XcVm\Module\SourceHealth;

use XcVm\Infrastructure\Database\DatabaseAware;

/**
 * Auto-rank, phase 2 of source management, in SHADOW MODE: it works out which
 * source each stream should try first and records what it would change, but
 * changes nothing. A week of these records, each checked a day later against
 * what the sources actually did, says whether the rules can be trusted with
 * the streams.
 *
 * Every run evaluates the streams with two sources or more whose turn has come
 * (EVAL_EVERY_SEC, at most EVAL_PER_RUN), with the Source Health score of each
 * source over the report window. A change is proposed when a source further
 * down is clearly better than the first one:
 *
 *   - it is eligible: a score of MIN_SCORE or more, at least medium
 *     confidence, an hour on air or MIN_PROBES_OK good probes, and not found
 *     dead or down by Source Audit;
 *   - it ranks above the first (SourceHealthCalculator::rankValue), never
 *     below, even when the first is off now;
 *   - and either the first source is off by Source Audit, or the other one
 *     ranks MARGIN points ahead.
 *
 * Hysteresis, so the order does not flap: the same proposal must come out of
 * CONFIRM evaluations in a row, and a stream gets at most one recorded change
 * per MIN_INTERVAL_SEC. The proposal moves that one source to the top and
 * keeps the others in the operator's order. The source on air is never
 * switched by a change of order: when enabled, a new order only takes effect at
 * the stream's next failover or restart.
 *
 * OUTCOME_SEC after a record, the window since is read again: how the first
 * source did (it stayed first, it is a shadow) and what is known of the one
 * proposed (probes, and time on air if the stream fell back on it).
 */
final class AutoRank {
	use DatabaseAware;

	public const EVAL_EVERY_SEC = 1800;
	public const EVAL_PER_RUN = 120;
	public const BUDGET_SEC = 20;
	public const MARGIN = 15.0;
	public const CONFIRM = 2;
	public const MIN_INTERVAL_SEC = 6 * 3600;
	public const MIN_ON_AIR_SEC = 3600;
	public const MIN_PROBES_OK = 3;
	/** A source scoring under this is never put first, not even in place of a dead one: it would only trade one failure for another. */
	public const MIN_SCORE = 30;
	/** Failures (incidents and failed starts) per hour on air a source may have in the day after and still count as working. */
	public const GOOD_RATE = 1.0;
	/** Time on air a rate is computed over, at least: six failures in seven minutes are not 51 an hour, but neither are they fine. */
	public const RATE_FLOOR_SEC = 900;
	/** Points a source of the main profile may trail the best one by and still be preferred: a profile change makes players re-initialise. */
	public const PROFILE_SLACK = 15.0;
	public const OUTCOME_SEC = 86400;
	public const KEEP_SEC = 30 * 86400;

	/** @return array{evaluated:int, recorded:int, outcomes:int} */
	public static function run(int $rNow): array {
		$rStarted = microtime(true);
		$rAudit = SourceHealthRepository::auditState();
		$rStates = [];
		foreach (self::db()->fetchAll('SELECT * FROM `source_health_rank_state`') ?: [] as $r) {
			$rStates[(int) $r['stream_id']] = $r;
		}
		$rDue = array_filter(SourceHealthRepository::liveStreams(), static fn (array $s): bool => count(array_filter($s['sources'], static fn (string $x): bool => $x !== '')) >= 2
			&& (int) ($rStates[$s['id']]['last_eval'] ?? 0) <= $rNow - self::EVAL_EVERY_SEC);
		usort($rDue, static fn (array $a, array $b): int => (int) ($rStates[$a['id']]['last_eval'] ?? 0) <=> (int) ($rStates[$b['id']]['last_eval'] ?? 0));
		$rEvaluated = 0;
		$rRecorded = 0;
		foreach (array_slice($rDue, 0, self::EVAL_PER_RUN) as $rStream) {
			if (microtime(true) - $rStarted > self::BUDGET_SEC) {
				break;
			}
			$rSources = array_values(array_filter($rStream['sources'], static fn (string $x): bool => $x !== ''));
			$rRows = self::rows($rStream['id'], $rSources, $rNow - SourceHealthTab::window(), $rNow);
			$rResult = self::decide($rRows, $rAudit, $rStates[$rStream['id']] ?? null, $rNow);
			self::saveState($rStream['id'], $rResult['state'], $rNow);
			if ($rResult['decision'] !== null) {
				self::record($rStream['id'], $rResult['decision'], $rNow);
				$rRecorded++;
			}
			$rEvaluated++;
		}
		$rOutcomes = self::outcomes($rNow, $rStarted);
		self::db()->query('DELETE FROM `source_health_rank_decisions` WHERE `decided_at` < ?', $rNow - self::KEEP_SEC);
		self::db()->query('DELETE r FROM `source_health_rank_state` r LEFT JOIN `streams` s ON s.`id` = r.`stream_id` WHERE s.`id` IS NULL');
		return ['evaluated' => $rEvaluated, 'recorded' => $rRecorded, 'outcomes' => $rOutcomes];
	}

	/** The Source Health rows of one stream over [$rFrom, $rTo], as the report computes them. */
	public static function rows(int $rStreamID, array $rSources, int $rFrom, int $rTo): array {
		$rEvents = array_values(array_filter(SourceHealthRepository::events($rStreamID, $rFrom), static fn (array $e): bool => (int) $e['date'] <= $rTo));
		$rDrops = [];
		foreach ($rEvents as $rEvent) {
			if (in_array($rEvent['action'], ['STREAM_FAILED', 'STREAM_START_FAIL'], true) && trim((string) $rEvent['source']) !== '') {
				$rDrops[md5(trim((string) $rEvent['source']))][] = (int) $rEvent['date'];
			}
		}
		$rRunning = $rTo >= time() - 60 ? SourceHealthRepository::running($rStreamID) : [];
		$rRows = SourceHealthCalculator::compute($rSources, $rEvents, $rFrom, $rTo, $rRunning, SourceHealthRepository::probeStats($rStreamID, $rFrom, $rDrops));
		$rProfiles = self::profiles($rStreamID);
		foreach ($rRows as &$rRow) {
			$rRow['profile'] = $rProfiles[md5(trim((string) $rRow['source']))] ?? '';
		}
		unset($rRow);
		return $rRows;
	}

	/**
	 * What each source of a stream looks like to a player, as a label (codec,
	 * size, rate, scan, audio): from its time on air (source_health_profiles),
	 * else from its latest probe that read one. Sources never on air nor probed
	 * have none.
	 *
	 * @return array<string, string> md5(source) => profile label
	 */
	public static function profiles(int $rStreamID): array {
		$rOut = [];
		foreach (self::db()->fetchAll("SELECT `source_hash`, `label` FROM `source_health_probes` WHERE `stream_id` = ? AND `kind` = 'probe' AND `label` <> '' ORDER BY `checked_at` ASC", $rStreamID) ?: [] as $r) {
			$rOut[(string) $r['source_hash']] = SourceHealthRepository::raw((string) $r['label']);
		}
		foreach (SourceHealthRepository::profiles($rStreamID) as $rHash => $r) {
			if ((string) ($r['label'] ?? '') !== '') {
				$rOut[(string) $rHash] = SourceHealthRepository::raw((string) $r['label']);   // what it showed on air wins
			}
		}
		return $rOut;
	}

	/** Can this source be put first? Returns why not, or null. PURE. */
	public static function ineligible(array $rRow, array $rAudit): ?string {
		$rStatus = (string) ($rAudit[md5(trim((string) $rRow['source']))]['status'] ?? '');
		return match (true) {
			$rRow['score'] === null                                  => 'nothing measured',
			in_array($rRow['confidence'], ['none', 'low'], true)      => 'too little data',
			(int) $rRow['score'] < self::MIN_SCORE                   => 'score under ' . self::MIN_SCORE,
			(int) $rRow['uptime_sec'] < self::MIN_ON_AIR_SEC && (int) ($rRow['probe_ok'] ?? 0) < self::MIN_PROBES_OK => 'not an hour on air nor ' . self::MIN_PROBES_OK . ' good probes',
			in_array($rStatus, ['dead', 'down'], true)               => 'Source Audit found it ' . $rStatus,
			default                                                  => null,
		};
	}

	/**
	 * The profile the stream's viewers know: the one its sources have spent
	 * the most time on air with over the window, else the first source's.
	 * '' when no source's profile is known. PURE.
	 *
	 * @param list<array<string, mixed>> $rRows compute() rows with a 'profile' label ('' unknown)
	 */
	public static function mainProfile(array $rRows): string {
		$rTime = [];
		foreach ($rRows as $r) {
			$rProfile = (string) ($r['profile'] ?? '');
			if ($rProfile !== '') {
				$rTime[$rProfile] = ($rTime[$rProfile] ?? 0) + (int) $r['uptime_sec'];
			}
		}
		if ($rTime === []) {
			return '';
		}
		arsort($rTime);
		$rBest = (string) array_key_first($rTime);
		return $rTime[$rBest] > 0 ? $rBest : (((string) ($rRows[0]['profile'] ?? '')) ?: $rBest);
	}

	/**
	 * The best source to put first among $rRows (the first one included or
	 * not): the eligible one ranking highest, but one of the main profile when
	 * it ranks within PROFILE_SLACK of it, so the stream does not change profile
	 * for a few points. PURE.
	 *
	 * @param list<array<string, mixed>> $rRows
	 */
	public static function best(array $rRows, array $rAudit, string $rMain): ?array {
		$rAll = null;
		$rSame = null;
		foreach ($rRows as $r) {
			if (self::ineligible($r, $rAudit) !== null) {
				continue;
			}
			$v = SourceHealthCalculator::rankValue($r);
			if ($rAll === null || $v > SourceHealthCalculator::rankValue($rAll)) {
				$rAll = $r;
			}
			if ($rMain !== '' && ($r['profile'] ?? '') === $rMain && ($rSame === null || $v > SourceHealthCalculator::rankValue($rSame))) {
				$rSame = $r;
			}
		}
		if ($rSame !== null && SourceHealthCalculator::rankValue($rSame) >= SourceHealthCalculator::rankValue($rAll) - self::PROFILE_SLACK) {
			return $rSame;
		}
		return $rAll;
	}

	/**
	 * The failover order with $rTopIndex first: then the sources of the top's
	 * profile (else the main one), best first, so a drop is caught by a source
	 * players need not re-initialise for; then those whose profile is not known
	 * yet; then the other profiles; and last the sources Source Audit finds dead
	 * or down. Ties keep the operator's order. PURE.
	 *
	 * @param list<array<string, mixed>> $rRows
	 * @return list<int> source indexes
	 */
	public static function proposedOrder(array $rRows, array $rAudit, string $rMain, int $rTopIndex): array {
		$rByIndex = array_column($rRows, null, 'index');
		$rGroup = (string) ($rByIndex[$rTopIndex]['profile'] ?? '') ?: $rMain;
		$rRest = array_values(array_filter($rRows, static fn (array $r): bool => (int) $r['index'] !== $rTopIndex));
		$rBucket = static function (array $r) use ($rAudit, $rGroup): int {
			$rProfile = (string) ($r['profile'] ?? '');
			return match (true) {
				in_array((string) ($rAudit[md5(trim((string) $r['source']))]['status'] ?? ''), ['dead', 'down'], true) => 3,
				$rGroup !== '' && $rProfile === $rGroup => 0,
				$rProfile === '' => 1,
				default => 2,
			};
		};
		usort($rRest, static fn (array $a, array $b): int => [$rBucket($a), -SourceHealthCalculator::rankValue($a), (int) $a['index']]
			<=> [$rBucket($b), -SourceHealthCalculator::rankValue($b), (int) $b['index']]);
		return array_merge([$rTopIndex], array_map(static fn (array $r): int => (int) $r['index'], $rRest));
	}

	/**
	 * Profile letters for a stream: A for the main profile, then B, C… in the
	 * order the sources come. PURE.
	 *
	 * @param list<array<string, mixed>> $rRows
	 * @return array<string, string> profile label => letter
	 */
	public static function letters(array $rRows, string $rMain): array {
		$rOut = $rMain !== '' ? [$rMain => 'A'] : [];
		foreach ($rRows as $r) {
			$rProfile = (string) ($r['profile'] ?? '');
			if ($rProfile !== '' && !isset($rOut[$rProfile])) {
				$rOut[$rProfile] = chr(ord('A') + min(25, count($rOut)));
			}
		}
		return $rOut;
	}

	/**
	 * One evaluation of one stream. PURE.
	 *
	 * Two kinds of proposal. FIRST: another source should go first (see the
	 * class comment), preferring one of the main profile within PROFILE_SLACK.
	 * ORDER: the first stays, but the next in line, the one a drop falls back
	 * on, is of another profile (or off by Source Audit) while a source of the
	 * first one's profile could take its place: the drop would then be caught
	 * without making players re-initialise.
	 *
	 * @param list<array<string, mixed>> $rRows SourceHealthCalculator::compute() output, operator's order, each with a 'profile' label ('' unknown)
	 * @param array<string, array<string, mixed>> $rAudit Source Audit state by md5(source)
	 * @param array<string, mixed>|null $rState the stream's row in source_health_rank_state
	 * @return array{state: array{pending_hash:string, pending_count:int, pending_since:int, last_decision_at:int, last_top_hash:string}, decision: ?array<string, mixed>}
	 */
	public static function decide(array $rRows, array $rAudit, ?array $rState, int $rNow): array {
		$rState = [
			'pending_hash' => (string) ($rState['pending_hash'] ?? ''), 'pending_count' => (int) ($rState['pending_count'] ?? 0),
			'pending_since' => (int) ($rState['pending_since'] ?? 0), 'last_decision_at' => (int) ($rState['last_decision_at'] ?? 0),
			'last_top_hash' => (string) ($rState['last_top_hash'] ?? ''),
		];
		$rClear = static function (array $s): array {
			$s['pending_hash'] = '';
			$s['pending_count'] = 0;
			$s['pending_since'] = 0;
			return $s;
		};
		if (count($rRows) < 2) {
			return ['state' => $rClear($rState), 'decision' => null];
		}
		$rOff = static fn (array $r): bool => in_array((string) ($rAudit[md5(trim((string) $r['source']))]['status'] ?? ''), ['dead', 'down'], true);
		$rFirst = $rRows[0];
		$rFirstValue = SourceHealthCalculator::rankValue($rFirst);
		$rMain = self::mainProfile($rRows);
		$rLetters = self::letters($rRows, $rMain);
		$rLetter = static fn (array $r): string => $rLetters[(string) ($r['profile'] ?? '')] ?? '?';

		$rKind = null;
		$rBest = self::best(array_slice($rRows, 1), $rAudit, $rMain);
		if ($rBest !== null && SourceHealthCalculator::rankValue($rBest) > $rFirstValue) {
			// Never a worse source first, even in place of one that is off now.
			$rBestValue = SourceHealthCalculator::rankValue($rBest);
			if ($rOff($rFirst)) {
				$rKind = 'first';
				$rReason = 'the first source is ' . $rAudit[md5(trim((string) $rFirst['source']))]['status'] . ' by Source Audit';
			} elseif ($rBestValue - $rFirstValue >= self::MARGIN) {
				$rKind = 'first';
				$rReason = 'ranks ' . (int) round($rBestValue) . ' against ' . ($rFirstValue < 0 ? 'nothing measured' : (int) round($rFirstValue)) . ' for the first';
			}
		}
		$rTop = $rKind === 'first' ? $rBest : $rFirst;
		$rOrder = self::proposedOrder($rRows, $rAudit, $rMain, (int) $rTop['index']);
		$rByIndex = array_column($rRows, null, 'index');
		if ($rKind === null) {
			$rNow1 = $rRows[1];
			$rNew1 = $rByIndex[$rOrder[1]];
			$rGroup = (string) ($rFirst['profile'] ?? '') ?: $rMain;
			if ((int) $rNew1['index'] !== (int) $rNow1['index'] && !$rOff($rNew1)) {
				if ($rOff($rNow1)) {
					$rKind = 'order';
					$rReason = 'the next in line, #' . ($rNow1['index'] + 1) . ', is ' . $rAudit[md5(trim((string) $rNow1['source']))]['status'] . ' by Source Audit';
				} elseif ($rGroup !== '' && ($rNow1['profile'] ?? '') !== '' && $rNow1['profile'] !== $rGroup && ($rNew1['profile'] ?? '') === $rGroup
					&& SourceHealthCalculator::rankValue($rNew1) >= SourceHealthCalculator::rankValue($rNow1) - self::PROFILE_SLACK) {
					$rKind = 'order';
					$rReason = 'a drop would fall back on profile ' . $rLetter($rNow1) . ' (#' . ($rNow1['index'] + 1) . '); #' . ($rNew1['index'] + 1) . ' keeps profile ' . $rLetter($rNew1);
				}
			}
		}
		if ($rKind === null) {
			return ['state' => $rClear($rState), 'decision' => null];
		}

		$rSignature = md5(implode(',', $rOrder));
		if ($rState['pending_hash'] === $rSignature) {
			$rState['pending_count']++;
		} else {
			$rState['pending_hash'] = $rSignature;
			$rState['pending_count'] = 1;
			$rState['pending_since'] = $rNow;
		}
		// Shadow mode leaves the order as it is: the same proposal would come back
		// every interval. It is recorded once, until it changes.
		if ($rState['pending_count'] < self::CONFIRM || $rNow - $rState['last_decision_at'] < self::MIN_INTERVAL_SEC || $rState['last_top_hash'] === $rSignature) {
			return ['state' => $rState, 'decision' => null];
		}
		$rState = $rClear($rState);
		$rState['last_decision_at'] = $rNow;
		$rState['last_top_hash'] = $rSignature;
		// FIRST compares the first source with the one proposed in its place;
		// ORDER the next in line now with the one proposed instead.
		[$rOld, $rNew] = $rKind === 'first' ? [$rFirst, $rTop] : [$rRows[1], $rByIndex[$rOrder[1]]];
		return ['state' => $rState, 'decision' => [
			'kind' => $rKind,
			'old_hash' => md5(trim((string) $rOld['source'])), 'new_hash' => md5(trim((string) $rNew['source'])), 'old_index' => (int) $rOld['index'], 'new_index' => (int) $rNew['index'],
			'old_score' => $rOld['score'], 'new_score' => $rNew['score'],
			'old_value' => SourceHealthCalculator::rankValue($rOld), 'new_value' => SourceHealthCalculator::rankValue($rNew),
			'old_profile' => $rLetter($rOld), 'new_profile' => $rLetter($rNew),
			'order_before' => implode(',', array_map(static fn (array $r): int => (int) $r['index'] + 1, $rRows)),
			'order_after' => implode(',', array_map(static fn (int $i): int => $i + 1, $rOrder)),
			'reason' => $rReason, 'on_air' => (bool) $rFirst['running'],
		]];
	}

	/**
	 * What a record turned out to be, from the day after it. PURE.
	 *
	 * A source works when its failures (incidents and failed starts) per hour
	 * on air stay within GOOD_RATE, over at least RATE_FLOOR_SEC, and most of its
	 * probes passed. The first source failed when it had any failure.
	 *   idle         the stream was not used that day: nothing to compare;
	 *   helped       the first failed, the proposed one was seen working;
	 *   hurt         the proposed one failed, and the first did not, or the
	 *                proposed one failed at over twice the first one's rate;
	 *   both_failed  both failed;
	 *   same         neither failed;
	 *   unknown      the first failed, the proposed one was not seen at all.
	 *
	 * @param array{incidents:?int, start_fails:?int, uptime:?int} $rOld
	 * @param array{incidents:?int, start_fails:?int, uptime:?int, probe_ok:?int, probe_total:?int} $rNew
	 */
	public static function verdict(array $rOld, array $rNew): string {
		$rOldFails = (int) ($rOld['incidents'] ?? 0) + (int) ($rOld['start_fails'] ?? 0);
		$rNewFails = (int) ($rNew['incidents'] ?? 0) + (int) ($rNew['start_fails'] ?? 0);
		$rOldUp = (int) ($rOld['uptime'] ?? 0);
		$rNewUp = (int) ($rNew['uptime'] ?? 0);
		$rProbes = (int) ($rNew['probe_total'] ?? 0);
		if ($rOldFails === 0 && $rOldUp === 0 && $rNewUp === 0 && $rNewFails === 0) {
			return $rProbes > 0 && (int) $rNew['probe_ok'] * 2 < $rProbes ? 'hurt' : 'idle';
		}
		$rRate = static fn (int $rFails, int $rUp): float => $rFails / (max($rUp, self::RATE_FLOOR_SEC) / 3600);
		$rNewSeen = $rNewUp > 0 || $rNewFails > 0 || $rProbes > 0;
		$rNewWorks = $rRate($rNewFails, $rNewUp) <= self::GOOD_RATE && ($rProbes === 0 || (int) $rNew['probe_ok'] * 2 >= $rProbes);
		$rOldBad = $rOldFails > 0;
		return match (true) {
			!$rNewSeen                                                             => $rOldBad ? 'unknown' : 'same',
			$rOldBad && $rNewWorks                                                 => 'helped',
			!$rNewWorks && (!$rOldBad || $rRate($rNewFails, $rNewUp) > 2 * $rRate($rOldFails, $rOldUp)) => 'hurt',
			$rOldBad                                                               => 'both_failed',
			default                                                                => 'same',
		};
	}

	/** The verdict of a stored record, from its numbers (so the rules can change and old records follow). */
	public static function verdictOf(array $r): ?string {
		if ($r['outcome_at'] === null) {
			return null;
		}
		if ($r['verdict'] === 'gone') {
			return 'gone';
		}
		return self::verdict(
			['incidents' => $r['old_incidents'], 'start_fails' => $r['old_start_fails'], 'uptime' => $r['old_uptime']],
			['incidents' => $r['new_incidents'], 'start_fails' => $r['new_start_fails'] ?? 0, 'uptime' => $r['new_uptime'], 'probe_ok' => $r['new_probe_ok'], 'probe_total' => $r['new_probe_total']]
		);
	}

	/** Records whose day has passed: read the day since and keep the verdict. */
	private static function outcomes(int $rNow, float $rStarted): int {
		$rDone = 0;
		$rRows = self::db()->fetchAll('SELECT * FROM `source_health_rank_decisions` WHERE `outcome_at` IS NULL AND `decided_at` <= ? ORDER BY `decided_at` LIMIT 50', $rNow - self::OUTCOME_SEC) ?: [];
		foreach ($rRows as $d) {
			if (microtime(true) - $rStarted > self::BUDGET_SEC + 10) {
				break;
			}
			$rStream = SourceHealthRepository::stream((int) $d['stream_id']);
			$rSources = $rStream === null ? [] : array_values(array_filter(array_map(static fn ($s): string => is_string($s) ? trim($s) : '', (array) json_decode(SourceHealthRepository::raw((string) $rStream['stream_source']), true)), static fn (string $s): bool => $s !== ''));
			$rByHash = [];
			if ($rSources !== []) {
				foreach (self::rows((int) $d['stream_id'], $rSources, (int) $d['decided_at'], (int) $d['decided_at'] + self::OUTCOME_SEC) as $r) {
					$rByHash[md5(trim((string) $r['source']))] = $r;
				}
			}
			[$rFailovers, $rSwitches] = $rSources === [] ? [null, null] : self::switches(
				array_values(array_filter(SourceHealthRepository::events((int) $d['stream_id'], (int) $d['decided_at']), static fn (array $e): bool => (int) $e['date'] >= (int) $d['decided_at'] && (int) $e['date'] <= (int) $d['decided_at'] + self::OUTCOME_SEC)),
				self::profiles((int) $d['stream_id'])
			);
			$rOld = $rByHash[$d['old_hash']] ?? null;
			$rNew = $rByHash[$d['new_hash']] ?? null;
			$rV = ($rOld === null || $rNew === null) ? 'gone' : self::verdict(
				['incidents' => (int) $rOld['incidents'], 'start_fails' => (int) $rOld['start_fails'], 'uptime' => (int) $rOld['uptime_sec']],
				['incidents' => (int) $rNew['incidents'], 'start_fails' => (int) $rNew['start_fails'], 'uptime' => (int) $rNew['uptime_sec'], 'probe_ok' => (int) ($rNew['probe_ok'] ?? 0), 'probe_total' => (int) ($rNew['probe_total'] ?? 0)]
			);
			self::db()->query(
				'UPDATE `source_health_rank_decisions` SET `outcome_at` = ?, `old_incidents` = ?, `old_uptime` = ?, `old_start_fails` = ?, `new_incidents` = ?, `new_uptime` = ?, `new_start_fails` = ?, `new_probe_ok` = ?, `new_probe_total` = ?, `failovers` = ?, `profile_switches` = ?, `verdict` = ? WHERE `id` = ?',
				$rNow, $rOld['incidents'] ?? null, $rOld['uptime_sec'] ?? null, $rOld['start_fails'] ?? null, $rNew['incidents'] ?? null, $rNew['uptime_sec'] ?? null, $rNew['start_fails'] ?? null, $rNew['probe_ok'] ?? null, $rNew['probe_total'] ?? null,
				$rFailovers, $rSwitches, $rV, (int) $d['id']
			);
			$rDone++;
		}
		return $rDone;
	}

	/**
	 * Failovers in a run of events: a server's encoder moving from one source
	 * to another, and how many of those changed profile (both known and
	 * different), which is when players had to re-initialise. PURE.
	 *
	 * @param list<array{server_id:int|string, action:string, source:?string, date:int|string}> $rEvents oldest first
	 * @param array<string, string> $rProfiles md5(source) => profile label
	 * @return array{0:int, 1:int} [failovers, profile switches]
	 */
	public static function switches(array $rEvents, array $rProfiles): array {
		$rLast = [];
		$rFailovers = 0;
		$rSwitches = 0;
		foreach ($rEvents as $e) {
			$rSource = trim((string) ($e['source'] ?? ''));
			if ($rSource === '') {
				continue;
			}
			$rServer = (string) $e['server_id'];
			$rPrev = $rLast[$rServer] ?? null;
			if ($rPrev !== null && $rPrev !== $rSource) {
				$rFailovers++;
				$a = $rProfiles[md5($rPrev)] ?? '';
				$b = $rProfiles[md5($rSource)] ?? '';
				if ($a !== '' && $b !== '' && $a !== $b) {
					$rSwitches++;
				}
			}
			$rLast[$rServer] = $rSource;
		}
		return [$rFailovers, $rSwitches];
	}

	private static function saveState(int $rStreamID, array $s, int $rNow): void {
		self::db()->query(
			'INSERT INTO `source_health_rank_state` (`stream_id`, `last_eval`, `pending_hash`, `pending_count`, `pending_since`, `last_decision_at`, `last_top_hash`) VALUES (?, ?, ?, ?, ?, ?, ?) '
			. 'ON DUPLICATE KEY UPDATE `last_eval` = VALUES(`last_eval`), `pending_hash` = VALUES(`pending_hash`), `pending_count` = VALUES(`pending_count`), `pending_since` = VALUES(`pending_since`), '
			. '`last_decision_at` = VALUES(`last_decision_at`), `last_top_hash` = VALUES(`last_top_hash`)',
			$rStreamID, $rNow, $s['pending_hash'], $s['pending_count'], $s['pending_since'], $s['last_decision_at'], substr($s['last_top_hash'], 0, 65)
		);
	}

	private static function record(int $rStreamID, array $d, int $rNow): void {
		self::db()->query(
			'INSERT INTO `source_health_rank_decisions` (`stream_id`, `decided_at`, `kind`, `old_hash`, `new_hash`, `old_index`, `new_index`, `old_score`, `new_score`, `old_value`, `new_value`, `old_profile`, `new_profile`, `order_before`, `order_after`, `reason`, `on_air`) '
			. 'VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
			$rStreamID, $rNow, $d['kind'], $d['old_hash'], $d['new_hash'], $d['old_index'], $d['new_index'], $d['old_score'], $d['new_score'], round($d['old_value'], 1), round($d['new_value'], 1),
			$d['old_profile'], $d['new_profile'], mb_substr($d['order_before'], 0, 190), mb_substr($d['order_after'], 0, 190), mb_substr($d['reason'], 0, 255), $d['on_air'] ? 1 : 0
		);
	}

	/**
	 * Records of the last $rDays days, newest first, with stream names.
	 *
	 * @return list<array<string, mixed>>
	 */
	public static function decisions(int $rFrom): array {
		return self::db()->fetchAll(
			'SELECT d.*, s.`stream_display_name` AS `name`, s.`stream_source` AS `sources` FROM `source_health_rank_decisions` d LEFT JOIN `streams` s ON s.`id` = d.`stream_id` WHERE d.`decided_at` >= ? ORDER BY d.`decided_at` DESC, d.`id` DESC',
			$rFrom
		) ?: [];
	}

	/**
	 * The stream's latest record of the last week and its evaluation state, for its report.
	 *
	 * @return array{decision: ?array<string, mixed>, state: ?array<string, mixed>}
	 */
	public static function forStream(int $rStreamID, int $rNow): array {
		$rDecision = self::db()->fetchOne('SELECT * FROM `source_health_rank_decisions` WHERE `stream_id` = ? AND `decided_at` >= ? ORDER BY `decided_at` DESC, `id` DESC LIMIT 1', $rStreamID, $rNow - 7 * 86400);
		$rState = self::db()->fetchOne('SELECT * FROM `source_health_rank_state` WHERE `stream_id` = ?', $rStreamID);
		return ['decision' => $rDecision ?: null, 'state' => $rState ?: null];
	}

	/** @return array{streams:int, evaluated:int, pending:int} */
	public static function coverage(int $rNow): array {
		$r = self::db()->fetchOne('SELECT COUNT(*) AS `streams`, SUM(`last_eval` >= ?) AS `evaluated`, SUM(`pending_count` > 0) AS `pending` FROM `source_health_rank_state`', $rNow - 2 * self::EVAL_EVERY_SEC) ?: [];
		return ['streams' => (int) ($r['streams'] ?? 0), 'evaluated' => (int) ($r['evaluated'] ?? 0), 'pending' => (int) ($r['pending'] ?? 0)];
	}
}
