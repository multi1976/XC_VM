<?php

namespace XcVm\Module\SourceHealth;

/**
 * Turns a stream's event log (`streams_logs`) into per-source health figures.
 *
 * PURE: no database, no clock, no globals — the repository hands it the rows and
 * the time, so every rule here is unit-testable.
 *
 * How the log is read, per server (each server runs its own copy of a stream, so
 * its events are a sequence of their own):
 *
 *   STREAM_START / STREAM_RESTART (S)  S became the running source. If another run
 *                                      was still open and nothing said why it ended
 *                                      (a daemon or server restart starts a new
 *                                      supervision with STREAM_START), that run just
 *                                      ends: it is not charged to its source.
 *   STREAM_FAILED (S)                  the open run ended in a failure (both the
 *                                      fanout daemon and the legacy monitor log it
 *                                      whenever the encoder dies on its own).
 *   STREAM_START_FAIL (S)              an attempt on S did not come up.
 *   PRIORITY_SWITCH / FORCE_SOURCE     a planned move away from the running source
 *   AUTO_RESTART                       (counted as "switched away", not a failure).
 *   AUDIO_LOSS / FPS_DROP_THRESHOLD /  a quality problem on the running source.
 *   FFMPEG_ERROR
 *   STREAM_STOP                        the operator stopped it: the run ends cleanly.
 *
 * Only events inside the window [from, now] are counted. The repository may pass
 * the last event BEFORE the window too (a run that was already going when the
 * window opened): its time on air is counted from `from`, its start is not.
 */
final class SourceHealthCalculator {
	/** Actions that open a run. */
	private const OPEN = ['STREAM_START', 'STREAM_RESTART'];
	/** Actions that move the stream off its running source on purpose. */
	private const PLANNED = ['PRIORITY_SWITCH', 'FORCE_SOURCE', 'AUTO_RESTART'];
	/** Actions that are a quality problem of the source that is running. */
	private const QUALITY = ['AUDIO_LOSS', 'FPS_DROP_THRESHOLD', 'FFMPEG_ERROR'];

	/**
	 * @param list<string> $rSources the stream's sources, in the operator's order.
	 * @param list<array{server_id:int|string, action:string, source:?string, date:int|string}> $rEvents
	 *        sorted oldest first; may start with events from before $rFrom.
	 * @param int $rFrom window start (unix time).
	 * @param int $rNow  window end (unix time).
	 * @param array<int, string> $rRunning server id => source its encoder runs right now.
	 * @param array<string, array<string, mixed>> $rProbes md5(source) => probe results in the
	 *        window (ok, total, open_ms, last_at, last_ok, last_error): an independent test of
	 *        every source, on air or not, so backups the stream rarely uses are measured too.
	 * @return list<array<string, mixed>> one row per source, in the operator's order.
	 */
	public static function compute(array $rSources, array $rEvents, int $rFrom, int $rNow, array $rRunning = [], array $rProbes = []): array {
		$rStats = [];
		foreach (array_values($rSources) as $i => $rSource) {
			$rKey = trim((string) $rSource);
			if ($rKey === '' || isset($rStats[$rKey])) {
				continue; // blank or duplicate line in the source list
			}
			$rStats[$rKey] = self::blank($i, $rKey);
		}

		/** @var array<int, array{src:string, since:int}|null> $rOpen */
		$rOpen = [];
		/** @var array<int, int> $rLast last event time per server */
		$rLast = [];

		foreach ($rEvents as $rEvent) {
			$rServer = (int) $rEvent['server_id'];
			$rTime = (int) $rEvent['date'];
			$rAction = (string) $rEvent['action'];
			$rSrc = trim((string) ($rEvent['source'] ?? ''));
			$rInWindow = $rTime >= $rFrom;
			$rLast[$rServer] = max($rLast[$rServer] ?? 0, $rTime);
			$rRun = $rOpen[$rServer] ?? null;

			if (in_array($rAction, self::OPEN, true)) {
				if ($rRun !== null) {
					self::close($rStats, $rRun, $rTime, $rFrom, false);
				}
				$rOpen[$rServer] = ['src' => $rSrc, 'since' => $rTime];
				if ($rInWindow && isset($rStats[$rSrc])) {
					$rStats[$rSrc]['starts']++;
					$rStats[$rSrc]['last_active'] = max($rStats[$rSrc]['last_active'], $rTime);
				}
				continue;
			}
			if (!$rInWindow) {
				// Only a run-opening event matters from before the window: it seeds the
				// run that was on air when the window opened. Anything else there
				// means no run was open.
				$rOpen[$rServer] = null;
				continue;
			}

			switch (true) {
				case $rAction === 'STREAM_FAILED':
					$rFailed = $rSrc !== '' ? $rSrc : ($rRun['src'] ?? '');
					if ($rRun !== null) {
						self::close($rStats, $rRun, $rTime, $rFrom, false);
					}
					if (isset($rStats[$rFailed])) {
						$rStats[$rFailed]['failures']++;
						// A failure soon after the last one (on any server) is the same
						// outage still going on — the panel retrying a source that keeps
						// dropping — not a new one.
						if ($rStats[$rFailed]['last_drop'] === 0 || $rTime - $rStats[$rFailed]['last_drop'] > self::INCIDENT_GAP_SEC) {
							$rStats[$rFailed]['incidents']++;
							$rStats[$rFailed]['incident_times'][] = $rTime;
						}
						$rStats[$rFailed]['last_drop'] = $rTime;
						$rStats[$rFailed]['last_failure'] = max($rStats[$rFailed]['last_failure'], $rTime);
					}
					$rOpen[$rServer] = null;
					break;

				case $rAction === 'STREAM_START_FAIL':
					if (isset($rStats[$rSrc])) {
						$rStats[$rSrc]['start_fails']++;
						$rStats[$rSrc]['start_fail_times'][] = $rTime;
						$rStats[$rSrc]['last_failure'] = max($rStats[$rSrc]['last_failure'], $rTime);
					}
					break;

				case in_array($rAction, self::PLANNED, true):
					if ($rRun !== null && $rAction !== 'AUTO_RESTART' && isset($rStats[$rRun['src']])) {
						$rStats[$rRun['src']]['switched_away']++;
					}
					break;

				case in_array($rAction, self::QUALITY, true):
					$rBad = $rSrc !== '' ? $rSrc : ($rRun['src'] ?? '');
					if (isset($rStats[$rBad])) {
						$rStats[$rBad]['quality_issues']++;
					}
					break;

				case $rAction === 'STREAM_STOP':
					if ($rRun !== null) {
						self::close($rStats, $rRun, $rTime, $rFrom, false);
					}
					$rOpen[$rServer] = null;
					break;
			}
		}

		// Runs still open at the end: on air until now when that server really runs
		// that source now; otherwise the log lost its end, and the last event seen on
		// that server is the most that can honestly be claimed.
		foreach ($rOpen as $rServer => $rRun) {
			if ($rRun === null) {
				continue;
			}
			$rUntil = (($rRunning[$rServer] ?? null) === $rRun['src']) ? $rNow : ($rLast[$rServer] ?? $rRun['since']);
			self::close($rStats, $rRun, $rUntil, $rFrom, false);
			if ($rUntil === $rNow && isset($rStats[$rRun['src']])) {
				$rStats[$rRun['src']]['running'] = true;
				$rStats[$rRun['src']]['last_active'] = $rNow;
			}
		}

		$rWindow = max(1, $rNow - $rFrom);
		foreach ($rStats as $rKey => &$rRow) {
			$rRow['uptime_sec'] = min($rRow['uptime_sec'], $rWindow * max(1, count($rLast)));
			$rProbe = $rProbes[md5((string) $rKey)] ?? null;
			$rRow['probe_ok'] = (int) ($rProbe['ok'] ?? 0);
			$rRow['probe_total'] = (int) ($rProbe['total'] ?? 0);
			$rRow['probe'] = $rProbe;
			[$rRow['score'], $rRow['confidence']] = self::score($rRow);
		}
		unset($rRow);

		return array_values($rStats);
	}

	/** Failures closer together than this (on any server) are one incident. */
	public const INCIDENT_GAP_SEC = 900;
	/** Prior for the time between failures: one failure in this many hours. */
	public const MTBF_PRIOR_HOURS = 6.0;
	/** Time between failures at which stability reaches 1 − 1/e ≈ 0.63. */
	public const STABILITY_SCALE_HOURS = 6.0;

	/**
	 * Score 0–100 for one source, or null when nothing is known about it: the
	 * chance it comes up times the chance it stays up, cut by quality problems.
	 *
	 *   start     (starts + probes ok + 1)             one assumed success, so one
	 *             / (attempts + probes + 1)            missed start is not "0%"; a
	 *                                                  probe is a start attempt too
	 *   stability 1 − e^(−MTBF / 6h), where            MTBF 6 h ≈ 0.63, 24 h ≈ 0.98;
	 *             MTBF = (hours + 6) / (incidents + 1) the prior keeps a few clean
	 *                                                  minutes from reading as bad;
	 *                                                  incidents, not raw failures: a
	 *                                                  burst of 100 retries during one
	 *                                                  outage is one outage
	 *   quality   1 / (1 + issues / (hours + 1))
	 *
	 * Multiplied, not averaged: a source that starts every time but drops every
	 * few minutes is not half good.
	 *
	 * @param array<string, mixed> $rRow
	 * @return array{0: ?int, 1: string} [score, confidence: none|low|medium|high]
	 */
	public static function score(array $rRow): array {
		$rAttempts = (int) $rRow['starts'] + (int) $rRow['start_fails'] + (int) ($rRow['probe_total'] ?? 0);
		$rHours = ((int) $rRow['uptime_sec']) / 3600;
		if ($rAttempts === 0 && $rHours <= 0) {
			return [null, 'none'];
		}
		$rStart = ((int) $rRow['starts'] + (int) ($rRow['probe_ok'] ?? 0) + 1) / ($rAttempts + 1);
		$rMtbf = ($rHours + self::MTBF_PRIOR_HOURS) / ((int) ($rRow['incidents'] ?? $rRow['failures']) + 1);
		$rStability = 1 - exp(-$rMtbf / self::STABILITY_SCALE_HOURS);
		$rQuality = 1 / (1 + ((int) $rRow['quality_issues']) / ($rHours + 1));
		$rScore = (int) round(100 * $rStart * $rStability * $rQuality);

		if ($rHours >= 24 || $rAttempts >= 10) {
			$rConfidence = 'high';
		} elseif ($rHours >= 1 || $rAttempts >= 3) {
			$rConfidence = 'medium';
		} else {
			$rConfidence = 'low';
		}
		return [max(0, min(100, $rScore)), $rConfidence];
	}

	/**
	 * What the suggested order goes by: the score, discounted by how little it
	 * rests on. A score is partly assumption (the priors); a source tried a few
	 * times, or never on air for an hour, must not overtake one measured for
	 * days just because nothing bad has been seen of it yet.
	 *
	 * @param array<string, mixed> $rRow
	 */
	public static function rankValue(array $rRow): float {
		if ($rRow['score'] === null) {
			return -1.0;
		}
		$rWeight = ['high' => 1.0, 'medium' => 0.85, 'low' => 0.6][$rRow['confidence']] ?? 0.6;
		if ((int) $rRow['uptime_sec'] < 3600) {
			$rWeight *= 0.7; // how long it stays up is not measured yet: the prior stands in
		}
		return $rRow['score'] * $rWeight;
	}

	/**
	 * Source indexes best first by rankValue(), unknown last, ties keep the
	 * operator's order. A suggestion only — nothing here changes how streams run.
	 *
	 * @param list<array<string, mixed>> $rRows output of compute()
	 * @return list<int>
	 */
	public static function suggestedOrder(array $rRows): array {
		usort($rRows, static function (array $a, array $b): int {
			$rA = self::rankValue($a);
			$rB = self::rankValue($b);
			return abs($rA - $rB) < 0.001 ? $a['index'] <=> $b['index'] : $rB <=> $rA;
		});
		return array_map(static fn (array $r): int => (int) $r['index'], $rRows);
	}

	/** @return array<string, mixed> */
	private static function blank(int $rIndex, string $rSource): array {
		return [
			'index' => $rIndex, 'source' => $rSource,
			'starts' => 0, 'start_fails' => 0, 'failures' => 0, 'incidents' => 0, 'incident_times' => [], 'start_fail_times' => [], 'last_drop' => 0,
			'quality_issues' => 0, 'switched_away' => 0,
			'uptime_sec' => 0, 'last_active' => 0, 'last_failure' => 0,
			'running' => false, 'score' => null, 'confidence' => 'none',
		];
	}

	/**
	 * End a run: add its time on air (clipped to the window) and, when nothing
	 * explained the end, charge a failure to its source.
	 *
	 * @param array<string, array<string, mixed>> $rStats
	 * @param array{src:string, since:int} $rRun
	 */
	private static function close(array &$rStats, array $rRun, int $rUntil, int $rFrom, bool $rFailure): void {
		if (!isset($rStats[$rRun['src']])) {
			return; // a source no longer in the list
		}
		$rStats[$rRun['src']]['uptime_sec'] += max(0, $rUntil - max($rRun['since'], $rFrom));
		if ($rFailure) {
			$rStats[$rRun['src']]['failures']++;
			$rStats[$rRun['src']]['last_failure'] = max($rStats[$rRun['src']]['last_failure'], $rUntil);
		}
	}
}
