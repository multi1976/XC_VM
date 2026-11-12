<?php

namespace XcVm\Module\LineAudit;

/**
 * The Line Audit page: one tab per kind of finding, a reseller filter and a
 * username search on all of them, over the last 24 hours or 7 days. Read-only.
 */
final class LineAuditPage {
	/** Days without use that make a line unused. */
	public const UNUSED_DAYS = 30;

	private const MAX_ROWS = 500;
	private const TABS = [
		'overview' => 'Overview', 'sharing' => 'Shared accounts', 'limits' => 'Connection limits', 'refused' => 'Refused',
		'isp' => 'ISP changes', 'expiry' => 'Expiring', 'unused' => 'Unused', 'unstable' => 'Unstable clients',
	];

	/** @param array<string, mixed> $rQuery GET parameters: view, days, reseller, q */
	public static function render(array $rQuery, int $rNow): string {
		$h = static fn ($v): string => htmlspecialchars((string) $v, ENT_QUOTES);
		$rView = isset(self::TABS[$rQuery['view'] ?? '']) ? (string) $rQuery['view'] : 'overview';
		$rDays = (int) ($rQuery['days'] ?? 1) === 7 ? 7 : 1;
		$rCanDelete = (bool) ($rQuery['can_delete'] ?? false);
		$rReseller = (int) ($rQuery['reseller'] ?? 0);
		$rSearch = mb_substr(trim((string) ($rQuery['q'] ?? '')), 0, 64);
		$rFrom = $rNow - $rDays * 86400;

		$rLines = LineAuditRepository::lines();
		$rLive = [];
		foreach (LineAuditRepository::liveConnections() as $c) {
			$rLive[$c['user_id']] = ($rLive[$c['user_id']] ?? 0) + 1;
		}
		$rHistory = LineAuditRepository::history($rFrom);
		$rSamples = LineAuditRepository::sampleStats($rFrom);
		$rRefusals = LineAuditRepository::refusals($rFrom);
		$rPlayers = LineAuditRepository::players($rFrom);
		$rHandovers = LineAuditRepository::handovers($rFrom);
		$rIspHistory = LineAuditRepository::ispHistory($rFrom);
		$rOwners = LineAuditRepository::owners(array_column($rLines, 'member_id'));
		$rKeep = static fn (array $l): bool => ($rReseller === 0 || (int) $l['member_id'] === $rReseller)
			&& ($rSearch === '' || mb_stripos((string) $l['username'], $rSearch) !== false);
		$rScope = array_filter($rLines, $rKeep);

		// Findings, per tab.
		$rSharing = [];
		foreach ($rScope as $rID => $l) {
			$s = LineAuditAnalyzer::sharing($rSamples[$rID] ?? null, $rHistory[$rID] ?? null, $rPlayers[$rID] ?? [], $rHandovers[$rID] ?? null);
			if ($s['level'] !== 'none') {
				$rSharing[$rID] = $s;
			}
		}
		uasort($rSharing, static fn (array $a, array $b): int => $b['score'] <=> $a['score']);
		$rLimits = [];
		foreach ($rScope as $rID => $l) {
			$rMax = (int) $l['max_connections'];
			$rNowConns = $rLive[$rID] ?? 0;
			$rAt = $rSamples[$rID]['at_limit'] ?? 0;
			if ($rMax > 0 && ($rNowConns >= $rMax || $rAt > 0)) {
				$rLimits[$rID] = ['now' => $rNowConns, 'at' => $rAt];
			}
		}
		uasort($rLimits, static fn (array $a, array $b): int => [$b['at'], $b['now']] <=> [$a['at'], $a['now']]);
		$rRefused = array_intersect_key($rRefusals, $rScope);
		uasort($rRefused, static fn (array $a, array $b): int => array_sum($b) <=> array_sum($a));
		$rExpiring = array_filter($rScope, static fn (array $l): bool => (int) $l['exp_date'] > $rNow && (int) $l['exp_date'] <= $rNow + 7 * 86400 && self::enabled($l));
		uasort($rExpiring, static fn (array $a, array $b): int => (int) $a['exp_date'] <=> (int) $b['exp_date']);
		$rExpiredTrying = array_filter($rScope, static fn (array $l): bool => (int) $l['exp_date'] > 0 && (int) $l['exp_date'] <= $rNow && isset($rRefusals[(int) $l['id']]['USER_EXPIRED']));
		$rUnused = array_filter($rScope, static fn (array $l): bool => self::unused($l, $rNow));
		uasort($rUnused, static fn (array $a, array $b): int => (int) $a['last_activity'] <=> (int) $b['last_activity']);
		$rUnstable = array_filter($rScope, static fn (array $l): bool => LineAuditAnalyzer::unstable($rHistory[(int) $l['id']] ?? null));
		uasort($rUnstable, static fn (array $a, array $b): int => ($rHistory[(int) $b['id']]['short'] ?? 0) <=> ($rHistory[(int) $a['id']]['short'] ?? 0));

		$rIspChanges = [];
		foreach (array_intersect_key($rIspHistory, $rScope) as $rID => $rIsps) {
			$c = LineAuditAnalyzer::ispChange($rIsps, $rFrom);
			if ($c !== null) {
				$rIspChanges[$rID] = $c;
			}
		}
		uasort($rIspChanges, static fn (array $a, array $b): int => [$a['kind'] === 'added' ? 0 : 1, -$a['since']] <=> [$b['kind'] === 'added' ? 0 : 1, -$b['since']]);

		$rCounts = [
			'sharing' => count($rSharing), 'limits' => count($rLimits), 'refused' => count($rRefused), 'isp' => count($rIspChanges),
			'expiry' => count($rExpiring) + count($rExpiredTrying), 'unused' => count($rUnused), 'unstable' => count($rUnstable),
		];
		// Header: tabs, window, filters.
		$rHtml = '<ul class="nav nav-tabs mb-3">';
		foreach (self::TABS as $rKey => $rLabel) {
			$rHtml .= '<li class="nav-item"><a class="nav-link' . ($rView === $rKey ? ' active' : '') . '" href="' . $h('./line_audit?' . http_build_query(array_filter(['view' => $rKey, 'days' => $rDays === 7 ? 7 : null, 'reseller' => $rReseller ?: null, 'q' => $rSearch !== '' ? $rSearch : null], static fn ($v): bool => $v !== null))) . '">'
				. $h($rLabel) . (isset($rCounts[$rKey]) ? ' <span class="badge rounded-pill ' . ($rCounts[$rKey] > 0 ? 'bg-label-danger' : 'bg-label-secondary') . '">' . $rCounts[$rKey] . '</span>' : '') . '</a></li>';
		}
		$rHtml .= '</ul>';
		$rResellers = array_intersect_key($rOwners, array_flip(array_map('intval', array_column($rLines, 'member_id'))));
		asort($rResellers);
		$rHtml .= '<form method="get" action="./line_audit" class="d-flex flex-wrap gap-2 mb-3 align-items-center"><input type="hidden" name="view" value="' . $h($rView) . '">'
			. '<select name="days" class="form-select form-select-sm" style="width:auto"><option value="1"' . ($rDays === 1 ? ' selected' : '') . '>Last 24 hours</option><option value="7"' . ($rDays === 7 ? ' selected' : '') . '>Last 7 days</option></select>'
			. '<select name="reseller" class="form-select form-select-sm" style="width:auto"><option value="0">All owners</option>';
		foreach ($rResellers as $rID => $rName) {
			$rHtml .= '<option value="' . (int) $rID . '"' . ($rReseller === (int) $rID ? ' selected' : '') . '>' . $h($rName) . '</option>';
		}
		$rHtml .= '</select><input type="search" name="q" value="' . $h($rSearch) . '" class="form-control form-control-sm" style="width:220px" placeholder="Username">'
			. '<button class="btn btn-sm btn-primary" type="submit">Apply</button>'
			. '<small class="text-muted ms-2">' . count($rScope) . ' lines · ' . array_sum(array_intersect_key($rLive, $rScope)) . ' connections now</small></form>';

		$rCtx = ['can_delete' => $rCanDelete, 'handovers' => $rHandovers, 'players' => $rPlayers, 'lines' => $rLines, 'live' => $rLive, 'history' => $rHistory, 'samples' => $rSamples, 'refusals' => $rRefusals, 'owners' => $rOwners, 'now' => $rNow, 'days' => $rDays];
		return $rHtml . match ($rView) {
			'sharing'  => self::sharingTab($rSharing, $rCtx),
			'limits'   => self::limitsTab($rLimits, $rCtx),
			'refused'  => self::refusedTab($rRefused, $rCtx),
			'isp'      => self::ispTab($rIspChanges, $rCtx),
			'expiry'   => self::expiryTab($rExpiring, $rExpiredTrying, $rCtx),
			'unused'   => self::unusedTab($rUnused, $rCtx),
			'unstable' => self::unstableTab($rUnstable, $rCtx),
			default    => self::overview($rCounts, $rSharing, $rRefusals, $rScope, $rCtx),
		};
	}

	/** Enabled, not expired, created over UNUSED_DAYS ago and not used for as long (or never). */
	public static function unused(array $l, int $rNow): bool {
		$rCut = $rNow - self::UNUSED_DAYS * 86400;
		return self::enabled($l) && ((int) $l['exp_date'] === 0 || (int) $l['exp_date'] > $rNow) && (int) $l['last_activity'] < $rCut && (int) $l['created_at'] < $rCut;
	}

	/** A MAG or Enigma2 line is deleted with its device (MAG / Enigma2 devices), not here. */
	public static function deletable(array $l): bool {
		return (int) ($l['is_mag'] ?? 0) === 0 && (int) ($l['is_e2'] ?? 0) === 0;
	}

	private static function enabled(array $l): bool {
		return (int) $l['enabled'] === 1 && (int) $l['admin_enabled'] === 1;
	}

	/** The first cells of every row: the line, its owner, its kind, its limit and connections now. */
	private static function lineCells(array $l, array $rCtx): string {
		$h = static fn ($v): string => htmlspecialchars((string) $v, ENT_QUOTES);
		$rID = (int) $l['id'];
		$rFlags = '';
		foreach (['is_trial' => 'trial', 'is_restreamer' => 'restreamer', 'is_mag' => 'MAG', 'is_e2' => 'E2'] as $k => $rLabel) {
			$rFlags .= (int) ($l[$k] ?? 0) === 1 ? ' <span class="badge bg-label-secondary">' . $rLabel . '</span>' : '';
		}
		if (!self::enabled($l)) {
			$rFlags .= ' <span class="badge bg-label-dark">disabled</span>';
		}
		return '<td><a href="./line?id=' . $rID . '" target="_blank" rel="noopener">' . $h($l['username']) . '</a>' . $rFlags . '</td>'
			. '<td><small>' . $h($rCtx['owners'][(int) $l['member_id']] ?? '–') . '</small></td>'
			. '<td class="text-center">' . ((int) ($rCtx['live'][$rID] ?? 0)) . '<small class="text-muted">/' . ((int) $l['max_connections'] ?: '∞') . '</small></td>';
	}

	private static function head(array $rMore): string {
		return '<div class="table-responsive"><table class="table table-sm align-middle"><thead><tr><th>Line</th><th>Owner</th><th class="text-center" title="Connections now / allowed">Now</th>'
			. implode('', array_map(static fn (string $c): string => '<th>' . $c . '</th>', $rMore)) . '</tr></thead><tbody>';
	}

	private static function tail(int $rTotal): string {
		return '</tbody></table></div>' . ($rTotal > self::MAX_ROWS ? '<p class="text-muted small">Showing the first ' . self::MAX_ROWS . ' of ' . $rTotal . '; filter by owner or username.</p>' : '');
	}

	private static function window(array $rCtx): string {
		return $rCtx['days'] === 7 ? 'the last 7 days' : 'the last 24 hours';
	}

	private static function overview(array $rCounts, array $rSharing, array $rRefusals, array $rScope, array $rCtx): string {
		$h = static fn ($v): string => htmlspecialchars((string) $v, ENT_QUOTES);
		$rLikely = count(array_filter($rSharing, static fn (array $s): bool => $s['level'] === 'likely'));
		$rCards = [
			['sharing', 'Shared accounts', $rLikely . ' likely · ' . ($rCounts['sharing'] - $rLikely) . ' to look at', 'Used from several countries or IPs at once, or from many in ' . self::window($rCtx) . '.'],
			['limits', 'At their limit', (string) $rCounts['limits'], 'Lines that use every connection they are allowed, now or in ' . self::window($rCtx) . ': more viewers than the line allows, or a limit set too low.'],
			['refused', 'Refused', (string) $rCounts['refused'], 'Lines with refused attempts: expired, banned, wrong IP or country, not in bouquet…'],
			['isp', 'ISP changes', (string) $rCounts['isp'], 'Lines that started coming from an ISP they never used before, in ' . self::window($rCtx) . ': a move, or a line passed on.'],
			['expiry', 'Expiring', (string) $rCounts['expiry'], 'Expiring in the next 7 days, or expired and still trying to connect.'],
			['unused', 'Unused', (string) $rCounts['unused'], 'Enabled, not expired, and not used for 30 days or more.'],
			['unstable', 'Unstable clients', (string) $rCounts['unstable'], 'Many sessions under a minute: a bad connection, a player that reconnects in a loop, or a failing stream.'],
		];
		$rHtml = '<div class="row g-3 mb-3">';
		foreach ($rCards as [$rKey, $rTitle, $rValue, $rText]) {
			$rHtml .= '<div class="col-md-4"><a href="./line_audit?view=' . $rKey . ($rCtx['days'] === 7 ? '&days=7' : '') . '" class="card h-100 text-body text-decoration-none"><div class="card-body">'
				. '<h6 class="mb-1">' . $h($rTitle) . '</h6><div class="fs-4 fw-semibold">' . $h($rValue) . '</div><small class="text-muted">' . $h($rText) . '</small></div></a></div>';
		}
		$rHtml .= '</div>';
		// Refusals by reason, panel-wide.
		$rByStatus = [];
		foreach (array_intersect_key($rRefusals, $rScope) as $rStatuses) {
			foreach ($rStatuses as $rStatus => $n) {
				$rByStatus[$rStatus] = ($rByStatus[$rStatus] ?? 0) + $n;
			}
		}
		arsort($rByStatus);
		$rHtml .= '<h6 class="mb-1">Refused attempts in ' . self::window($rCtx) . ', by reason</h6>';
		if ($rByStatus === []) {
			return $rHtml . '<p class="text-muted small mb-0">None recorded. If you expected some, check that client logs are saved (Settings → <em>client_logs_save</em>).</p>';
		}
		$rHtml .= '<div class="d-flex flex-wrap gap-2">';
		foreach ($rByStatus as $rStatus => $n) {
			$rHtml .= '<span class="badge bg-label-' . (in_array($rStatus, LineAuditAnalyzer::LINE_REFUSALS, true) ? 'warning' : 'secondary') . ' p-2">' . $h($rStatus) . ': ' . $n . '</span>';
		}
		return $rHtml . '</div>';
	}

	private static function sharingTab(array $rSharing, array $rCtx): string {
		$h = static fn ($v): string => htmlspecialchars((string) $v, ENT_QUOTES);
		$rHtml = '<p class="text-muted small">Lines used from several places. <strong>At the same time</strong> comes from the live connections, looked at every five minutes '
			. '(two countries at once cannot be one household); the rest from the sessions of ' . self::window($rCtx) . '. '
			. 'On a line with one or two connections a shared account is rarely seen at once: the panel closes the older connection when a new one opens, '
			. 'so the places take it from each other, back and forth. <strong>Handovers</strong> counts those (a return is A, B, then A again). A restreamer is expected to look like this. '
			. 'A phone on mobile data and a TV at home are two IPs and two ISPs: one sign alone is a note, not a verdict.</p>';
		if ($rSharing === []) {
			return $rHtml . '<p class="text-muted mb-0">Nothing found.</p>';
		}
		$rHtml .= self::head(['Verdict', 'Why', 'IPs', 'Handovers', 'Countries', 'ISPs (widest moment)', 'Players', 'Sessions']);
		foreach (array_slice($rSharing, 0, self::MAX_ROWS, true) as $rID => $s) {
			$l = $rCtx['lines'][$rID];
			$rHist = $rCtx['history'][$rID] ?? null;
			$rSam = $rCtx['samples'][$rID] ?? null;
			[$rLabel, $rClass, $rRow] = match ($s['level']) {
				'likely'     => ['Likely shared', 'bg-label-danger', 'table-danger'],
				'suspicious' => ['Suspicious', 'bg-label-warning', 'table-warning'],
				default      => ['Note', 'bg-label-info', ''],
			};
			if ((int) $l['is_restreamer'] === 1) {
				[$rLabel, $rClass, $rRow] = ['Restreamer', 'bg-label-secondary', ''];
			}
			$rHtml .= '<tr class="' . $rRow . '">' . self::lineCells($l, $rCtx)
				. '<td><span class="badge ' . $rClass . '">' . $rLabel . '</span></td>'
				. '<td><small>' . $h(implode('; ', $s['reasons'])) . '</small></td>'
				. '<td class="text-center">' . (int) ($rHist['ips'] ?? 0) . '</td>'
				. '<td class="text-center">' . self::handoverCell($rCtx['handovers'][$rID] ?? null) . '</td>'
				. '<td><small>' . $h(trim(($rSam['country_list'] ?? '') !== '' ? $rSam['country_list'] : ($rHist['country_list'] ?? ''), ',')) . '</small></td>'
				. '<td><small class="text-muted">' . $h(mb_strimwidth((string) ($rSam['isp_list'] ?? ''), 0, 90, '…')) . '</small></td>'
				. '<td><small>' . self::players($rCtx['players'][$rID] ?? [], (string) ($rSam['ua_list'] ?? '')) . '</small></td>'
				. '<td class="text-center">' . (int) ($rHist['sessions'] ?? 0) . '</td></tr>';
		}
		return $rHtml . self::tail(count($rSharing));
	}

	private static function limitsTab(array $rLimits, array $rCtx): string {
		$rHtml = '<p class="text-muted small">Lines using every connection they are allowed: right now, or for a while in ' . self::window($rCtx) . ' (seen every five minutes). '
			. 'A line often at its limit has more viewers than it allows — a family that needs more, or an account passed around; the refused connections are in the Refused tab.</p>';
		if ($rLimits === []) {
			return $rHtml . '<p class="text-muted mb-0">Nothing found.</p>';
		}
		$rHtml .= self::head(['At the limit', 'Refused (all reasons)']);
		foreach (array_slice($rLimits, 0, self::MAX_ROWS, true) as $rID => $x) {
			$l = $rCtx['lines'][$rID];
			$rHtml .= '<tr class="' . ($x['now'] >= (int) $l['max_connections'] ? 'table-warning' : '') . '">' . self::lineCells($l, $rCtx)
				. '<td>' . ($x['at'] > 0 ? '~' . LineAuditAnalyzer::duration($x['at'] * LineAuditAnalyzer::SAMPLE_SEC) : '<span class="text-muted">now only</span>') . '</td>'
				. '<td class="text-center">' . array_sum($rCtx['refusals'][$rID] ?? []) . '</td></tr>';
		}
		return $rHtml . self::tail(count($rLimits));
	}

	private static function refusedTab(array $rRefused, array $rCtx): string {
		$h = static fn ($v): string => htmlspecialchars((string) $v, ENT_QUOTES);
		$rHtml = '<p class="text-muted small">Attempts the panel refused in ' . self::window($rCtx) . ', from its client log, per line and reason. '
			. 'Repeated refusals of one kind say what to fix: an expired line that is still used, a wrong country or ISP lock, a player that sends no user agent… '
			. '<em>What it asked for</em> names the channels or films: a line refused NOT_IN_BOUQUET thousands of times on one channel has a player retrying a channel its package lacks.</p>';
		if ($rRefused === []) {
			return $rHtml . '<p class="text-muted mb-0">Nothing found. If you expected some, check that client logs are saved (Settings → <em>client_logs_save</em>).</p>';
		}
		$rHtml .= self::head(['Refusals', 'By reason', 'What it asked for']);
		$rAsked = LineAuditRepository::refusalStreams($rCtx['now'] - $rCtx['days'] * 86400, array_slice(array_keys($rRefused), 0, self::MAX_ROWS));
		foreach (array_slice($rRefused, 0, self::MAX_ROWS, true) as $rID => $rStatuses) {
			if (!isset($rCtx['lines'][$rID])) {
				continue;
			}
			arsort($rStatuses);
			$rBadges = '';
			foreach ($rStatuses as $rStatus => $n) {
				$rBadges .= '<span class="badge bg-label-' . (in_array($rStatus, LineAuditAnalyzer::LINE_REFUSALS, true) ? 'warning' : 'secondary') . ' me-1">' . $h($rStatus) . ' ' . $n . '</span>';
			}
			$rWhat = [];
			foreach (array_slice($rAsked[$rID] ?? [], 0, 3) as $a) {
				$rName = $a['stream_id'] === 0 ? '(no stream)' : ($a['name'] !== '' ? $a['name'] : '#' . $a['stream_id'] . ' (deleted)');
				$rPage = match ($a['type']) {
					1, 3, 4 => './stream?id=',
					2       => './movie?id=',
					default => '',
				};
				$rLabel = $h($rName) . ' <span class="text-muted">×' . $a['n'] . (count($rStatuses) > 1 ? ' ' . $h($a['status']) : '') . '</span>';
				$rWhat[] = $rPage !== '' && $a['name'] !== '' ? '<a href="' . $rPage . $a['stream_id'] . '" target="_blank" rel="noopener">' . $rLabel . '</a>' : $rLabel;
			}
			$rMore = count($rAsked[$rID] ?? []) > 3 ? ' <span class="text-muted">+' . (count($rAsked[$rID]) - 3) . ' more</span>' : '';
			$rHtml .= '<tr>' . self::lineCells($rCtx['lines'][$rID], $rCtx) . '<td class="text-center">' . array_sum($rStatuses) . '</td><td>' . $rBadges . '</td>'
				. '<td><small>' . implode('<br>', $rWhat) . $rMore . '</small></td></tr>';
		}
		return $rHtml . self::tail(count($rRefused));
	}

	private static function expiryTab(array $rExpiring, array $rExpiredTrying, array $rCtx): string {
		$h = static fn ($v): string => htmlspecialchars((string) $v, ENT_QUOTES);
		$rHtml = '<h6 class="mb-1">Expiring in the next 7 days</h6><p class="text-muted small">Enabled lines, soonest first, with when they were last used: a line in use is a customer to remind.</p>';
		if ($rExpiring === []) {
			$rHtml .= '<p class="text-muted">None.</p>';
		} else {
			$rHtml .= self::head(['Expires', 'Last used']);
			foreach (array_slice($rExpiring, 0, self::MAX_ROWS, true) as $l) {
				$rHtml .= '<tr>' . self::lineCells($l, $rCtx) . '<td>' . $h(date('Y-m-d H:i', (int) $l['exp_date'])) . ' <small class="text-muted">in ' . LineAuditAnalyzer::duration((int) $l['exp_date'] - $rCtx['now']) . '</small></td>'
					. '<td><small>' . self::ago((int) $l['last_activity'], $rCtx['now']) . '</small></td></tr>';
			}
			$rHtml .= self::tail(count($rExpiring));
		}
		$rHtml .= '<h6 class="mb-1 mt-4">Expired, still trying to connect</h6><p class="text-muted small">Refused as expired in ' . self::window($rCtx) . ': customers still using a player set up with this line.</p>';
		if ($rExpiredTrying === []) {
			return $rHtml . '<p class="text-muted mb-0">None.</p>';
		}
		$rHtml .= self::head(['Expired', 'Attempts']);
		foreach (array_slice($rExpiredTrying, 0, self::MAX_ROWS, true) as $l) {
			$rHtml .= '<tr>' . self::lineCells($l, $rCtx) . '<td>' . $h(date('Y-m-d', (int) $l['exp_date'])) . '</td><td class="text-center">' . (int) $rCtx['refusals'][(int) $l['id']]['USER_EXPIRED'] . '</td></tr>';
		}
		return $rHtml . self::tail(count($rExpiredTrying));
	}

	private static function ispTab(array $rChanges, array $rCtx): string {
		$h = static fn ($v): string => htmlspecialchars((string) $v, ENT_QUOTES);
		$rHtml = '<p class="text-muted small">Lines that started coming from an ISP they had never used before, in ' . self::window($rCtx) . ', while they had used another one before. '
			. '<strong>Added</strong>: the earlier ISP is still in use too, so a second place. <strong>Moved</strong>: the earlier one is no longer used, a customer who changed provider or moved house, or a line passed on to someone else. '
			. 'The panel keeps only a line\'s current ISP; this module remembers every ISP a line was used from since it was installed (and from the activity log before that). '
			. 'To hold a line to one ISP, the panel has ISP Lock (Edit Line).</p>';
		if ($rChanges === []) {
			return $rHtml . '<p class="text-muted mb-0">Nothing found.</p>';
		}
		$rHtml .= self::head(['Change', 'New ISP', 'Since', 'Earlier ISPs', 'Current ISP', 'ISP Lock']);
		foreach (array_slice($rChanges, 0, self::MAX_ROWS, true) as $rID => $c) {
			$l = $rCtx['lines'][$rID];
			$rAdded = $c['kind'] === 'added';
			$rHtml .= '<tr class="' . ($rAdded ? 'table-warning' : '') . '">' . self::lineCells($l, $rCtx)
				. '<td><span class="badge ' . ($rAdded ? 'bg-label-warning' : 'bg-label-info') . '">' . ($rAdded ? 'Added' : 'Moved') . '</span></td>'
				. '<td><small class="fw-semibold">' . $h(implode(', ', $c['new'])) . '</small></td>'
				. '<td><small>' . self::ago($c['since'], $rCtx['now']) . '</small></td>'
				. '<td><small class="text-muted">' . $h(mb_strimwidth(implode(', ', $c['old']), 0, 90, '…')) . '</small></td>'
				. '<td><small>' . $h($l['isp_desc'] !== '' ? $l['isp_desc'] : '–') . '</small></td>'
				. '<td><small>' . ((int) ($l['is_isplock'] ?? 0) === 1 ? '<span class="badge bg-label-success">on</span>' : '<span class="text-muted">off</span>') . '</small></td></tr>';
		}
		return $rHtml . self::tail(count($rChanges));
	}

	private static function unusedTab(array $rUnused, array $rCtx): string {
		$h = static fn ($v): string => htmlspecialchars((string) $v, ENT_QUOTES);
		$rHtml = '<p class="text-muted small">Enabled, not expired, created over 30 days ago and not used for 30 days or more (never, or long ago). '
			. 'Lines nobody uses: a customer who left, a test line, or a line set up and forgotten.</p>';
		if ($rUnused === []) {
			return $rHtml . '<p class="text-muted mb-0">Nothing found.</p>';
		}
		$rCanDelete = $rCtx['can_delete'] ?? false;
		if ($rCanDelete) {
			$rHtml .= '<div class="d-flex flex-wrap gap-2 align-items-center mb-2">'
				. '<button type="button" class="btn btn-sm btn-danger" id="la-delete" disabled>Delete selected <span class="badge bg-white text-danger ms-1" id="la-delete-n">0</span></button>'
				. '<small class="text-muted">A deleted line cannot be brought back; its history stays, without the line. MAG and Enigma2 lines are deleted from their devices page.</small></div>';
		}
		$rHtml .= '<div class="table-responsive"><table class="table table-sm align-middle"><thead><tr>'
			. ($rCanDelete ? '<th><input type="checkbox" class="form-check-input" id="la-all" title="Select all shown"></th>' : '')
			. '<th>Line</th><th>Owner</th><th class="text-center" title="Connections now / allowed">Now</th><th>Last used</th><th>Created</th><th>Expires</th></tr></thead><tbody>';
		foreach (array_slice($rUnused, 0, self::MAX_ROWS, true) as $l) {
			$rHtml .= '<tr>' . ($rCanDelete ? '<td>' . (self::deletable($l) ? '<input type="checkbox" class="form-check-input la-pick" value="' . (int) $l['id'] . '">' : '') . '</td>' : '')
				. self::lineCells($l, $rCtx) . '<td><small>' . self::ago((int) $l['last_activity'], $rCtx['now']) . '</small></td>'
				. '<td><small>' . $h((int) $l['created_at'] > 0 ? date('Y-m-d', (int) $l['created_at']) : '–') . '</small></td>'
				. '<td><small>' . $h((int) $l['exp_date'] > 0 ? date('Y-m-d', (int) $l['exp_date']) : 'never') . '</small></td></tr>';
		}
		return $rHtml . self::tail(count($rUnused)) . ($rCanDelete ? self::deleteScript() : '');
	}

	/** Select, confirm, delete through `api?action=line_audit_delete`, then reload. */
	private static function deleteScript(): string {
		return <<<'JS'
<script>
(function () {
	var picks = function () { return Array.prototype.slice.call(document.querySelectorAll('.la-pick')); };
	var btn = document.getElementById('la-delete'), n = document.getElementById('la-delete-n'), all = document.getElementById('la-all');
	var chosen = function () { return picks().filter(function (c) { return c.checked; }).map(function (c) { return c.value; }); };
	var sync = function () { var k = chosen().length; n.textContent = k; btn.disabled = k === 0; };
	picks().forEach(function (c) { c.addEventListener('change', sync); });
	if (all) { all.addEventListener('change', function () { picks().forEach(function (c) { c.checked = all.checked; }); sync(); }); }
	btn.addEventListener('click', function () {
		var ids = chosen();
		if (!ids.length || !window.confirm('Delete ' + ids.length + ' line(s)? This cannot be undone.')) { return; }
		btn.disabled = true;
		jQuery.post('./api?action=line_audit_delete', { ids: JSON.stringify(ids) }, function (r) {
			if (!r || r.result !== true) { window.alert((r && r.message) || 'The lines could not be deleted.'); btn.disabled = false; return; }
			window.alert(r.deleted + ' line(s) deleted' + (r.skipped ? ', ' + r.skipped + ' left: no longer unused, or a MAG/Enigma2 line' : '') + '.');
			window.location.reload();
		}, 'json').fail(function () { window.alert('The request failed.'); btn.disabled = false; });
	});
})();
</script>
JS;
	}

	private static function unstableTab(array $rUnstable, array $rCtx): string {
		$rHtml = '<p class="text-muted small">Lines with 20 sessions or more under a minute in ' . self::window($rCtx) . ', at least half of all their sessions. '
			. 'A weak connection, a player that reconnects in a loop, or a stream that fails for everyone — if several lines show it on the same channel, look at the channel in Source Health. '
			. 'Short sessions that hand over between IPs are something else: two places taking the line from each other (the panel closes one when the other opens); those are in Shared accounts too.</p>';
		if ($rUnstable === []) {
			return $rHtml . '<p class="text-muted mb-0">Nothing found.</p>';
		}
		$rHtml .= self::head(['Short sessions', 'All sessions', 'Watched', 'IPs', 'Handovers', 'Likely cause']);
		foreach (array_slice($rUnstable, 0, self::MAX_ROWS, true) as $l) {
			$rHist = $rCtx['history'][(int) $l['id']];
			$rHand = $rCtx['handovers'][(int) $l['id']] ?? null;
			$rHtml .= '<tr class="' . (($rHand['returns'] ?? 0) >= LineAuditAnalyzer::HANDOVER_RETURNS ? 'table-warning' : '') . '">' . self::lineCells($l, $rCtx) . '<td class="text-center text-danger">' . $rHist['short'] . '</td><td class="text-center">' . $rHist['sessions'] . '</td>'
				. '<td>' . LineAuditAnalyzer::duration($rHist['watched']) . '</td>'
				. '<td class="text-center">' . $rHist['ips'] . '</td>'
				. '<td class="text-center">' . self::handoverCell($rHand) . '</td>'
				. '<td><small>' . LineAuditAnalyzer::unstableCause($rHist, $rHand) . '</small></td></tr>';
		}
		return $rHtml . self::tail(count($rUnstable));
	}

	/** @param array{handovers:int, returns:int}|null $rHand */
	private static function handoverCell(?array $rHand): string {
		if ($rHand === null) {
			return '<span class="text-muted">0</span>';
		}
		$rHot = $rHand['returns'] >= LineAuditAnalyzer::HANDOVER_RETURNS;
		return '<span class="' . ($rHot ? 'text-danger fw-semibold' : '') . '" title="handovers between IPs, of them returns to the IP before">'
			. $rHand['handovers'] . ($rHand['returns'] > 0 ? ' <small class="text-muted">(' . $rHand['returns'] . ' back)</small>' : '') . '</span>';
	}

	/**
	 * The player families of a line: those of its sessions in the window (with
	 * how many each), else those seen live; restreaming tools in red.
	 *
	 * @param array<string, int> $rFamilies
	 */
	private static function players(array $rFamilies, string $rLive): string {
		if ($rFamilies === [] && $rLive !== '') {
			$rFamilies = array_fill_keys(array_map('trim', explode(',', $rLive)), 0);
		}
		$rOut = [];
		foreach ($rFamilies as $rFamily => $n) {
			$rText = htmlspecialchars((string) $rFamily, ENT_QUOTES) . ($n > 0 ? ' <span class="text-muted">' . $n . '</span>' : '');
			$rOut[] = in_array($rFamily, LineAuditAnalyzer::TOOLS, true) ? '<span class="text-danger fw-semibold">' . $rText . '</span>' : $rText;
		}
		return implode(', ', $rOut);
	}

	private static function ago(int $rTime, int $rNow): string {
		return $rTime > 0 ? htmlspecialchars(date('Y-m-d', $rTime), ENT_QUOTES) . ' <span class="text-muted">(' . LineAuditAnalyzer::duration(max(0, $rNow - $rTime)) . ' ago)</span>' : '<span class="text-muted">never</span>';
	}
}
