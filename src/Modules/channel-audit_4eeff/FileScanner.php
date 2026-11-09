<?php

namespace XcVm\Module\ChannelAudit;

/**
 * Physically checks one file: ffprobe's stream list, then a full read of every
 * packet (PacketAnalyzer), then — when asked — a full decode. Commands run
 * without a shell (proc_open with an argument list) at low CPU and I/O
 * priority, so an audit does not starve the streams on the server.
 */
final class FileScanner {
	private string $rProbe;
	private string $rFfmpeg;
	/** @var list<string> */
	private array $rNice = [];

	public function __construct(string $rProbe, string $rFfmpeg) {
		$this->rProbe = $rProbe;
		$this->rFfmpeg = $rFfmpeg;
		if (is_executable('/usr/bin/nice')) {
			$this->rNice = ['/usr/bin/nice', '-n', '15'];
		}
		if (is_executable('/usr/bin/ionice')) {
			$this->rNice = array_merge($this->rNice, ['/usr/bin/ionice', '-c', '3']);
		}
	}

	/**
	 * @param string $rInput a local path or an http(s) URL
	 * @param array<string, mixed> $rSpec AuditStandard::normalize() output
	 * @param (callable(): bool)|null $rStop polled while a command runs; true aborts it
	 * @return array{status: string, issues: list<array{0:string,1:string,2:string}>, facts: array<string, mixed>}
	 */
	public function scan(string $rInput, array $rSpec, bool $rDeep, ?callable $rStop = null): array {
		$rRemote = (bool) preg_match('~^https?://~i', $rInput);
		$rNet = $rRemote ? ['-rw_timeout', '30000000'] : [];

		[$rCode, $rJson, $rErr] = $this->run(array_merge(['timeout', '120', $this->rProbe, '-v', 'error'], $rNet, ['-show_streams', '-show_format', '-of', 'json', $rInput]), null, $rStop);
		$rMeta = json_decode($rJson, true);
		if ($rCode !== 0 || !is_array($rMeta) || empty($rMeta['streams'])) {
			return self::error('the file could not be opened: ' . (trim($rErr) !== '' ? mb_substr(trim(strtok($rErr, "\n")), 0, 200) : 'ffprobe exit ' . $rCode));
		}
		$rStreams = $rMeta['streams'];

		$rVideo = $rAudio = -1;
		foreach ($rStreams as $rStream) {
			$rType = $rStream['codec_type'] ?? '';
			if ($rType === 'video' && $rVideo < 0 && empty($rStream['disposition']['attached_pic'])) {
				$rVideo = (int) $rStream['index'];
			} elseif ($rType === 'audio' && $rAudio < 0) {
				$rAudio = (int) $rStream['index'];
			}
		}

		$rPackets = null;
		if ($rVideo >= 0) {
			$rAnalyzer = new PacketAnalyzer($rVideo, $rAudio);
			[$rCode, , $rErr] = $this->run(
				array_merge($this->rNice, [$this->rProbe, '-v', 'error'], $rNet, ['-show_entries', 'packet=stream_index,pts_time,dts_time,duration_time,flags', '-of', 'csv=p=0', $rInput]),
				static function (string $rLine) use ($rAnalyzer): void {
					$rAnalyzer->feedCsv($rLine);
				},
				$rStop
			);
			if ($rCode === 0) {
				$rStep = AuditStandard::rate((string) $rSpec['fps']);
				$rPackets = $rAnalyzer->finish($rStep !== null ? 1 / $rStep : null);
			}
		}

		$rDecodeErrors = null;
		if ($rDeep && $rVideo >= 0) {
			$rMaps = ['-map', '0:' . $rVideo];
			if ($rAudio >= 0) {
				array_push($rMaps, '-map', '0:' . $rAudio);
			}
			[$rCode, , $rErr] = $this->run(array_merge($this->rNice, [$this->rFfmpeg, '-nostdin', '-hide_banner', '-v', 'error'], $rNet, ['-i', $rInput], $rMaps, ['-f', 'null', '-']), null, $rStop);
			$rDecodeErrors = array_values(array_filter(array_map('trim', explode("\n", $rErr)), static fn (string $l): bool => $l !== ''));
			if ($rCode !== 0 && $rDecodeErrors === []) {
				$rDecodeErrors = ['the decoder stopped with exit code ' . $rCode];
			}
		}

		$rResult = AuditStandard::check($rSpec, $rStreams, $rPackets, $rDecodeErrors);
		$rResult['facts']['format'] = (string) ($rMeta['format']['format_name'] ?? '');
		$rResult['facts']['size'] = isset($rMeta['format']['size']) ? (int) $rMeta['format']['size'] : null;
		if ($rDecodeErrors !== null) {
			$rResult['facts']['decode_errors'] = array_slice($rDecodeErrors, 0, 20);
		}
		return $rResult;
	}

	/** @return array{status: string, issues: list<array{0:string,1:string,2:string}>, facts: array<string, mixed>} */
	public static function error(string $rMessage): array {
		return ['status' => 'error', 'issues' => [['fail', 'file', $rMessage]], 'facts' => []];
	}

	/**
	 * Run a command (no shell). stdout goes to $rOnLine line by line when given,
	 * else it is returned; stderr is returned (at most 64 KB).
	 *
	 * @param list<string> $rCommand
	 * @return array{0: int, 1: string, 2: string} [exit code, stdout, stderr]
	 */
	private function run(array $rCommand, ?callable $rOnLine, ?callable $rStop): array {
		$rProc = proc_open($rCommand, [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $rPipes);
		if (!is_resource($rProc)) {
			return [127, '', 'could not start ' . $rCommand[0]];
		}
		stream_set_blocking($rPipes[1], false);
		stream_set_blocking($rPipes[2], false);
		$rOut = $rErr = $rBuffer = '';
		$rLastPoll = microtime(true);
		$rStopped = false;

		while (true) {
			$rRead = [$rPipes[1], $rPipes[2]];
			$rWrite = $rExcept = null;
			if (@stream_select($rRead, $rWrite, $rExcept, 1) === false) {
				break;
			}
			foreach ($rRead as $rPipe) {
				$rChunk = fread($rPipe, 65536);
				if ($rChunk === false || $rChunk === '') {
					continue;
				}
				if ($rPipe === $rPipes[2]) {
					if (strlen($rErr) < 65536) {
						$rErr .= $rChunk;
					}
				} elseif ($rOnLine === null) {
					$rOut .= $rChunk;
				} else {
					$rBuffer .= $rChunk;
					$rLines = explode("\n", $rBuffer);
					$rBuffer = (string) array_pop($rLines);
					foreach ($rLines as $rLine) {
						$rOnLine($rLine);
					}
				}
			}
			if (feof($rPipes[1]) && feof($rPipes[2])) {
				break;
			}
			if ($rStop !== null && microtime(true) - $rLastPoll >= 2) {
				$rLastPoll = microtime(true);
				if ($rStop()) {
					$rStopped = true;
					proc_terminate($rProc, 9);
					break;
				}
			}
		}
		if ($rOnLine !== null && $rBuffer !== '') {
			$rOnLine($rBuffer);
		}
		fclose($rPipes[1]);
		fclose($rPipes[2]);
		$rCode = proc_close($rProc);
		return [$rStopped ? 130 : $rCode, $rOut, $rErr];
	}
}
