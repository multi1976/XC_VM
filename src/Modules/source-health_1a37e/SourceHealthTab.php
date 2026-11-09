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
			$rEvents = SourceHealthRepository::events($rStreamID, $rFrom);
			$rRunning = SourceHealthRepository::running($rStreamID);
			$rRows = SourceHealthCalculator::compute($rSources, $rEvents, $rFrom, $rNow, $rRunning);
			// The same log read over the last day only: what the source is like now.
			$rRecent = array_column(SourceHealthCalculator::compute($rSources, $rEvents, max($rFrom, $rNow - 86400), $rNow, $rRunning), null, 'index');
			$rProfiles = SourceHealthRepository::profiles($rStreamID);
		} catch (\Throwable $e) {
			error_log('source-health: ' . $e->getMessage());
			return self::note('The report could not be built: ' . $e->getMessage());
		}

		return self::table($rRows, $rRecent, $rProfiles, $rFrom, $rNow) . self::timeline($rRows, $rFrom, $rNow);
	}

	/**
	 * Incidents per day and hour, one row per source: shows whether a source
	 * fails all the time or in bad spells (an evening, one day of trouble).
	 *
	 * @param list<array<string, mixed>> $rRows
	 */
	private static function timeline(array $rRows, int $rFrom, int $rNow): string {
		$h = static fn ($v): string => htmlspecialchars((string) $v, ENT_QUOTES);
		$rDays = [];
		for ($t = strtotime(date('Y-m-d', $rFrom)); $t <= $rNow; $t = strtotime('+1 day', $t)) {
			$rDays[date('Y-m-d', $t)] = $t;
		}
		$rHtml = '<h6 class="mt-4 mb-1">Incident timeline</h6>'
			. '<p class="text-muted small mb-2">Incidents per day; the hours they started in underneath, the exact times on hover, and the attempts that did not start at all. A source that fails in spells looks very different from one that fails all the time.</p>'
			. '<div class="table-responsive"><table class="table table-sm table-bordered text-center mb-2"><thead><tr><th class="text-start">#</th>';
		foreach ($rDays as $rDay => $t) {
			$rHtml .= '<th>' . $h(date('D d.m', $t)) . '</th>';
		}
		$rHtml .= '</tr></thead><tbody>';
		foreach ($rRows as $rRow) {
			$rByDay = $rFailedStarts = [];
			foreach ($rRow['incident_times'] as $rTime) {
				$rByDay[date('Y-m-d', $rTime)][] = $rTime;
			}
			foreach ($rRow['start_fail_times'] as $rTime) {
				$rFailedStarts[date('Y-m-d', $rTime)] = ($rFailedStarts[date('Y-m-d', $rTime)] ?? 0) + 1;
			}
			$rHtml .= '<tr><td class="text-start">' . ((int) $rRow['index'] + 1) . ' <small class="text-muted">' . $h(self::mask($rRow['source'])) . '</small></td>';
			foreach (array_keys($rDays) as $rDay) {
				$rTimes = $rByDay[$rDay] ?? [];
				$rMissed = isset($rFailedStarts[$rDay]) ? '<br><small class="text-danger">' . $rFailedStarts[$rDay] . '× did not start</small>' : '';
				if ($rTimes === []) {
					$rHtml .= '<td class="text-muted">' . ($rMissed !== '' ? '–' . $rMissed : '·') . '</td>';
					continue;
				}
				$rHours = array_values(array_unique(array_map(static fn (int $t): string => date('H', $t), $rTimes)));
				$rClass = count($rTimes) >= 3 ? 'bg-label-danger' : 'bg-label-warning';
				$rHtml .= '<td class="' . $rClass . '" title="' . $h(implode(', ', array_map(static fn (int $t): string => date('H:i', $t), $rTimes))) . '">'
					. '<strong>' . count($rTimes) . '</strong><br><small>' . $h(implode(' ', array_map(static fn (string $x): string => $x . 'h', $rHours))) . '</small>' . $rMissed . '</td>';
			}
			$rHtml .= '</tr>';
		}
		return $rHtml . '</tbody></table></div>';
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
	/** @param array<int, array<string, mixed>> $rRecent compute() over the last 24 h, keyed by source index */
	private static function table(array $rRows, array $rRecent, array $rProfiles, int $rFrom, int $rNow): string {
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
			. '<th>#</th><th>Source</th><th class="text-center">Score</th><th class="text-center" title="The same figures over the last 24 hours only">Last 24h</th><th class="text-center">Starts / failed</th>'
			. '<th class="text-center">Failures</th><th class="text-center" title="Failures less than ' . intdiv(SourceHealthCalculator::INCIDENT_GAP_SEC, 60) . ' minutes apart count as one incident (one outage)">Incidents</th>'
			. '<th class="text-center">Quality issues</th><th class="text-center">On air</th>'
			. '<th class="text-center" title="Mean time on air between incidents">MTBF</th><th>Last failure</th><th>Output profile</th></tr></thead><tbody>';

		foreach ($rRows as $rRow) {
			$rProfile = $rProfiles[md5($rRow['source'])] ?? null;
			$rGroup = $rProfile !== null ? ($rGroups[(string) $rProfile['fingerprint']] ?? '') : '';
			$rMtbf = $rRow['incidents'] > 0 ? self::duration(intdiv((int) $rRow['uptime_sec'], (int) $rRow['incidents'])) : ($rRow['uptime_sec'] > 0 ? '&gt; ' . self::duration((int) $rRow['uptime_sec']) : '–');
			$rHtml .= '<tr>'
				. '<td>' . ((int) $rRow['index'] + 1) . ($rRow['running'] ? ' <span class="badge bg-label-success" title="On air now">live</span>' : '') . '</td>'
				. '<td><code title="' . $h(self::mask($rRow['source'])) . '">' . $h(self::mask($rRow['source'])) . '</code></td>'
				. '<td class="text-center">' . self::scoreBadge($rRow['score'], (string) $rRow['confidence']) . '</td>'
				. '<td class="text-center">' . self::recent($rRecent[$rRow['index']] ?? null) . '</td>'
				. '<td class="text-center">' . (int) $rRow['starts'] . ' / ' . (int) $rRow['start_fails'] . '</td>'
				. '<td class="text-center">' . (int) $rRow['failures'] . '</td>'
				. '<td class="text-center">' . (int) $rRow['incidents'] . '</td>'
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
		$rHtml .= '<p class="text-muted small mb-0">Score 0–100 = chance the source starts × chance it stays up (time between incidents; 6 h ≈ 63, 24 h ≈ 98) × quality issues per hour. '
			. 'Failures less than ' . intdiv(SourceHealthCalculator::INCIDENT_GAP_SEC, 60) . ' minutes apart are one incident: a burst of retries during one outage counts once. '
			. 'Low confidence means little data. Profiles are recorded every 5 minutes while a source is on air.</p>';
		return $rHtml;
	}

	/** Last-24h cell: score and incident count, or "–" when the source was not tried. */
	private static function recent(?array $rRow): string {
		if ($rRow === null || $rRow['score'] === null) {
			return '<span class="text-muted">–</span>';
		}
		return self::scoreBadge($rRow['score'], (string) $rRow['confidence'])
			. '<br><small class="text-muted">' . (int) $rRow['incidents'] . ' inc.' . ($rRow['start_fails'] > 0 ? ' · ' . (int) $rRow['start_fails'] . ' failed starts' : '') . '</small>';
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
