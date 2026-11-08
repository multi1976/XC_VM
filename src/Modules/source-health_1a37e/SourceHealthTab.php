<?php

namespace XcVm\Module\SourceHealth;

use XcVm\Core\Config\SettingsManager;

/**
 * The "Source Health" tab on the Edit Stream page: a read-only report of how
 * each of the stream's sources has behaved, the shape of its output, and the
 * order the figures suggest. It changes nothing; the operator decides.
 */
final class SourceHealthTab {
	/** Report window when log retention allows it. */
	public const WINDOW_SEC = 7 * 86400;

	/** @param array<string, mixed>|null $rStream the stream row as stored */
	public static function render(?array $rStream, string $rMode): string {
		if ($rStream === null || $rMode !== 'edit' || empty($rStream['id'])) {
			return self::note('The report is available once the stream has been saved and has run.');
		}
		$rSources = json_decode((string) ($rStream['stream_source'] ?? ''), true);
		$rSources = is_array($rSources) ? array_values(array_filter(array_map(static fn ($s): string => trim((string) $s), $rSources), static fn (string $s): bool => $s !== '')) : [];
		if ($rSources === []) {
			return self::note('This stream has no sources.');
		}

		try {
			$rNow = time();
			$rFrom = $rNow - self::window();
			$rStreamID = (int) $rStream['id'];
			$rRows = SourceHealthCalculator::compute($rSources, SourceHealthRepository::events($rStreamID, $rFrom), $rFrom, $rNow, SourceHealthRepository::running($rStreamID));
			$rProfiles = SourceHealthRepository::profiles($rStreamID);
		} catch (\Throwable $e) {
			error_log('source-health: ' . $e->getMessage());
			return self::note('The report could not be built: ' . $e->getMessage());
		}

		return self::table($rRows, $rProfiles, $rFrom, $rNow);
	}

	/** Seven days, or less when the panel keeps stream logs for less. */
	private static function window(): int {
		$rKeep = (int) SettingsManager::get('keep_restarts', 0);
		return $rKeep > 0 ? min(self::WINDOW_SEC, $rKeep) : self::WINDOW_SEC;
	}

	/**
	 * @param list<array<string, mixed>> $rRows
	 * @param array<string, array<string, mixed>> $rProfiles
	 */
	private static function table(array $rRows, array $rProfiles, int $rFrom, int $rNow): string {
		$rOrder = SourceHealthCalculator::suggestedOrder($rRows);
		$rByIndex = array_column($rRows, null, 'index');

		// Compatibility groups, lettered from the best-scored source's group down.
		$rGroups = [];
		foreach ($rOrder as $rIndex) {
			$rKey = (string) ($rProfiles[md5($rByIndex[$rIndex]['source'])]['fingerprint'] ?? '');
			if ($rKey !== '' && !isset($rGroups[$rKey])) {
				$rGroups[$rKey] = chr(ord('A') + min(25, count($rGroups)));
			}
		}

		$h = static fn ($v): string => htmlspecialchars((string) $v, ENT_QUOTES);
		$rHtml = '';
		if ((int) SettingsManager::get('save_restart_logs', 1) === 0) {
			$rHtml .= '<div class="alert alert-warning">Stream logs are not being saved (Settings → <em>Save restart logs</em> is off), so this report cannot see new events.</div>';
		}
		$rHtml .= '<p class="text-muted mb-2">From ' . $h(date('Y-m-d H:i', $rFrom)) . ' to now, all servers. Read-only: nothing here changes how the stream runs.</p>';
		$rHtml .= '<div class="table-responsive"><table class="table table-sm table-hover mb-2"><thead><tr>'
			. '<th>#</th><th>Source</th><th class="text-center">Score</th><th class="text-center">Starts / failed</th>'
			. '<th class="text-center">Failures</th><th class="text-center">Quality issues</th><th class="text-center">On air</th>'
			. '<th class="text-center">MTBF</th><th>Last failure</th><th>Output profile</th></tr></thead><tbody>';

		foreach ($rRows as $rRow) {
			$rProfile = $rProfiles[md5($rRow['source'])] ?? null;
			$rGroup = $rProfile !== null ? ($rGroups[(string) $rProfile['fingerprint']] ?? '') : '';
			$rMtbf = $rRow['failures'] > 0 ? self::duration(intdiv((int) $rRow['uptime_sec'], (int) $rRow['failures'])) : ($rRow['uptime_sec'] > 0 ? '&gt; ' . self::duration((int) $rRow['uptime_sec']) : '–');
			$rHtml .= '<tr>'
				. '<td>' . ((int) $rRow['index'] + 1) . ($rRow['running'] ? ' <span class="badge bg-label-success" title="On air now">live</span>' : '') . '</td>'
				. '<td><code title="' . $h(self::mask($rRow['source'])) . '">' . $h(self::mask($rRow['source'])) . '</code></td>'
				. '<td class="text-center">' . self::scoreBadge($rRow['score'], (string) $rRow['confidence']) . '</td>'
				. '<td class="text-center">' . (int) $rRow['starts'] . ' / ' . (int) $rRow['start_fails'] . '</td>'
				. '<td class="text-center">' . (int) $rRow['failures'] . '</td>'
				. '<td class="text-center">' . (int) $rRow['quality_issues'] . '</td>'
				. '<td class="text-center">' . ($rRow['uptime_sec'] > 0 ? self::duration((int) $rRow['uptime_sec']) : '–') . '</td>'
				. '<td class="text-center">' . $rMtbf . '</td>'
				. '<td>' . ($rRow['last_failure'] > 0 ? $h(date('Y-m-d H:i', (int) $rRow['last_failure'])) : '–') . '</td>'
				. '<td>' . ($rProfile !== null
					? '<span class="badge bg-label-secondary">' . $h($rGroup) . '</span> ' . $h($rProfile['label'])
						. ((int) ($rProfile['bitrate'] ?? 0) > 0 ? ' <span class="text-muted">· ' . round(((int) $rProfile['bitrate']) / 1000, 1) . ' Mbps</span>' : '')
					: '<span class="text-muted">not seen on air yet</span>')
				. '</td></tr>';
		}
		$rHtml .= '</tbody></table></div>';

		$rKnown = array_values(array_filter($rOrder, static fn (int $i): bool => $rByIndex[$i]['score'] !== null));
		if (count($rKnown) > 1) {
			$rLabels = array_map(static fn (int $i): string => '#' . ($i + 1), $rOrder);
			$rSame = $rOrder === array_keys($rByIndex);
			$rHtml .= '<p class="mb-1"><strong>Suggested order:</strong> ' . $h(implode(', ', $rLabels))
				. ($rSame ? ' <span class="text-muted">(the current order)</span>' : '') . '</p>';
		}
		if (count($rGroups) > 1) {
			$rHtml .= '<p class="text-muted mb-1">Sources in the same profile group (same letter) can replace each other with only a timestamp jump. '
				. 'A switch between groups makes players re-initialise their decoder, which many set-top boxes handle badly.</p>';
		}
		$rHtml .= '<p class="text-muted small mb-0">Score 0–100: start success 35%, stability (time between failures) 45%, quality issues per hour 20%. '
			. 'Low confidence means little data. Profiles are recorded every 5 minutes while a source is on air.</p>';
		return $rHtml;
	}

	private static function scoreBadge(?int $rScore, string $rConfidence): string {
		if ($rScore === null) {
			return '<span class="text-muted">no data</span>';
		}
		$rClass = $rScore >= 80 ? 'bg-label-success' : ($rScore >= 50 ? 'bg-label-warning' : 'bg-label-danger');
		return '<span class="badge ' . $rClass . '">' . $rScore . '</span>'
			. ($rConfidence === 'high' ? '' : ' <small class="text-muted">' . htmlspecialchars($rConfidence, ENT_QUOTES) . ' conf.</small>');
	}

	/** scheme://host:port/…/last-segment — enough to tell sources apart without printing credentials. */
	public static function mask(string $rSource): string {
		$rParts = parse_url($rSource);
		if (!is_array($rParts) || empty($rParts['host'])) {
			return mb_strlen($rSource) > 48 ? mb_substr($rSource, 0, 48) . '…' : $rSource;
		}
		$rOut = ($rParts['scheme'] ?? '') !== '' ? $rParts['scheme'] . '://' : '';
		$rOut .= $rParts['host'] . (isset($rParts['port']) ? ':' . $rParts['port'] : '');
		$rPath = trim((string) ($rParts['path'] ?? ''), '/');
		if ($rPath !== '') {
			$rSegments = explode('/', $rPath);
			$rOut .= '/' . (count($rSegments) > 1 ? '…/' : '') . end($rSegments);
		}
		return $rOut;
	}

	public static function duration(int $rSeconds): string {
		if ($rSeconds < 60) {
			return $rSeconds . 's';
		}
		if ($rSeconds < 3600) {
			return intdiv($rSeconds, 60) . 'm';
		}
		if ($rSeconds < 86400) {
			return intdiv($rSeconds, 3600) . 'h ' . intdiv($rSeconds % 3600, 60) . 'm';
		}
		return intdiv($rSeconds, 86400) . 'd ' . intdiv($rSeconds % 86400, 3600) . 'h';
	}

	private static function note(string $rText): string {
		return '<p class="text-muted mb-0">' . htmlspecialchars($rText, ENT_QUOTES) . '</p>';
	}
}
