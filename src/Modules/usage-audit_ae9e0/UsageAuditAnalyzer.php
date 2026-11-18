<?php

namespace XcVm\Module\UsageAudit;

/**
 * The arithmetic of the Usage Audit, without the database: sessions clipped
 * to the window, summed into viewer-hours, counted at their busiest moment,
 * spread over the hours of the day.
 */
final class UsageAuditAnalyzer {
	/** A line counts as a viewer of something only after this long on it: zapping past a channel is not watching it. */
	public const MIN_WATCH_SEC = 60;

	/**
	 * Usage per group over [from, to).
	 *
	 * A session is `[user_id, stream_id, date_start, date_end]`, date_end 0 for
	 * one still running (it runs to `to`). Restreamer lines are kept out of
	 * the viewer counts (one restreamer is any number of unseen viewers) and
	 * counted apart, per group, as `restream`.
	 *
	 * @param list<array{0:int,1:int,2:int,3:int}> $rSessions
	 * @param array<int, true>                     $rRestreamers line id => true
	 * @param array<int, int|string>               $rGroupOf     stream id => group key; a stream not in it is its own group
	 * @return array{groups: array<int|string, array{secs:int, sessions:int, lines:int, peak:int, peak_at:int, restream:int}>, total: array{secs:int, sessions:int, lines:int, peak:int, peak_at:int}, hours: array<int, int>}
	 */
	public static function usage(array $rSessions, int $rFrom, int $rTo, array $rRestreamers = [], ?array $rGroupOf = null): array {
		$rGroups = [];
		$rSpans = [];
		$rWatch = [];
		$rRestream = [];
		$rAll = [];
		$rLineSecs = [];
		$rHours = array_fill(0, 24, 0);
		$rTotal = ['secs' => 0, 'sessions' => 0];
		foreach ($rSessions as [$rUser, $rStream, $rStart, $rEnd]) {
			$s = max((int) $rStart, $rFrom);
			$e = min((int) $rEnd > 0 ? (int) $rEnd : $rTo, $rTo);
			if ($e <= $s) {
				continue;
			}
			$rKey = $rGroupOf === null ? (int) $rStream : ($rGroupOf[(int) $rStream] ?? (int) $rStream);
			if (isset($rRestreamers[(int) $rUser])) {
				$rRestream[$rKey][(int) $rUser] = true;
				continue;
			}
			$rGroups[$rKey] ??= ['secs' => 0, 'sessions' => 0];
			$rGroups[$rKey]['secs'] += $e - $s;
			$rGroups[$rKey]['sessions']++;
			$rSpans[$rKey][] = [$s, $e];
			$rWatch[$rKey][(int) $rUser] = ($rWatch[$rKey][(int) $rUser] ?? 0) + $e - $s;
			$rLineSecs[(int) $rUser] = ($rLineSecs[(int) $rUser] ?? 0) + $e - $s;
			$rAll[] = [$s, $e];
			$rTotal['secs'] += $e - $s;
			$rTotal['sessions']++;
			self::spread($rHours, $s, $e);
		}
		$rOut = [];
		foreach ($rGroups as $rKey => $g) {
			[$rPeak, $rAt] = self::peak($rSpans[$rKey]);
			$rOut[$rKey] = $g + [
				'lines' => count(array_filter($rWatch[$rKey], static fn (int $v): bool => $v >= self::MIN_WATCH_SEC)),
				'peak' => $rPeak, 'peak_at' => $rAt, 'restream' => count($rRestream[$rKey] ?? []),
			];
		}
		foreach ($rRestream as $rKey => $rLines) {
			$rOut[$rKey] ??= ['secs' => 0, 'sessions' => 0, 'lines' => 0, 'peak' => 0, 'peak_at' => 0, 'restream' => count($rLines)];
		}
		uasort($rOut, static fn (array $a, array $b): int => [$b['secs'], $b['restream']] <=> [$a['secs'], $a['restream']]);
		[$rPeak, $rAt] = self::peak($rAll);
		return [
			'groups' => $rOut,
			'total' => $rTotal + ['lines' => count(array_filter($rLineSecs, static fn (int $v): bool => $v >= self::MIN_WATCH_SEC)), 'peak' => $rPeak, 'peak_at' => $rAt],
			'hours' => $rHours,
		];
	}

	/**
	 * Most at once, and when it was first reached. One session handing over
	 * to the next in the same second is one viewer, not two.
	 *
	 * @param list<array{0:int,1:int}> $rSpans [start, end)
	 * @return array{0:int, 1:int}
	 */
	public static function peak(array $rSpans): array {
		$rEvents = [];
		foreach ($rSpans as [$s, $e]) {
			$rEvents[] = [$s, 1];
			$rEvents[] = [$e, -1];
		}
		usort($rEvents, static fn (array $a, array $b): int => [$a[0], $a[1]] <=> [$b[0], $b[1]]);   // ends before starts
		$rNow = 0;
		$rMax = 0;
		$rAt = 0;
		foreach ($rEvents as [$t, $d]) {
			$rNow += $d;
			if ($rNow > $rMax) {
				$rMax = $rNow;
				$rAt = $t;
			}
		}
		return [$rMax, $rAt];
	}

	/**
	 * Average viewers in each hour of the day over the window: viewer-seconds
	 * spent in that hour, over the seconds the window has of it.
	 *
	 * @param array<int, int> $rHours hour => viewer-seconds
	 * @return array<int, float>
	 */
	public static function hourly(array $rHours, int $rFrom, int $rTo): array {
		$rAvail = array_fill(0, 24, 0);
		self::spread($rAvail, $rFrom, $rTo);
		$rOut = [];
		foreach ($rHours as $h => $v) {
			$rOut[$h] = $rAvail[$h] > 0 ? round($v / $rAvail[$h], 2) : 0.0;
		}
		return $rOut;
	}

	/** @param array<int, int> $rHours hour of the day (panel time zone) => seconds, added to */
	private static function spread(array &$rHours, int $s, int $e): void {
		while ($s < $e) {
			$rNext = min($e, (intdiv($s, 3600) + 1) * 3600);   // hour boundaries are the same in every whole-hour time zone
			$rHours[(int) date('G', $s)] += $rNext - $s;
			$s = $rNext;
		}
	}

	/** "3h 20m", "45m", "20s". */
	public static function duration(int $rSecs): string {
		if ($rSecs >= 3600) {
			return intdiv($rSecs, 3600) . 'h' . ($rSecs % 3600 >= 60 ? ' ' . intdiv($rSecs % 3600, 60) . 'm' : '');
		}
		return $rSecs >= 60 ? intdiv($rSecs, 60) . 'm' : $rSecs . 's';
	}

	/**
	 * A category separator: a stream named "=Name=" (or "=== Name ===") that
	 * only heads a group of channels in players without categories.
	 */
	public static function isSeparator(string $rName): bool {
		return (bool) preg_match('/^\s*=.*=\s*$/su', $rName) && mb_strlen(trim($rName)) >= 2;
	}

	/** @return list<int> ids from a JSON list column ("[1,2]"), or a plain number */
	public static function ids(?string $rJson): array {
		$rList = json_decode((string) $rJson, true);
		if (is_int($rList) || (is_string($rList) && ctype_digit($rList))) {
			return [(int) $rList];
		}
		return is_array($rList) ? array_values(array_filter(array_map('intval', $rList), static fn (int $v): bool => $v > 0)) : [];
	}
}
