<?php

namespace XcVm\Module\SourceHealth;

/**
 * A stream's health seen through its sources, from the audit's verdict on
 * each one (dead, down, ok, on air, not checked). PURE.
 *
 * Levels, worst first:
 *   no_working  every checked source is off and none is up: the stream cannot
 *               start (or keep) a working source;
 *   no_backup   one source works and every other is off: the next drop has
 *               nothing to fall back to;
 *   mostly_off  half or more of its sources are off;
 *   some_off    some of its sources are off;
 *   healthy     none is off (not listed by default).
 */
final class StreamHealth {
	public const LEVELS = ['no_working', 'no_backup', 'mostly_off', 'some_off', 'healthy'];

	/**
	 * @param list<string> $rStatuses the audit state of each source, in list order: on_air, ok, dead, down, waiting, pending, skip
	 * @return array{level:string, total:int, dead:int, down:int, off:int, working:int, unknown:int, first_working:?int, off_before_working:int, off_share:float}
	 */
	public static function assess(array $rStatuses): array {
		$rCount = static fn (array $rWhich): int => count(array_filter($rStatuses, static fn (string $s): bool => in_array($s, $rWhich, true)));
		$rDead = $rCount(['dead']);
		$rDown = $rCount(['down']);
		$rOff = $rDead + $rDown;
		$rWorking = $rCount(['on_air', 'ok']);
		$rTotal = count($rStatuses);
		$rFirst = null;
		$rBefore = 0;
		foreach ($rStatuses as $i => $rStatus) {
			if (in_array($rStatus, ['on_air', 'ok'], true)) {
				$rFirst = $i;
				break;
			}
			if (in_array($rStatus, ['dead', 'down'], true)) {
				$rBefore++;
			}
		}
		$rShare = $rTotal > 0 ? $rOff / $rTotal : 0.0;
		$rLevel = match (true) {
			$rOff === 0                    => 'healthy',
			$rWorking === 0                => 'no_working',
			$rWorking === 1 && $rOff === $rTotal - 1 => 'no_backup',
			$rShare >= 0.5                 => 'mostly_off',
			default                        => 'some_off',
		};
		return [
			'level' => $rLevel, 'total' => $rTotal, 'dead' => $rDead, 'down' => $rDown, 'off' => $rOff, 'working' => $rWorking,
			'unknown' => $rTotal - $rOff - $rWorking, 'first_working' => $rFirst, 'off_before_working' => $rFirst === null ? 0 : $rBefore, 'off_share' => $rShare,
		];
	}

	/** Worst first: level, then the share of sources off, then how many, then name. */
	public static function compare(array $a, array $b): int {
		return [array_search($a['health']['level'], self::LEVELS, true), -$a['health']['off_share'], -$a['health']['off'], $a['name']]
			<=> [array_search($b['health']['level'], self::LEVELS, true), -$b['health']['off_share'], -$b['health']['off'], $b['name']];
	}
}
