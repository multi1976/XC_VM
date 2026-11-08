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
 *                                      was still open and nothing said why it ended,
 *                                      that run counts as a failure of its source.
 *   STREAM_FAILED (S)                  the open run ended in a failure.
 *   STREAM_START_FAIL (S)              an attempt on S did not come up.
 *   PRIORITY_SWITCH / FORCE_SOURCE     a planned move: the run that ends next is not
 *   AUTO_RESTART                       charged as a failure.
 *   AUDIO_LOSS / FPS_DROP_THRESHOLD /  a quality problem on the running source; the
 *   FFMPEG_ERROR                       restart it causes is not charged twice.
 *   STREAM_STOP                        the operator stopped it: the run ends cleanly.
 *
 * Only events inside the window [from, now] are counted. The repository may pass
 * the last event BEFORE the window too (a run that was already going when the
 * window opened): its time on air is counted from `from`, its start is not.
 */
final class SourceHealthCalculator {
	/** Actions that open a run. */
	private const OPEN = ['STREAM_START', 'STREAM_RESTART'];
	/** Actions after which the next end of the run is planned, not a failure. */
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
	 * @return list<array<string, mixed>> one row per source, in the operator's order.
	 */
	public static function compute(array $rSources, array $rEvents, int $rFrom, int $rNow, array $rRunning = []): array {
		$rStats = [];
		foreach (array_values($rSources) as $i => $rSource) {
			$rKey = trim((string) $rSource);
			if ($rKey === '' || isset($rStats[$rKey])) {
				continue; // blank or duplicate line in the source list
			}
			$rStats[$rKey] = self::blank($i, $rKey);
		}

		/** @var array<int, array{src:string, since:int, planned:bool}|null> $rOpen */
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
					self::close($rStats, $rRun, $rTime, $rFrom, !$rRun['planned'] && $rInWindow);
				}
				$rOpen[$rServer] = ['src' => $rSrc, 'since' => $rTime, 'planned' => false];
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
						$rStats[$rFailed]['last_failure'] = max($rStats[$rFailed]['last_failure'], $rTime);
					}
					$rOpen[$rServer] = null;
					break;

				case $rAction === 'STREAM_START_FAIL':
					if (isset($rStats[$rSrc])) {
						$rStats[$rSrc]['start_fails']++;
						$rStats[$rSrc]['last_failure'] = max($rStats[$rSrc]['last_failure'], $rTime);
					}
					break;

				case in_array($rAction, self::PLANNED, true):
					if ($rRun !== null) {
						$rOpen[$rServer]['planned'] = true;
						if ($rAction !== 'AUTO_RESTART' && isset($rStats[$rRun['src']])) {
							$rStats[$rRun['src']]['switched_away']++;
						}
					}
					break;

				case in_array($rAction, self::QUALITY, true):
					$rBad = $rSrc !== '' ? $rSrc : ($rRun['src'] ?? '');
					if (isset($rStats[$rBad])) {
						$rStats[$rBad]['quality_issues']++;
					}
					if ($rRun !== null) {
						$rOpen[$rServer]['planned'] = true; // counted here, not again as a failure
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
		foreach ($rStats as &$rRow) {
			$rRow['uptime_sec'] = min($rRow['uptime_sec'], $rWindow * max(1, count($rLast)));
			[$rRow['score'], $rRow['confidence']] = self::score($rRow);
		}
		unset($rRow);

		return array_values($rStats);
	}

	/**
	 * Score 0–100 for one source, or null when nothing is known about it.
	 *
	 *   start rate  (35%)  starts / (starts + failed starts)
	 *   stability   (45%)  1 − e^(−MTBF / 2h): 2 h between failures ≈ 0.63, 6 h ≈ 0.95
	 *   quality     (20%)  1 / (1 + quality problems per hour on air)
	 *
	 * @param array<string, mixed> $rRow
	 * @return array{0: ?int, 1: string} [score, confidence: none|low|medium|high]
	 */
	public static function score(array $rRow): array {
		$rAttempts = (int) $rRow['starts'] + (int) $rRow['start_fails'];
		$rHours = ((int) $rRow['uptime_sec']) / 3600;
		if ($rAttempts === 0 && $rHours <= 0) {
			return [null, 'none'];
		}
		$rStartRate = $rAttempts > 0 ? ((int) $rRow['starts']) / $rAttempts : 1.0;
		$rMtbf = $rHours / max(1, (int) $rRow['failures']);
		$rStability = 1 - exp(-$rMtbf / 2);
		$rQuality = 1 / (1 + ($rHours > 0 ? ((int) $rRow['quality_issues']) / $rHours : (int) $rRow['quality_issues']));
		$rScore = (int) round(100 * (0.35 * $rStartRate + 0.45 * $rStability + 0.20 * $rQuality));

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
	 * Source indexes best first: score descending, unknown last, ties keep the
	 * operator's order. A suggestion only — nothing here changes how streams run.
	 *
	 * @param list<array<string, mixed>> $rRows output of compute()
	 * @return list<int>
	 */
	public static function suggestedOrder(array $rRows): array {
		usort($rRows, static function (array $a, array $b): int {
			$rA = $a['score'] ?? -1;
			$rB = $b['score'] ?? -1;
			return $rA === $rB ? $a['index'] <=> $b['index'] : $rB <=> $rA;
		});
		return array_map(static fn (array $r): int => (int) $r['index'], $rRows);
	}

	/** @return array<string, mixed> */
	private static function blank(int $rIndex, string $rSource): array {
		return [
			'index' => $rIndex, 'source' => $rSource,
			'starts' => 0, 'start_fails' => 0, 'failures' => 0,
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
	 * @param array{src:string, since:int, planned:bool} $rRun
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
