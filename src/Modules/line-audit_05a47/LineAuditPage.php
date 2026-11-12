<?php

namespace XcVm\Module\LineAudit;

/**
 * The Line Audit page: one tab per kind of finding, a reseller filter and a
 * username search on all of them, over the last 24 hours or 7 days. Read-only.
 */
final class LineAuditPage {
	private const MAX_ROWS = 500;
	private const TABS = [
		'overview' => 'Overview', 'sharing' => 'Shared accounts', 'limits' => 'Connection limits', 'refused' => 'Refused',
		'expiry' => 'Expiring', 'unused' => 'Unused', 'unstable' => 'Unstable clients',
	];

	/** @param array<string, mixed> $rQuery GET parameters: view, days, reseller, q */
	public static function render(array $rQuery, int $rNow): string {
		$h = static fn ($v): string => htmlspecialchars((string) $v, ENT_QUOTES);
		$rView = isset(self::TABS[$rQuery['view'] ?? '']) ? (string) $rQuery['view'] : 'overview';
		$rDays = (int) ($rQuery['days'] ?? 1) === 7 ? 7 : 1;
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
		$rOwners = LineAuditRepository::owners(array_column($rLines, 'member_id'));
		$rKeep = static fn (array $l): bool => ($rReseller === 0 || (int) $l['member_id'] === $rReseller)
			&& ($rSearch === '' || mb_stripos((string) $l['username'], $rSearch) !== false);
		$rScope = array_filter($rLines, $rKeep);

		// Findings, per tab.
		$rSharing = [];
		foreach ($rScope as $rID => $l) {
			$s = LineAuditAnalyzer::sharing($rSamples[$rID] ?? null, $rHistory[$rID] ?? null);
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
		$rUnused = array_filter($rScope, static fn (array $l): bool => self::enabled($l) && ((int) $l['exp_date'] === 0 || (int) $l['exp_date'] > $rNow)
			&& (int) $l['last_activity'] < $rNow - 30 * 86400 && (int) $l['created_at'] < $rNow - 30 * 86400);
		uasort($rUnused, static fn (array $a, array $b): int => (int) $a['last_activity'] <=> (int) $b['last_activity']);
		$rUnstable = array_filter($rScope, static fn (array $l): bool => LineAuditAnalyzer::unstable($rHistory[(int) $l['id']] ?? null));
		uasort($rUnstable, static fn (array $a, array $b): int => ($rHistory[(int) $b['id']]['short'] ?? 0) <=> ($rHistory[(int) $a['id']]['short'] ?? 0));

		$rCounts = [
			'sharing' => count($rSharing), 'limits' => count($rLimits), 'refused' => count($rRefused),
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

		$rCtx = ['lines' => $rLines, 'live' => $rLive, 'history' => $rHistory, 'samples' => $rSamples, 'refusals' => $rRefusals, 'owners' => $rOwners, 'now' => $rNow, 'days' => $rDays];
		return $rHtml . match ($rView) {
			'sharing'  => self::sharingTab($rSharing, $rCtx),
			'limits'   => self::limitsTab($rLimits, $rCtx),
			'refused'  => self::refusedTab($rRefused, $rCtx),
			'expiry'   => self::expiryTab($rExpiring, $rExpiredTrying, $rCtx),
			'unused'   => self::unusedTab($rUnused, $rCtx),
			'unstable' => self::unstableTab($rUnstable, $rCtx),
			default    => self::overview($rCounts, $rSharing, $rRefusals, $rScope, $rCtx),
		};
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
			. '(two countries at once cannot be one household); the rest from the sessions of ' . self::window($rCtx) . '. A restreamer is expected to look like this. '
			. 'A phone on mobile data and a TV at home are two IPs and two ISPs: one sign alone is a note, not a verdict.</p>';
		if ($rSharing === []) {
			return $rHtml . '<p class="text-muted mb-0">Nothing found.</p>';
		}
		$rHtml .= self::head(['Verdict', 'Why', 'Countries', 'ISPs (widest moment)', 'Sessions']);
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
				. '<td><small>' . $h(trim(($rSam['country_list'] ?? '') !== '' ? $rSam['country_list'] : ($rHist['country_list'] ?? ''), ',')) . '</small></td>'
				. '<td><small class="text-muted">' . $h(mb_strimwidth((string) ($rSam['isp_list'] ?? ''), 0, 90, '…')) . '</small></td>'
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
			. 'Repeated refusals of one kind say what to fix: an expired line that is still used, a wrong country or ISP lock, a player that sends no user agent…</p>';
		if ($rRefused === []) {
			return $rHtml . '<p class="text-muted mb-0">Nothing found. If you expected some, check that client logs are saved (Settings → <em>client_logs_save</em>).</p>';
		}
		$rHtml .= self::head(['Refusals', 'By reason']);
		foreach (array_slice($rRefused, 0, self::MAX_ROWS, true) as $rID => $rStatuses) {
			if (!isset($rCtx['lines'][$rID])) {
				continue;
			}
			arsort($rStatuses);
			$rBadges = '';
			foreach ($rStatuses as $rStatus => $n) {
				$rBadges .= '<span class="badge bg-label-' . (in_array($rStatus, LineAuditAnalyzer::LINE_REFUSALS, true) ? 'warning' : 'secondary') . ' me-1">' . $h($rStatus) . ' ' . $n . '</span>';
			}
			$rHtml .= '<tr>' . self::lineCells($rCtx['lines'][$rID], $rCtx) . '<td class="text-center">' . array_sum($rStatuses) . '</td><td>' . $rBadges . '</td></tr>';
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

	private static function unusedTab(array $rUnused, array $rCtx): string {
		$h = static fn ($v): string => htmlspecialchars((string) $v, ENT_QUOTES);
		$rHtml = '<p class="text-muted small">Enabled, not expired, created over 30 days ago and not used for 30 days or more (never, or long ago). '
			. 'Lines nobody uses: a customer who left, a test line, or a line set up and forgotten.</p>';
		if ($rUnused === []) {
			return $rHtml . '<p class="text-muted mb-0">Nothing found.</p>';
		}
		$rHtml .= self::head(['Last used', 'Created', 'Expires']);
		foreach (array_slice($rUnused, 0, self::MAX_ROWS, true) as $l) {
			$rHtml .= '<tr>' . self::lineCells($l, $rCtx) . '<td><small>' . self::ago((int) $l['last_activity'], $rCtx['now']) . '</small></td>'
				. '<td><small>' . $h((int) $l['created_at'] > 0 ? date('Y-m-d', (int) $l['created_at']) : '–') . '</small></td>'
				. '<td><small>' . $h((int) $l['exp_date'] > 0 ? date('Y-m-d', (int) $l['exp_date']) : 'never') . '</small></td></tr>';
		}
		return $rHtml . self::tail(count($rUnused));
	}

	private static function unstableTab(array $rUnstable, array $rCtx): string {
		$rHtml = '<p class="text-muted small">Lines with 20 sessions or more under a minute in ' . self::window($rCtx) . ', at least half of all their sessions. '
			. 'A weak connection, a player that reconnects in a loop, or a stream that fails for everyone — if several lines show it on the same channel, look at the channel in Source Health.</p>';
		if ($rUnstable === []) {
			return $rHtml . '<p class="text-muted mb-0">Nothing found.</p>';
		}
		$rHtml .= self::head(['Short sessions', 'All sessions', 'Watched']);
		foreach (array_slice($rUnstable, 0, self::MAX_ROWS, true) as $l) {
			$rHist = $rCtx['history'][(int) $l['id']];
			$rHtml .= '<tr>' . self::lineCells($l, $rCtx) . '<td class="text-center text-danger">' . $rHist['short'] . '</td><td class="text-center">' . $rHist['sessions'] . '</td>'
				. '<td>' . LineAuditAnalyzer::duration($rHist['watched']) . '</td></tr>';
		}
		return $rHtml . self::tail(count($rUnstable));
	}

	private static function ago(int $rTime, int $rNow): string {
		return $rTime > 0 ? htmlspecialchars(date('Y-m-d', $rTime), ENT_QUOTES) . ' <span class="text-muted">(' . LineAuditAnalyzer::duration(max(0, $rNow - $rTime)) . ' ago)</span>' : '<span class="text-muted">never</span>';
	}
}
