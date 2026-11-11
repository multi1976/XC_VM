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
		'waiting' => ['Waiting', 'bg-label-secondary', 'Not checked: its provider account is in use by a live stream, and a check would take a connection from it.'],
		'pending' => ['Not checked yet', 'bg-label-secondary', 'Queued: sources are checked a few per provider every five minutes.'],
		'skip'    => ['Not HTTP', 'bg-label-secondary', 'Only http(s) sources are checked.'],
	];

	/** @param array<string, mixed> $rQuery the page's GET parameters (status, host) */
	public static function render(array $rQuery, int $rNow): string {
		$h = static fn ($v): string => htmlspecialchars((string) $v, ENT_QUOTES);
		$rState = SourceHealthRepository::auditState();
		$rOnAir = SourceHealthRepository::onAirSources();
		$rLive = array_flip(array_map(static fn (string $s): string => md5(trim($s)), $rOnAir));
		$rBusy = [];
		foreach ($rOnAir as $rSource) {
			$rBusy[(string) SourceProber::accountKey($rSource)] = true;
		}

		$rRows = [];
		$rCounts = array_fill_keys(array_keys(self::LABELS), 0);
		$rHosts = [];
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
				$rCounts[$rStatus]++;
				$rHosts[$rHost][$rStatus] = ($rHosts[$rHost][$rStatus] ?? 0) + 1;
				$rRows[] = ['stream' => $rStream, 'index' => $i, 'source' => $rSource, 'host' => $rHost, 'status' => $rStatus, 'audit' => $rAudit];
			}
		}

		$rWant = (string) ($rQuery['status'] ?? 'problems');
		$rWantHost = strtolower(trim((string) ($rQuery['host'] ?? '')));
		$rShown = array_values(array_filter($rRows, static function (array $r) use ($rWant, $rWantHost): bool {
			if ($rWantHost !== '' && $r['host'] !== $rWantHost) {
				return false;
			}
			return $rWant === 'all' || ($rWant === 'problems' ? in_array($r['status'], ['dead', 'down'], true) : $r['status'] === $rWant);
		}));
		usort($rShown, static fn (array $a, array $b): int => [self::rank($a['status']), $a['stream']['name']] <=> [self::rank($b['status']), $b['stream']['name']]);

		$rLink = static fn (string $rStatus, string $rHost = ''): string => './source_audit?status=' . rawurlencode($rStatus) . ($rHost !== '' ? '&host=' . rawurlencode($rHost) : '');
		$rHtml = '<p class="text-muted small">Every source of every live stream, checked with one short HTTP request (first 2 KB, no ffmpeg): about once a day while it answers, every '
			. intdiv(SourceAudit::FAIL_INTERVAL_SEC, 3600) . ' h while it does not, at most ' . SourceAudit::PER_HOST_PER_RUN . ' per provider every five minutes. '
			. 'A source on air, or on a provider account a live stream is using, is never checked. Read-only: nothing here changes a stream.</p>';

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
		$rHtml .= '<th></th></tr></thead><tbody>';
		foreach ($rHosts as $rHost => $rByStatus) {
			$rChecked = ($rByStatus['dead'] ?? 0) + ($rByStatus['down'] ?? 0) + ($rByStatus['ok'] ?? 0);
			$rAllOff = $rChecked >= 2 && ($rByStatus['ok'] ?? 0) === 0 && ($rByStatus['on_air'] ?? 0) === 0;
			$rHtml .= '<tr' . ($rWantHost === $rHost ? ' class="table-active"' : '') . '><td><a href="' . $h($rLink('all', $rHost)) . '">' . $h($rHost) . '</a></td><td class="text-center">' . array_sum($rByStatus) . '</td>';
			foreach (['dead', 'down', 'ok', 'on_air', 'waiting', 'pending'] as $rKey) {
				$n = $rByStatus[$rKey] ?? 0;
				$rHtml .= '<td class="text-center' . ($n === 0 ? ' text-muted' : '') . '">' . ($n ?: '·') . '</td>';
			}
			$rHtml .= '<td>' . ($rAllOff ? '<span class="badge bg-label-danger">every checked source off</span>' : '') . '</td></tr>';
		}
		$rHtml .= '</tbody></table></div>';

		// Sources.
		$rHtml .= '<h6 class="mb-1">Sources' . ($rWantHost !== '' ? ' on ' . $h($rWantHost) . ' <a class="small" href="' . $h($rLink($rWant)) . '">(all providers)</a>' : '') . '</h6>';
		if ($rShown === []) {
			return $rHtml . '<p class="text-muted mb-0">Nothing to show for this filter.</p>';
		}
		$rHtml .= '<div class="table-responsive"><table class="table table-sm"><thead><tr><th>Stream</th><th class="text-center">#</th><th>Source</th><th>State</th><th>Reason</th>'
			. '<th>Off since</th><th>Last seen up</th><th>Checked</th></tr></thead><tbody>';
		foreach (array_slice($rShown, 0, self::MAX_ROWS) as $r) {
			$rAudit = $r['audit'];
			[$rLabel, $rClass, $rTitle] = self::LABELS[$r['status']];
			$rKind = $rAudit !== null && $rAudit['kind'] !== null ? (string) $rAudit['kind'] : null;
			$rWhen = static fn (int $t): string => $t > 0 ? $h(date('Y-m-d H:i', $t)) . '<br><small class="text-muted">' . $h(SourceHealthTab::duration(max(0, $rNow - $t))) . ' ago</small>' : '<span class="text-muted">–</span>';
			$rHtml .= '<tr><td><a href="./stream?id=' . (int) $r['stream']['id'] . '" target="_blank" rel="noopener">' . $h($r['stream']['name']) . '</a>'
				. ' <a class="small text-muted" href="./source_health?id=' . (int) $r['stream']['id'] . '" target="_blank" rel="noopener" title="Source Health report">↗</a></td>'
				. '<td class="text-center">' . ((int) $r['index'] + 1) . '<small class="text-muted">/' . count($r['stream']['sources']) . '</small></td>'
				. '<td><code class="small">' . $h(SourceHealthTab::mask($r['source'])) . '</code></td>'
				. '<td><span class="badge ' . $rClass . '" title="' . $h($rTitle) . '">' . $h($rLabel) . '</span>'
				. ($rAudit !== null && (int) $rAudit['fails'] > 0 ? '<br><small class="text-muted">' . (int) $rAudit['fails'] . ' failed check(s)</small>' : '') . '</td>'
				. '<td><small>' . ($rAudit !== null && $rAudit['reason'] !== '' ? '<code class="text-wrap">' . $h($rAudit['reason']) . '</code>' : '') . '</small>'
				. ($rKind === 'gone' ? ' <span class="badge bg-label-danger">permanent</span>' : '') . ($rKind === 'temporary' ? ' <span class="badge bg-label-secondary">temporary</span>' : '') . '</td>'
				. '<td><small>' . ($rAudit !== null && (int) $rAudit['first_fail'] > 0 ? $rWhen((int) $rAudit['first_fail']) : '<span class="text-muted">–</span>') . '</small></td>'
				. '<td><small>' . ($rAudit !== null ? $rWhen((int) $rAudit['last_ok']) : '<span class="text-muted">–</span>') . '</small></td>'
				. '<td><small>' . ($rAudit !== null ? $rWhen((int) $rAudit['checked_at']) : '<span class="text-muted">–</span>') . '</small></td></tr>';
		}
		$rHtml .= '</tbody></table></div>';
		if (count($rShown) > self::MAX_ROWS) {
			$rHtml .= '<p class="text-muted small">Showing the first ' . self::MAX_ROWS . ' of ' . count($rShown) . '; filter by provider to see the rest.</p>';
		}
		return $rHtml;
	}

	private static function rank(string $rStatus): int {
		return (int) array_search($rStatus, ['dead', 'down', 'pending', 'waiting', 'ok', 'on_air', 'skip'], true);
	}
}
