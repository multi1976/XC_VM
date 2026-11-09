<?php

namespace XcVm\Module\ChannelAudit;

/**
 * Reads a file's packets (one pass of `ffprobe -show_entries packet=…`) and sums
 * up what matters for a channel built by concatenation: where each track starts
 * and ends, whether the video runs at a constant frame rate, the keyframe
 * pattern, timestamp order and audio continuity.
 *
 * PURE: fed one packet at a time, no I/O — the scanner streams ffprobe's output
 * into it, the tests feed it synthetic packets.
 *
 * Times are seconds (ffprobe's *_time fields). A packet flagged D (discard:
 * encoder priming, edit-list trims) is not played, so it does not count for
 * timing.
 */
final class PacketAnalyzer {
	/** Audio gaps/overlaps smaller than this are rounding, not a hole. */
	public const AUDIO_TOLERANCE = 0.005;

	private int $rVideoIndex;
	private int $rAudioIndex;

	/** @var list<float> */
	private array $rVideoPts = [];
	private ?float $rVideoEnd = null;
	private ?float $rLastVideoDts = null;
	private int $rVideoPackets = 0;
	private int $rVideoNoTimestamp = 0;
	private int $rDtsBackwards = 0;
	private ?bool $rFirstVideoKey = null;
	private int $rSinceKey = -1;
	/** @var array<int, int> GOP length (frames) => how many GOPs had it, last GOP excluded */
	private array $rGops = [];
	private int $rKeyframes = 0;
	private int $rCorrupt = 0;

	private ?float $rAudioStart = null;
	private ?float $rAudioEnd = null;
	private int $rAudioPackets = 0;
	private int $rAudioNoTimestamp = 0;
	private int $rAudioGaps = 0;
	private int $rAudioOverlaps = 0;
	private float $rAudioMaxGap = 0.0;
	private float $rAudioMaxOverlap = 0.0;
	private ?float $rAudioGapAt = null;

	public function __construct(int $rVideoIndex, int $rAudioIndex = -1) {
		$this->rVideoIndex = $rVideoIndex;
		$this->rAudioIndex = $rAudioIndex;
	}

	/**
	 * One line of `ffprobe -show_entries packet=stream_index,pts_time,dts_time,duration_time,flags -of csv=p=0`:
	 * "0,0.040000,-0.040000,0.040000,K__" (a trailing field may follow: side data).
	 */
	public function feedCsv(string $rLine): void {
		$rParts = explode(',', trim($rLine));
		if (count($rParts) < 5 || !ctype_digit($rParts[0])) {
			return;
		}
		$this->feed((int) $rParts[0], self::num($rParts[1]), self::num($rParts[2]), self::num($rParts[3]), $rParts[4]);
	}

	public function feed(int $rStream, ?float $rPts, ?float $rDts, ?float $rDuration, string $rFlags): void {
		if ($rStream === $this->rVideoIndex) {
			$this->video($rPts, $rDts, $rDuration, $rFlags);
		} elseif ($rStream === $this->rAudioIndex) {
			$this->audio($rPts, $rDuration, $rFlags);
		}
	}

	private function video(?float $rPts, ?float $rDts, ?float $rDuration, string $rFlags): void {
		$this->rVideoPackets++;
		$rKey = str_contains($rFlags, 'K');
		if (str_contains($rFlags, 'C')) {
			$this->rCorrupt++;
		}
		$this->rFirstVideoKey ??= $rKey;
		if ($rKey) {
			if ($this->rSinceKey > 0) {
				$this->rGops[$this->rSinceKey] = ($this->rGops[$this->rSinceKey] ?? 0) + 1;
			}
			$this->rSinceKey = 0;
			$this->rKeyframes++;
		}
		if ($this->rSinceKey >= 0) {
			$this->rSinceKey++;
		}

		if ($rDts === null && $rPts === null) {
			$this->rVideoNoTimestamp++;
			return;
		}
		$rDts ??= $rPts;
		if ($this->rLastVideoDts !== null && $rDts <= $this->rLastVideoDts) {
			$this->rDtsBackwards++;
		}
		$this->rLastVideoDts = $rDts;

		if (str_contains($rFlags, 'D') || $rPts === null) {
			return;
		}
		$this->rVideoPts[] = $rPts;
		$rEnd = $rPts + ($rDuration ?? 0.0);
		$this->rVideoEnd = $this->rVideoEnd === null ? $rEnd : max($this->rVideoEnd, $rEnd);
	}

	private function audio(?float $rPts, ?float $rDuration, string $rFlags): void {
		$this->rAudioPackets++;
		if (str_contains($rFlags, 'D')) {
			return;
		}
		if ($rPts === null) {
			$this->rAudioNoTimestamp++;
			return;
		}
		if ($this->rAudioEnd !== null) {
			$rGap = $rPts - $this->rAudioEnd;
			if ($rGap > self::AUDIO_TOLERANCE) {
				$this->rAudioGaps++;
				if ($rGap > $this->rAudioMaxGap) {
					$this->rAudioMaxGap = $rGap;
					$this->rAudioGapAt = $this->rAudioEnd;
				}
			} elseif ($rGap < -self::AUDIO_TOLERANCE) {
				$this->rAudioOverlaps++;
				$this->rAudioMaxOverlap = max($this->rAudioMaxOverlap, -$rGap);
			}
		}
		$this->rAudioStart ??= $rPts;
		$this->rAudioEnd = $rPts + ($rDuration ?? 0.0);
	}

	/**
	 * @param float|null $rFrameDuration the expected 1/fps; null = take the most common step.
	 * @return array<string, mixed>
	 */
	public function finish(?float $rFrameDuration = null): array {
		sort($this->rVideoPts);
		$rSteps = [];
		$rCount = count($this->rVideoPts);
		for ($i = 1; $i < $rCount; $i++) {
			$rSteps[] = $this->rVideoPts[$i] - $this->rVideoPts[$i - 1];
		}

		if ($rFrameDuration === null || $rFrameDuration <= 0) {
			$rFrameDuration = self::mode($rSteps);
		}
		$rIrregular = 0;
		$rDuplicates = 0;
		$rMaxStep = 0.0;
		$rMaxStepAt = null;
		$rMaxDev = 0.0;
		$rMaxDevAt = null;
		if ($rFrameDuration !== null) {
			$rTolerance = max(0.0015, $rFrameDuration * 0.05);
			foreach ($rSteps as $i => $rStep) {
				if ($rStep < 0.0005) {
					$rDuplicates++;
				} elseif (abs($rStep - $rFrameDuration) > $rTolerance) {
					$rIrregular++;
				}
				if (abs($rStep - $rFrameDuration) > $rMaxDev) {
					$rMaxDev = abs($rStep - $rFrameDuration);
					$rMaxDevAt = $this->rVideoPts[$i];
				}
				if ($rStep > $rMaxStep) {
					$rMaxStep = $rStep;
					$rMaxStepAt = $this->rVideoPts[$i];
				}
			}
		}

		ksort($this->rGops);
		$rVideoStart = $rCount > 0 ? $this->rVideoPts[0] : null;
		return [
			'video' => [
				'packets'       => $this->rVideoPackets,
				'frames'        => $rCount,
				'start'         => $rVideoStart,
				'end'           => $this->rVideoEnd,
				'duration'      => ($rVideoStart !== null && $this->rVideoEnd !== null) ? $this->rVideoEnd - $rVideoStart : 0.0,
				'first_key'     => $this->rFirstVideoKey ?? false,
				'keyframes'     => $this->rKeyframes,
				'gops'          => $this->rGops,
				'last_gop'      => max(0, $this->rSinceKey),
				'frame_step'    => $rFrameDuration,
				'irregular'     => $rIrregular,
				'duplicates'    => $rDuplicates,
				'max_step'      => $rMaxStep,
				'max_step_at'   => $rMaxStepAt,
				'max_dev'       => $rMaxDev,
				'max_dev_at'    => $rMaxDevAt,
				'dts_backwards' => $this->rDtsBackwards,
				'no_timestamp'  => $this->rVideoNoTimestamp,
				'corrupt'       => $this->rCorrupt,
			],
			'audio' => $this->rAudioIndex < 0 ? null : [
				'packets'      => $this->rAudioPackets,
				'start'        => $this->rAudioStart,
				'end'          => $this->rAudioEnd,
				'duration'     => ($this->rAudioStart !== null && $this->rAudioEnd !== null) ? $this->rAudioEnd - $this->rAudioStart : 0.0,
				'gaps'         => $this->rAudioGaps,
				'max_gap'      => $this->rAudioMaxGap,
				'max_gap_at'   => $this->rAudioGapAt,
				'overlaps'     => $this->rAudioOverlaps,
				'max_overlap'  => $this->rAudioMaxOverlap,
				'no_timestamp' => $this->rAudioNoTimestamp,
			],
		];
	}

	/** The most common step, rounded to 0.1 ms (null when there are none). */
	private static function mode(array $rSteps): ?float {
		$rCounts = [];
		foreach ($rSteps as $rStep) {
			if ($rStep > 0.0005) {
				$rKey = (string) round($rStep, 4);
				$rCounts[$rKey] = ($rCounts[$rKey] ?? 0) + 1;
			}
		}
		if ($rCounts === []) {
			return null;
		}
		arsort($rCounts);
		return (float) array_key_first($rCounts);
	}

	private static function num(string $rValue): ?float {
		return is_numeric($rValue) ? (float) $rValue : null;
	}
}
