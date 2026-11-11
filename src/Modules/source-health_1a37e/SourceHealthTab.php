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
	public static function render(?array $rStream, string $rMode, bool $rFullPage = false): string {
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
			$rDayFrom = max($rFrom, $rNow - 86400);
			$rRows = SourceHealthCalculator::compute($rSources, $rEvents, $rFrom, $rNow, $rRunning, SourceHealthRepository::probeStats($rStreamID, $rFrom));
			// The same log read over the last day only: what the source is like now.
			$rRecent = array_column(SourceHealthCalculator::compute($rSources, $rEvents, $rDayFrom, $rNow, $rRunning, SourceHealthRepository::probeStats($rStreamID, $rDayFrom)), null, 'index');
			$rProbing = SourceHealthRepository::probeEnabled($rStreamID);
			$rAccounts = SourceHealthRepository::accountConnections();
			$rErrors = SourceHealthCalculator::errorsBySource($rSources, $rEvents, SourceHealthRepository::errors($rStreamID, $rFrom), 120, SourceHealthRepository::hosts($rStreamID, 0));
			$rProfiles = SourceHealthRepository::profiles($rStreamID);
		} catch (\Throwable $e) {
			error_log('source-health: ' . $e->getMessage());
			return self::note('The report could not be built: ' . $e->getMessage());
		}

		$rOpen = $rFullPage ? '' : '<p class="mb-2"><a href="./source_health?id=' . (int) $rStream['id'] . '" target="_blank" rel="noopener">Open this report as a full page ↗</a></p>';
		return $rOpen . self::probeSwitch((int) $rStream['id'], $rProbing) . self::table($rRows, $rRecent, $rProfiles, $rFrom, $rNow, $rAccounts) . self::timeline($rRows, $rFrom, $rNow) . self::runSection($rRows) . self::errorSection($rRows, $rErrors);
	}

	/**
	 * How long a source stays on air before it drops, from runs seen whole
	 * (started and failed inside the window). Lengths that cluster tightly
	 * around one value point at a timer — a provider session or token that
	 * expires — rather than a bad line, and a timer can be beaten by
	 * reconnecting before it runs out.
	 *
	 * @param list<array<string, mixed>> $rRows
	 */
	private static function runSection(array $rRows): string {
		$h = static fn ($v): string => htmlspecialchars((string) $v, ENT_QUOTES);
		$rBody = '';
		$rLabels = array_values(SourceHealthCalculator::RUN_BUCKETS);
		foreach ($rRows as $rRow) {
			$rStats = SourceHealthCalculator::runLengths($rRow['run_lengths'] ?? []);
			if ($rStats === null) {
				continue;
			}
			$rBack = SourceHealthCalculator::recoveryStats($rRow['recoveries'] ?? []);
			$rMoved = (int) ($rRow['moved_away'] ?? 0);
			$rShortPct = (int) round(100 * $rStats['short'] / max(1, $rStats['count']));
			$rPeak = max($rStats['buckets']) ?: 1;
			$rBody .= '<tr><td class="text-start">' . ((int) $rRow['index'] + 1) . ' <small class="text-muted">' . $h(self::mask($rRow['source'])) . '</small></td>'
				. '<td class="text-center">' . (int) $rStats['count'] . '</td>'
				. '<td class="text-center' . ($rShortPct >= 30 ? ' text-danger' : '') . '">' . (int) $rStats['short'] . ' <small class="text-muted">(' . $rShortPct . '%)</small></td>'
				. '<td class="text-center"><strong>' . ($rStats['held'] > 0 ? $h(self::duration($rStats['median'])) : '–') . '</strong></td>'
				. '<td class="text-center"><small>' . ($rStats['held'] > 0 ? $h(self::duration($rStats['p25']) . ' – ' . self::duration($rStats['p75'])) : '–') . '</small></td>'
				. '<td class="text-center"><small>' . $h(self::duration($rStats['min']) . ' / ' . self::duration($rStats['max'])) . '</small></td>';
			foreach ($rLabels as $rLabel) {
				$n = (int) $rStats['buckets'][$rLabel];
				$rBody .= '<td class="text-center' . ($n > 0 ? ($n === $rPeak ? ' bg-label-primary' : ' bg-label-secondary') : ' text-muted') . '">' . ($n > 0 ? $n : '·') . '</td>';
			}
			$rBody .= '<td class="text-center"><small>'
				. ($rBack !== null
					? '<span title="Of ' . (int) $rBack['count'] . ' drop(s) after which this source held again (' . intdiv(SourceHealthCalculator::HOLD_SEC, 60) . ' min or more); 3 of 4 within ' . $h(self::duration($rBack['p75'])) . ', longest ' . $h(self::duration($rBack['max'])) . '">'
						. '<strong>' . $h(self::duration($rBack['median'])) . '</strong> · ' . (int) $rBack['attempts'] . ' tr' . ($rBack['attempts'] === 1 ? 'y' : 'ies') . '</span>'
					: '<span class="text-muted">–</span>')
				. ($rMoved > 0 ? '<br><span class="text-muted">' . $rMoved . '× settled on another source</span>' : '')
				. '</small></td>';
			$rBody .= '<td>' . self::runPattern($rStats, $rShortPct) . '</td></tr>';
		}
		$rHtml = '<h6 class="mt-4 mb-1">How long runs last before they drop</h6>'
			. '<p class="text-muted small mb-2">Every run that started and failed inside the window, per source. Runs under a minute did not hold: '
			. 'the source dropped as soon as it was started, usually while it is coming back after a drop. The median and the spread are of the runs that held. '
			. '<em>Back after a drop</em>: once a source that was holding drops, how long until it held again (median) and in how many attempts — '
			. 'how long a failover should stay away from it before trying it again. Runs that end at about the same length again and again point to a timer on the provider\'s side (a session or token that expires).</p>';
		if ($rBody === '') {
			return $rHtml . '<p class="text-muted small mb-0">No complete runs that ended in a failure in the window.</p>';
		}
		$rHead = '<tr><th class="text-start">#</th><th>Runs</th><th>Did not hold<br><small>&lt;1m</small></th><th>Median</th><th>Middle half</th><th>Shortest / longest</th>';
		foreach ($rLabels as $rLabel) {
			$rHead .= '<th><small>' . $h($rLabel) . '</small></th>';
		}
		return $rHtml . '<div class="table-responsive"><table class="table table-sm table-bordered text-center mb-2"><thead>' . $rHead . '<th>Back after a drop</th><th>Pattern</th></tr></thead><tbody>' . $rBody . '</tbody></table></div>';
	}

	/** @param array{count:int, short:int, held:int, median:int, steady:bool} $rStats */
	private static function runPattern(array $rStats, int $rShortPct): string {
		if ($rStats['steady']) {
			return '<span class="badge bg-label-warning" title="Half of the runs that held end within a narrow band around the same length: typical of a provider session or token that expires, not of a bad line.">steady ~'
				. htmlspecialchars(self::duration($rStats['median']), ENT_QUOTES) . ' — likely a session/token limit</span>';
		}
		if ($rStats['held'] >= 3 && $rShortPct >= 30 && $rStats['median'] >= 2 * SourceHealthCalculator::INCIDENT_GAP_SEC) {
			return '<span class="badge bg-label-info" title="Once up it lasts; the trouble is coming back after a drop. Give it time before retrying, and use a backup meanwhile.">holds once up — slow to come back</span>';
		}
		if ($rStats['count'] < 5) {
			return '<small class="text-muted">too few runs to tell</small>';
		}
		return '<small class="text-muted">' . ($rStats['median'] < 2 * SourceHealthCalculator::INCIDENT_GAP_SEC ? 'short runs: drops in bad spells' : 'spread out') . '</small>';
	}

	/**
	 * The encoder's own words, per source: the commonest error lines from the
	 * panel's Stream Error Logs, each put with the source in use at the time.
	 * This is what says WHY a source drops or does not start.
	 *
	 * @param list<array<string, mixed>> $rRows
	 * @param array{by_source: array<int, list<array{0:string, 1:int, 2:int}>>, unattributed: int} $rErrors
	 */
	private static function errorSection(array $rRows, array $rErrors): string {
		$h = static fn ($v): string => htmlspecialchars((string) $v, ENT_QUOTES);
		$rHtml = '<h6 class="mt-4 mb-1">What the encoder reported</h6>'
			. '<p class="text-muted small mb-2">The commonest error lines from Stream Error Logs in the report window. A line that names a source\'s host goes with that source; '
			. 'one that names none goes with the source in use or being started when it was written. Launch banners and stream descriptions are left out (launches are counted from them); '
			. 'numbers are replaced by #, so repeats group together.'
			. (($rErrors['by_name'] ?? 0) + ($rErrors['by_time'] ?? 0) > 0 ? ' Placed by host: ' . (int) $rErrors['by_name'] . ' · by time: ' . (int) $rErrors['by_time'] . '.' : '')
			. '</p>';
		if ($rErrors['by_source'] === []) {
			return $rHtml . '<p class="text-muted small mb-0">No error lines (beyond launch banners) for this stream in the window' . ($rErrors['unattributed'] > 0 ? ' that could be put with a source (' . (int) $rErrors['unattributed'] . ' not placed)' : '') . '.</p>';
		}
		$rHtml .= '<div class="table-responsive"><table class="table table-sm mb-2"><thead><tr><th>#</th><th>Message</th><th class="text-center">Times</th><th>Last</th></tr></thead><tbody>';
		foreach ($rRows as $rRow) {
			$rGone = null;
			foreach ($rErrors['by_source'][$rRow['index']] ?? [] as [$rMessage, , $rLast]) {
				// The provider says the source is gone, and it has not run since.
				if ($rGone === null && SourceHealthCalculator::reasonKind($rMessage) === 'gone' && $rLast >= (int) $rRow['last_active']) {
					$rGone = $rMessage;
				}
			}
			foreach ($rErrors['by_source'][$rRow['index']] ?? [] as $n => [$rMessage, $rCount, $rLast, $rSure]) {
				$rLaunches = (int) ($rErrors['launches'][$rRow['index']] ?? 0);
				$rKind = SourceHealthCalculator::reasonKind($rMessage);
				$rHtml .= '<tr>'
					. ($n === 0 ? '<td rowspan="' . count($rErrors['by_source'][$rRow['index']]) . '">' . ((int) $rRow['index'] + 1) . '<br><small class="text-muted">' . $h(self::mask($rRow['source']))
						. ($rLaunches > 0 ? '<br>' . $rLaunches . ' launch(es) logged' : '') . '</small>'
						. ($rGone !== null ? '<br><span class="badge bg-label-danger text-wrap text-start mt-1" title="The provider\'s own answer says this will not come back by itself, and the source has not run since. Replace or remove it.">provider says it is gone — replace or remove</span>' : '')
						. '</td>' : '')
					. '<td><code class="text-wrap">' . $h($rMessage) . '</code>'
					. ($rKind === 'gone' ? ' <span class="badge bg-label-danger" title="Says the channel, account or host is gone: retrying will not help">permanent</span>' : '')
					. ($rKind === 'temporary' ? ' <span class="badge bg-label-secondary" title="A passing failure: the same source can work again">temporary</span>' : '')
					. ' <small class="text-muted" title="' . ($rSure ? 'Every line names this source\'s host' : 'Some or all of these lines name no host: placed by time') . '">' . ($rSure ? '· by host' : '· by time') . '</small></td><td class="text-center">' . (int) $rCount . '</td><td><small>' . $h(date('m-d H:i', $rLast)) . '</small></td></tr>';
			}
		}
		$rHtml .= '</tbody></table></div>';
		if ($rErrors['unattributed'] > 0) {
			$rHtml .= '<p class="text-muted small mb-0">' . (int) $rErrors['unattributed'] . ' line(s) could not be put with a source (no source event within two minutes on that server).</p>';
		}
		return $rHtml;
	}

	/** The opt-in for active probes of this stream's sources, saved at once (not with the form). */
	private static function probeSwitch(int $rStreamID, bool $rOn): string {
		return '<div class="form-check form-switch mb-3">'
			. '<input class="form-check-input" type="checkbox" id="sh-probe" ' . ($rOn ? 'checked' : '') . ' data-stream="' . $rStreamID . '">'
			. '<label class="form-check-label" for="sh-probe"><strong>Probe every source</strong> — test each source on its own every '
			. intdiv(SourceProber::INTERVAL_SEC, 60) . ' minutes from the main server, so backups are measured as well as the source on air '
			. '(one short connection reading ' . SourceProber::PROBE_READ_SEC . ' s; never the source on air, nor one whose provider account a live stream is using). '
			. '<span class="text-muted" id="sh-probe-state">' . ($rOn ? 'On.' : 'Off.') . '</span></label></div>'
			. '<script>(function(){var c=document.getElementById("sh-probe");if(!c||c.dataset.bound){return;}c.dataset.bound=1;'
			. 'c.addEventListener("change",function(){var s=document.getElementById("sh-probe-state");s.textContent="Saving…";'
			. 'jQuery.post("./api?action=source_health_probe",{stream_id:c.dataset.stream,enabled:c.checked?1:0},function(r){'
			. 's.textContent=(r&&r.result)?(c.checked?"On: first results within 5–10 minutes.":"Off."):"Could not save.";},"json")'
			. '.fail(function(){s.textContent="Could not save.";});});})();</script>';
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
	private static function table(array $rRows, array $rRecent, array $rProfiles, int $rFrom, int $rNow, array $rAccounts = []): string {
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
			. '<th>#</th><th>Source <small class="text-muted">/ output profile</small></th><th class="text-center">Score<br><small>7 days</small></th>'
			. '<th class="text-center" title="The same figures over the last 24 hours only">Last 24h</th>'
			. '<th class="text-center" title="Active probes in the report window: successful / made, median time to open, steady read speed">Probes</th>'
			. '<th class="text-center">Starts<br><small>ok / failed</small></th>'
			. '<th class="text-center" title="Failures less than ' . intdiv(SourceHealthCalculator::INCIDENT_GAP_SEC, 60) . ' minutes apart count as one incident (one outage)">Drops<br><small>incidents</small></th>'
			. '<th class="text-center" title="MTBF: mean time on air between incidents">On air<br><small>MTBF</small></th>'
			. '<th>Last failure</th></tr></thead><tbody>';

		foreach ($rRows as $rRow) {
			$rProfile = $rProfiles[md5($rRow['source'])] ?? null;
			$rGroup = $rProfile !== null ? ($rGroups[(string) $rProfile['fingerprint']] ?? '') : '';
			$rMtbf = $rRow['incidents'] > 0 ? self::duration(intdiv((int) $rRow['uptime_sec'], (int) $rRow['incidents'])) : ($rRow['uptime_sec'] > 0 ? '&gt; ' . self::duration((int) $rRow['uptime_sec']) : '–');
			$rProfileText = $rProfile !== null
				? '<span class="badge bg-label-secondary">' . $h($rGroup) . '</span> ' . $h(SourceHealthRepository::raw((string) $rProfile['label']))
					. ((int) ($rProfile['bitrate'] ?? 0) > 0 ? ' · ' . round(((int) $rProfile['bitrate']) / 1000, 1) . ' Mbps' : '')
				: (($rRow['probe']['label'] ?? '') !== '' ? $h($rRow['probe']['label']) . ' (from probes)' : 'not seen on air yet');
			$rHtml .= '<tr>'
				. '<td>' . ((int) $rRow['index'] + 1) . ($rRow['running'] ? '<br><span class="badge bg-label-success" title="On air now">live</span>' : '') . '</td>'
				. '<td><code title="' . $h(self::mask($rRow['source'])) . '">' . $h(self::mask($rRow['source'])) . '</code><br><small class="text-muted">' . $rProfileText . '</small>'
					. self::accountNote($rRow, $rAccounts) . '</td>'
				. '<td class="text-center">' . self::scoreBadge($rRow['score'], (string) $rRow['confidence']) . '</td>'
				. '<td class="text-center">' . self::recent($rRecent[$rRow['index']] ?? null) . '</td>'
				. '<td class="text-center">' . self::probes($rRow['probe'] ?? null) . '</td>'
				. '<td class="text-center">' . (int) $rRow['starts'] . ' / ' . (int) $rRow['start_fails'] . '</td>'
				. '<td class="text-center">' . (int) $rRow['failures'] . '<br><small class="text-muted">' . (int) $rRow['incidents'] . ' inc.'
					. ((int) $rRow['quality_issues'] > 0 ? ' · ' . (int) $rRow['quality_issues'] . ' quality' : '') . '</small></td>'
				. '<td class="text-center">' . ($rRow['uptime_sec'] > 0 ? self::duration((int) $rRow['uptime_sec']) : '–') . '<br><small class="text-muted">' . $rMtbf . '</small></td>'
				. '<td><small>' . ($rRow['last_failure'] > 0 ? $h(date('Y-m-d H:i', (int) $rRow['last_failure'])) : '–') . '</small></td>'
				. '</tr>';
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
			. 'Probes count as start attempts. The suggested order discounts scores that rest on little data (low confidence, or under an hour on air), so an untried source does not overtake a measured one. '
			. 'Low confidence means little data. Profiles are recorded every 5 minutes while a source is on air.</p>';
		return $rHtml;
	}

	/**
	 * How many live connections the panel holds right now on this source's
	 * provider account (same host and /user/pass/), besides this source itself.
	 * Providers cap connections per account; past the cap they cut the oldest
	 * ("source ended") and refuse new ones (404, an empty body, a web page).
	 */
	private static function accountNote(array $rRow, array $rAccounts): string {
		$rKey = SourceProber::accountKey((string) $rRow['source']);
		$rOthers = $rKey === null ? 0 : (int) ($rAccounts[$rKey] ?? 0) - ($rRow['running'] ? 1 : 0);
		if ($rOthers <= 0) {
			return '';
		}
		return '<br><small class="text-warning" title="Live connections the panel holds right now on the same provider account (host + user/pass), other than this source">account also used by ' . $rOthers . ' other live connection(s)</small>';
	}

	/** Last-24h cell: score and incident count, or "–" when the source was not tried. */
	private static function recent(?array $rRow): string {
		if ($rRow === null || $rRow['score'] === null) {
			return '<span class="text-muted">–</span>';
		}
		return self::scoreBadge($rRow['score'], (string) $rRow['confidence'])
			. '<br><small class="text-muted">on air ' . ($rRow['uptime_sec'] > 0 ? self::duration((int) $rRow['uptime_sec']) : '0') . ' · ' . (int) $rRow['incidents'] . ' inc.'
			. ($rRow['start_fails'] > 0 ? ' · ' . (int) $rRow['start_fails'] . ' failed starts' : '')
			. ($rRow['probe_total'] > 0 ? ' · probes ' . (int) $rRow['probe_ok'] . '/' . (int) $rRow['probe_total'] : '') . '</small>';
	}

	/** Probes cell: "46/48 ok", median open time and read speed; the last failure or skip on hover. */
	private static function probes(?array $rProbe): string {
		if ($rProbe === null) {
			return '<span class="text-muted">–</span>';
		}
		$h = static fn ($v): string => htmlspecialchars((string) $v, ENT_QUOTES);
		$rLast = $rProbe['last_error'] !== '' ? ($rProbe['last_kind'] === 'skip' ? 'last: not probed, ' : 'last: ') . $rProbe['last_error'] : 'last probe ok';
		$rSkipped = ($rProbe['skipped'] ?? 0) > 0
			? '<br><small class="text-muted" title="' . $h($rProbe['skip_reason']) . '">' . (int) $rProbe['skipped'] . ' skipped: ' . $h($rProbe['skip_reason']) . '</small>'
			: '';
		if ($rProbe['total'] === 0) {
			return '<span class="text-muted" title="' . $h($rLast) . '">not probed</span>' . $rSkipped;
		}
		$rRate = $rProbe['ok'] / $rProbe['total'];
		$rClass = $rRate >= 0.95 ? 'text-success' : ($rRate >= 0.7 ? 'text-warning' : 'text-danger');
		return '<span class="' . $rClass . '" title="' . $h($rLast) . '">' . (int) $rProbe['ok'] . '/' . (int) $rProbe['total'] . ' ok</span>'
			. ($rProbe['open_ms'] !== null ? '<br><small class="text-muted">open ' . round($rProbe['open_ms'] / 1000, 1) . ' s' . ($rProbe['speed'] !== null ? ' · ' . ($rProbe['speed'] >= 10 ? '≥10' : $rProbe['speed']) . 'x' : '') . '</small>' : '')
			. ($rProbe['last_kind'] === 'probe' && !$rProbe['last_ok'] ? '<br><small class="text-danger">' . $h($rProbe['last_error']) . '</small>' : '')
			. $rSkipped;
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
