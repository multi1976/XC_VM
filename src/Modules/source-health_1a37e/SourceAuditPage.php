<?php

namespace XcVm\Module\SourceHealth;

/**
 * The Source Audit page: every source of every live stream, by state, with a
 * per-provider summary. Read-only.
 */
final class SourceAuditPage {
	private const MAX_ROWS = 1000;
	private const LABELS = [
		'dead'    => ['Dead', 'bg-label-danger', 'Off on repeated checks, or the provider says it is gone. Replace or remove.'],
		'down'    => ['Down', 'bg-label-warning', 'Failed its last check(s); not yet enough evidence to call it dead.'],
		'ok'      => ['OK', 'bg-label-success', 'Answered with media on its last check.'],
		'on_air'  => ['On air', 'bg-label-primary', 'In use by a live stream right now: up, and never checked, so the live line is not disturbed.'],
		'waiting' => ['Waiting', 'bg-label-secondary', 'Not checked yet: its provider account is in use by a live stream, and the provider has not said a connection is free (or cannot be asked). It is checked once it does.'],
		'pending' => ['Not checked yet', 'bg-label-secondary', 'Queued: sources are checked a few per provider every five minutes.'],
		'skip'    => ['Not HTTP', 'bg-label-secondary', 'Only http(s) sources are checked.'],
	];

	/** @param array<string, mixed> $rQuery the page's GET parameters (status, host) */
	public static function render(array $rQuery, int $rNow): string {
		$h = static fn ($v): string => htmlspecialchars((string) $v, ENT_QUOTES);
		$rView = ($rQuery['view'] ?? '') === 'accounts' ? 'accounts' : 'sources';
		$rTabs = '<ul class="nav nav-tabs mb-3"><li class="nav-item"><a class="nav-link' . ($rView === 'sources' ? ' active' : '') . '" href="./source_audit">Sources</a></li>'
			. '<li class="nav-item"><a class="nav-link' . ($rView === 'accounts' ? ' active' : '') . '" href="./source_audit?view=accounts">Provider accounts</a></li></ul>';
		if ($rView === 'accounts') {
			return $rTabs . self::accounts($rNow);
		}
		$rState = SourceHealthRepository::auditState();
		$rAccounts = SourceHealthRepository::accounts();
		$rOnAir = SourceHealthRepository::onAirSources();
		$rLive = array_flip(array_map(static fn (string $s): string => md5(trim($s)), $rOnAir));
		$rBusy = [];
		foreach ($rOnAir as $rSource) {
			$rBusy[(string) SourceProber::accountKey($rSource)] = true;
		}

		$rRows = [];
		$rCounts = array_fill_keys(array_keys(self::LABELS), 0);
		$rHosts = [];
		$rAccountStates = [];
		foreach (SourceHealthRepository::liveStreams() as $rStream) {
			foreach ($rStream['sources'] as $i => $rSource) {
				if ($rSource === '') {
					continue;
				}
				$rHash = md5($rSource);
				$rAudit = $rState[$rHash] ?? null;
				$rStatus = match (true) {
					isset($rLive[$rHash])                                     => 'on_air',
					!SourceAudit::checkable($rSource)                         => 'skip',
					$rAudit !== null                                          => (string) $rAudit['status'],
					isset($rBusy[(string) SourceProber::accountKey($rSource)]) => 'waiting',
					default                                                   => 'pending',
				};
				$rStatus = isset(self::LABELS[$rStatus]) ? $rStatus : 'pending';
				$rHost = SourceAudit::host($rSource) ?: '(none)';
				$rAccountKey = SourceProber::accountKey($rSource);
				$rAccount = $rAccountKey !== null ? ($rAccounts[md5($rAccountKey)] ?? null) : null;
				$rCounts[$rStatus]++;
				$rHosts[$rHost][$rStatus] = ($rHosts[$rHost][$rStatus] ?? 0) + 1;
				if ($rAccount !== null && $rAccount['api']) {
					$rAccountStates[$rHost][md5((string) $rAccountKey)] = ProviderAccount::deadReason($rAccount, $rNow) === null ? 'active' : 'off';
				}
				$rRows[] = ['stream' => $rStream, 'index' => $i, 'source' => $rSource, 'host' => $rHost, 'status' => $rStatus, 'audit' => $rAudit, 'account' => $rAccount];
			}
		}

		$rWant = (string) ($rQuery['status'] ?? 'problems');
		$rWantHost = strtolower(trim((string) ($rQuery['host'] ?? '')));
		$rSearch = mb_substr(trim((string) ($rQuery['q'] ?? '')), 0, 100);
		$rShown = array_values(array_filter($rRows, static function (array $r) use ($rWant, $rWantHost, $rSearch): bool {
			if ($rWantHost !== '' && $r['host'] !== $rWantHost) {
				return false;
			}
			// Search what the page shows: stream name, provider host, reason. Never the full URL (it holds the account).
			if ($rSearch !== '' && mb_stripos($r['stream']['name'] . ' ' . $r['host'] . ' ' . (string) ($r['audit']['reason'] ?? ''), $rSearch) === false) {
				return false;
			}
			return $rWant === 'all' || ($rWant === 'problems' ? in_array($r['status'], ['dead', 'down'], true) : $r['status'] === $rWant);
		}));
		usort($rShown, static fn (array $a, array $b): int => [self::rank($a['status']), $a['stream']['name']] <=> [self::rank($b['status']), $b['stream']['name']]);

		$rLink = static fn (string $rStatus, string $rHost = ''): string => './source_audit?status=' . rawurlencode($rStatus) . ($rHost !== '' ? '&host=' . rawurlencode($rHost) : '') . ($rSearch !== '' ? '&q=' . rawurlencode($rSearch) : '');
		$rHtml = $rTabs . '<p class="text-muted small">Every source of every live stream, checked with one short HTTP request (first 2 KB, no ffmpeg): about once a day while it answers, every '
			. intdiv(SourceAudit::FAIL_INTERVAL_SEC, 3600) . ' h while it does not, at most ' . SourceAudit::PER_HOST_PER_RUN . ' per provider every five minutes. '
			. 'A source on air is never checked; one on a provider account a live stream is using only when the provider\'s own API says the account has a connection to spare. '
			. 'The provider API also reports expired, banned or refused accounts. Read-only: nothing here changes a stream.</p>'
			. '<form method="get" action="./source_audit" class="d-flex flex-wrap gap-2 mb-3" style="max-width:640px">'
			. '<input type="hidden" name="status" value="' . $h($rWant) . '">' . ($rWantHost !== '' ? '<input type="hidden" name="host" value="' . $h($rWantHost) . '">' : '')
			. '<input type="search" name="q" value="' . $h($rSearch) . '" class="form-control form-control-sm" style="flex:1;min-width:220px" placeholder="Search stream name, provider host or reason">'
			. '<button class="btn btn-sm btn-primary" type="submit">Search</button>'
			. ($rSearch !== '' ? '<a class="btn btn-sm btn-outline-secondary" href="./source_audit?status=' . rawurlencode($rWant) . ($rWantHost !== '' ? '&host=' . rawurlencode($rWantHost) : '') . '">Clear</a>' : '')
			. '</form>';

		// Summary.
		$rHtml .= '<div class="d-flex flex-wrap gap-2 mb-3">';
		foreach (['problems' => ['Dead + down', 'bg-label-danger']] + array_map(static fn (array $l): array => [$l[0], $l[1]], self::LABELS) as $rKey => [$rLabel, $rClass]) {
			$n = $rKey === 'problems' ? $rCounts['dead'] + $rCounts['down'] : $rCounts[$rKey];
			$rHtml .= '<a href="' . $h($rLink($rKey, $rWantHost)) . '" class="badge ' . $rClass . ' p-2' . ($rWant === $rKey ? ' border border-dark' : '') . '" title="' . $h(self::LABELS[$rKey][2] ?? '') . '">'
				. $h($rLabel) . ': ' . $n . '</a>';
		}
		$rHtml .= '<a href="' . $h($rLink('all', $rWantHost)) . '" class="badge bg-label-dark p-2' . ($rWant === 'all' ? ' border border-dark' : '') . '">All: ' . count($rRows) . '</a></div>';

		// Per provider.
		uasort($rHosts, static fn (array $a, array $b): int => (($b['dead'] ?? 0) + ($b['down'] ?? 0)) <=> (($a['dead'] ?? 0) + ($a['down'] ?? 0)));
		$rHtml .= '<h6 class="mb-1">By provider</h6><p class="text-muted small mb-2">Providers with sources off first. When every checked source of a provider is off, it is likely the provider (or the account) that is down, not each channel.</p>'
			. '<div class="table-responsive" style="max-height:320px;overflow:auto"><table class="table table-sm mb-3"><thead><tr><th>Host</th><th class="text-center">Sources</th>';
		foreach (['dead', 'down', 'ok', 'on_air', 'waiting', 'pending'] as $rKey) {
			$rHtml .= '<th class="text-center">' . $h(self::LABELS[$rKey][0]) . '</th>';
		}
		$rHtml .= '<th title="Provider accounts behind these sources, as the provider\'s API reports them">Accounts</th><th></th></tr></thead><tbody>';
		foreach ($rHosts as $rHost => $rByStatus) {
			$rChecked = ($rByStatus['dead'] ?? 0) + ($rByStatus['down'] ?? 0) + ($rByStatus['ok'] ?? 0);
			$rAllOff = $rChecked >= 2 && ($rByStatus['ok'] ?? 0) === 0 && ($rByStatus['on_air'] ?? 0) === 0;
			$rHtml .= '<tr' . ($rWantHost === $rHost ? ' class="table-active"' : '') . '><td><a href="' . $h($rLink('all', $rHost)) . '">' . $h($rHost) . '</a></td><td class="text-center">' . array_sum($rByStatus) . '</td>';
			foreach (['dead', 'down', 'ok', 'on_air', 'waiting', 'pending'] as $rKey) {
				$n = $rByStatus[$rKey] ?? 0;
				$rHtml .= '<td class="text-center' . ($n === 0 ? ' text-muted' : '') . '">' . ($n ?: '·') . '</td>';
			}
			$rStates = array_count_values($rAccountStates[$rHost] ?? []);
			$rHtml .= '<td class="small">' . ($rStates === [] ? '<span class="text-muted">–</span>' : (($rStates['active'] ?? 0) > 0 ? ($rStates['active'] . ' active') : '') . (($rStates['off'] ?? 0) > 0 ? (($rStates['active'] ?? 0) > 0 ? ', ' : '') . '<span class="text-danger">' . $rStates['off'] . ' expired/refused</span>' : '')) . '</td>';
			$rHtml .= '<td>' . ($rAllOff ? '<span class="badge bg-label-danger">every checked source off</span>' : '') . '</td></tr>';
		}
		$rHtml .= '</tbody></table></div>';

		// Sources.
		$rHtml .= '<div class="d-flex align-items-center gap-2 mb-1"><h6 class="mb-0">Sources' . ($rWantHost !== '' ? ' on ' . $h($rWantHost) . ' <a class="small" href="' . $h($rLink($rWant)) . '">(all providers)</a>' : '')
			. ' <small class="text-muted">' . count($rShown) . '</small></h6>';
		$rRequeue = array_values(array_unique(array_map(static fn (array $r): string => md5($r['source']), array_filter($rShown, static fn (array $r): bool => in_array($r['status'], ['dead', 'down', 'ok'], true)))));
		if ($rRequeue !== []) {
			$rHtml .= '<button type="button" class="btn btn-sm btn-outline-primary ms-auto" id="sa-requeue" data-hashes="' . $h(json_encode($rRequeue)) . '" title="Put these sources at the front of the queue: the cron checks them within the next runs, a few per provider every five minutes">Recheck all shown (' . count($rRequeue) . ')</button>';
		}
		$rHtml .= '</div>';
		if ($rShown === []) {
			return $rHtml . '<p class="text-muted mb-0">Nothing to show for this filter.</p>';
		}
		$rHtml .= '<div class="table-responsive"><table class="table table-sm"><thead><tr><th>Stream</th><th class="text-center">#</th><th>Source</th><th>State</th><th>Reason</th>'
			. '<th>Off since</th><th>Last seen up</th><th>Checked</th><th></th></tr></thead><tbody>';
		$rChannels = ProviderImport::channelCheck(array_map(static fn (array $r): array => ['source' => $r['source'], 'name' => $r['stream']['name']], array_slice($rShown, 0, self::MAX_ROWS)));
		foreach (array_slice($rShown, 0, self::MAX_ROWS) as $r) {
			$rChannel = $rChannels[md5($r['source'])] ?? null;
			$rAudit = $r['audit'];
			[$rLabel, $rClass, $rTitle] = self::LABELS[$r['status']];
			$rKind = $rAudit !== null && $rAudit['kind'] !== null ? (string) $rAudit['kind'] : null;
			$rWhen = static fn (int $t): string => $t > 0 ? $h(date('Y-m-d H:i', $t)) . '<br><small class="text-muted">' . $h(SourceHealthTab::duration(max(0, $rNow - $t))) . ' ago</small>' : '<span class="text-muted">–</span>';
			$rHtml .= '<tr><td><a href="./stream?id=' . (int) $r['stream']['id'] . '" target="_blank" rel="noopener">' . $h($r['stream']['name']) . '</a>'
				. ' <a class="small text-muted" href="./source_health?id=' . (int) $r['stream']['id'] . '" target="_blank" rel="noopener" title="Source Health report">↗</a></td>'
				. '<td class="text-center">' . ((int) $r['index'] + 1) . '<small class="text-muted">/' . count($r['stream']['sources']) . '</small></td>'
				. '<td><code class="small">' . $h(SourceHealthTab::mask($r['source'])) . '</code></td>'
				. '<td><span class="badge ' . $rClass . '" title="' . $h($rTitle) . '">' . $h($rLabel) . '</span>'
				. ($rAudit !== null && (int) $rAudit['fails'] > 0 ? '<br><small class="text-muted">' . (int) $rAudit['fails'] . ' failed check(s)</small>' : '')
				. self::accountNote($r['account'], $rNow) . '</td>'
				. '<td><small>' . ($rAudit !== null && $rAudit['reason'] !== '' ? '<code class="text-wrap">' . $h($rAudit['reason']) . '</code>' : '') . '</small>'
				. ($rChannel !== null && !$rChannel['listed']
					? '<br><small class="text-danger" title="The channel id in this source is not in the channel list the panel last fetched for Stream Provider ' . $h($rChannel['provider']) . '">not in the provider\'s channel list</small>'
						. ($rChannel['suggest'] !== [] ? '<br><small class="text-success">same name there: ' . implode(', ', array_map(static fn (array $c): string => 'ID ' . $c['id'] . ' (' . $h($c['name']) . ')', $rChannel['suggest'])) . '</small>' : '')
					: '')
				. ($rKind === 'gone' ? ' <span class="badge bg-label-danger">permanent</span>' : '') . ($rKind === 'temporary' ? ' <span class="badge bg-label-secondary">temporary</span>' : '') . '</td>'
				. '<td><small>' . ($rAudit !== null && (int) $rAudit['first_fail'] > 0 ? $rWhen((int) $rAudit['first_fail']) : '<span class="text-muted">–</span>') . '</small></td>'
				. '<td><small>' . ($rAudit !== null ? $rWhen((int) $rAudit['last_ok']) : '<span class="text-muted">–</span>') . '</small></td>'
				. '<td><small>' . ($rAudit !== null && (int) $rAudit['checked_at'] > 0 ? $rWhen((int) $rAudit['checked_at']) : ($rAudit !== null ? '<span class="text-muted">queued</span>' : '<span class="text-muted">–</span>')) . '</small></td>'
				. '<td>' . (in_array($r['status'], ['on_air', 'skip'], true) ? '' : '<button type="button" class="btn btn-sm btn-outline-secondary sa-recheck" data-hash="' . md5($r['source']) . '" data-stream="' . (int) $r['stream']['id'] . '" title="Check this source now (same rules: not if its account has no connection to spare)">Recheck</button>') . '</td></tr>';
		}
		$rHtml .= '</tbody></table></div>' . self::script();
		if (count($rShown) > self::MAX_ROWS) {
			$rHtml .= '<p class="text-muted small">Showing the first ' . self::MAX_ROWS . ' of ' . count($rShown) . '; filter by provider to see the rest.</p>';
		}
		return $rHtml;
	}

	/** The Provider accounts view: every account the sources use, and whether it is a Stream Provider. */
	private static function accounts(int $rNow): string {
		$h = static fn ($v): string => htmlspecialchars((string) $v, ENT_QUOTES);
		$rFound = ProviderImport::discover();
		$rInfo = SourceHealthRepository::accounts();
		uasort($rFound, static fn (array $a, array $b): int => [$a['provider'] !== null, $a['host'], $a['user']] <=> [$b['provider'] !== null, $b['host'], $b['user']]);
		$rNew = array_keys(array_filter($rFound, static fn (array $a): bool => $a['provider'] === null));
		$rHtml = '<p class="text-muted small">The provider accounts found in the sources of live streams (Xtream-style URLs: host, port, username, password). '
			. 'An account added here becomes a Stream Provider exactly as through <em>Add Provider</em>: the core then fetches its details and channel lists every hour, '
			. 'and Source Audit uses that list to tell a channel the provider has dropped, and which channel carries its name now, without opening a stream.</p>'
			. '<div class="alert alert-warning small py-2">The hourly provider sync downloads each provider\'s full live, VOD and series lists, one provider after another, and the core stops it after five minutes in all: '
			. 'with many providers, the last ones may not be synced every hour. Accounts added here are set to sync their live channels only (on a panel that supports per-list sync), '
			. 'which is all the audit needs; change it per provider in its form.</div>';
		if ($rFound === []) {
			return $rHtml . '<p class="text-muted mb-0">No Xtream-style accounts in the sources.</p>';
		}
		$rHtml .= '<div class="d-flex flex-wrap align-items-center gap-2 mb-2">'
			. '<button type="button" class="btn btn-sm btn-primary" id="sa-add-selected" disabled>Add selected to Stream Providers</button>'
			. ($rNew !== [] ? '<button type="button" class="btn btn-sm btn-outline-primary" id="sa-add-all" data-hashes="' . $h(json_encode($rNew)) . '">Add all not yet added (' . count($rNew) . ')</button>' : '')
			. '<label class="small ms-2"><input type="checkbox" id="sa-add-enabled" checked> enabled (hourly sync)</label>'
			. '<span class="small text-muted ms-auto">' . count($rFound) . ' accounts · ' . (count($rFound) - count($rNew)) . ' already Stream Providers</span></div>'
			. '<div class="table-responsive"><table class="table table-sm"><thead><tr><th><input type="checkbox" id="sa-all"></th><th>Host</th><th>Port</th><th>User</th><th>Provider API</th>'
			. '<th class="text-center">Connections</th><th>Expires</th><th class="text-center">Sources</th><th class="text-center">Streams</th><th>Stream Provider</th></tr></thead><tbody>';
		foreach ($rFound as $rHash => $rAcct) {
			$rApi = $rInfo[$rHash] ?? null;
			$rDead = ProviderAccount::deadReason($rApi, $rNow);
			$rUser = mb_substr($rAcct['user'], 0, 3) . (mb_strlen($rAcct['user']) > 3 ? '…' : '');
			$rHtml .= '<tr><td>' . ($rAcct['provider'] === null ? '<input type="checkbox" class="sa-acct" value="' . $h($rHash) . '">' : '') . '</td>'
				. '<td>' . $h($rAcct['host']) . '</td><td>' . (int) $rAcct['port'] . '</td><td><code>' . $h($rUser) . '</code></td>'
				. '<td><small>' . ($rApi === null ? '<span class="text-muted">not asked yet</span>' : (!$rApi['api'] ? '<span class="text-muted">no answer</span>'
					: ($rDead !== null ? '<span class="text-danger">' . $h(substr($rDead, 9)) . '</span>' : '<span class="text-success">' . $h($rApi['status'] ?: 'active') . '</span>'))) . '</small></td>'
				. '<td class="text-center"><small>' . ($rApi !== null && $rApi['api'] && $rApi['max'] !== null ? (int) $rApi['active'] . ' / ' . (int) $rApi['max'] : '–') . '</small></td>'
				. '<td><small>' . ($rApi !== null && $rApi['exp'] !== null ? $h(date('Y-m-d', $rApi['exp'])) : '–') . '</small></td>'
				. '<td class="text-center">' . (int) $rAcct['sources'] . '</td><td class="text-center">' . count($rAcct['streams']) . '</td>'
				. '<td>' . ($rAcct['provider'] !== null
					? '<a href="./provider?id=' . (int) $rAcct['provider']['id'] . '" target="_blank" rel="noopener">' . $h($rAcct['provider']['name']) . '</a>'
						. (!$rAcct['provider']['enabled'] ? ' <span class="badge bg-label-secondary">disabled</span>' : '')
						. ($rAcct['provider']['enabled'] && (int) ($rAcct['provider']['data']['streams'] ?? 0) > 0 ? ' <small class="text-muted">' . (int) $rAcct['provider']['data']['streams'] . ' channels</small>' : '')
					: '<span class="text-muted">–</span>') . '</td></tr>';
		}
		return $rHtml . '</tbody></table></div>' . <<<'JS'
<script>
(function () {
	var sel = document.getElementById('sa-add-selected'), all = document.getElementById('sa-all');
	function picked() { return Array.prototype.map.call(document.querySelectorAll('.sa-acct:checked'), function (c) { return c.value; }); }
	function sync() { sel.disabled = picked().length === 0; sel.textContent = 'Add selected to Stream Providers' + (picked().length ? ' (' + picked().length + ')' : ''); }
	document.querySelectorAll('.sa-acct').forEach(function (c) { c.addEventListener('change', sync); });
	if (all) { all.addEventListener('change', function () { document.querySelectorAll('.sa-acct').forEach(function (c) { c.checked = all.checked; }); sync(); }); }
	function add(hashes, btn) {
		if (!hashes.length || !confirm('Add ' + hashes.length + ' account(s) to Stream Providers?')) { return; }
		btn.disabled = true; btn.textContent = 'Adding…';
		jQuery.post('./api?action=source_audit_providers', {hashes: JSON.stringify(hashes), enabled: document.getElementById('sa-add-enabled').checked ? 1 : 0}, function (r) {
			if (r && r.result) { alert(r.added + ' added, ' + r.existing + ' already there' + (r.failed ? ', ' + r.failed + ' failed' : '') + '.'); location.reload(); }
			else { btn.textContent = 'Failed'; }
		}, 'json').fail(function () { btn.disabled = false; btn.textContent = 'Failed: retry'; });
	}
	sel.addEventListener('click', function () { add(picked(), sel); });
	var a = document.getElementById('sa-add-all');
	if (a) { a.addEventListener('click', function () { add(JSON.parse(a.dataset.hashes), a); }); }
})();
</script>
JS;
	}

	/** @param array{api:bool, auth:?bool, status:string, max:?int, active:?int, exp:?int, checked_at:int}|null $rAcct */
	private static function accountNote(?array $rAcct, int $rNow): string {
		if ($rAcct === null || !$rAcct['api']) {
			return '';
		}
		$rDead = ProviderAccount::deadReason($rAcct, $rNow);
		if ($rDead !== null) {
			return '<br><small class="text-danger">' . htmlspecialchars($rDead, ENT_QUOTES) . '</small>';
		}
		return '<br><small class="text-muted" title="What the provider\'s API said ' . htmlspecialchars(SourceHealthTab::duration(max(0, $rNow - $rAcct['checked_at'])), ENT_QUOTES) . ' ago">account '
			. ($rAcct['max'] !== null ? (int) $rAcct['active'] . '/' . (int) $rAcct['max'] . ' conn.' : 'active')
			. ($rAcct['exp'] !== null ? ' · until ' . date('Y-m-d', $rAcct['exp']) : '') . '</small>';
	}

	private static function script(): string {
		return <<<'JS'
<script>
(function () {
	document.querySelectorAll('.sa-recheck').forEach(function (b) {
		b.addEventListener('click', function () {
			b.disabled = true; b.textContent = 'Checking…';
			jQuery.post('./api?action=source_audit_recheck', {hash: b.dataset.hash, stream_id: b.dataset.stream}, function (r) {
				var msg = (r && r.message) || 'Failed';
				var cell = b.closest('tr').querySelector('td:nth-child(5)');
				if (cell) { cell.innerHTML = '<small><strong>' + (r && r.result ? 'Now: ' : 'Not checked: ') + '</strong></small>'; cell.firstChild.appendChild(document.createTextNode(msg)); }
				b.textContent = r && r.result ? 'Done' : 'Recheck'; b.disabled = !!(r && r.result);
			}, 'json').fail(function () { b.disabled = false; b.textContent = 'Recheck'; });
		});
	});
	var q = document.getElementById('sa-requeue');
	if (q) {
		q.addEventListener('click', function () {
			q.disabled = true;
			jQuery.post('./api?action=source_audit_recheck', {hashes: q.dataset.hashes}, function (r) {
				q.textContent = r && r.result ? r.queued + ' queued: checked within the next runs' : 'Failed';
			}, 'json').fail(function () { q.disabled = false; });
		});
	}
})();
</script>
JS;
	}

	private static function rank(string $rStatus): int {
		return (int) array_search($rStatus, ['dead', 'down', 'pending', 'waiting', 'ok', 'on_air', 'skip'], true);
	}
}
