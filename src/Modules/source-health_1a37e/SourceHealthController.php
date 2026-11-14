<?php

namespace XcVm\Module\SourceHealth;

use XcVm\Core\Auth\Authorization;
use XcVm\Core\Http\RequestManager;
use XcVm\Core\Util\AdminHelpers;
use XcVm\Core\Util\LayoutRenderer;
use XcVm\Public\Controllers\Admin\Ajax\BaseAjaxController;

/**
 * The Source Health full page (`source_health?id=N`) and
 * `api?action=source_health_probe`, which turns active probes of one
 * stream's sources on or off (the switch on the report).
 */
class SourceHealthController extends BaseAjaxController {
	/** `source_health?id=N` — the report on a full page (the Edit Stream tab sits in a narrow modal). */
	public function page(): void {
		if (!Authorization::check('adv', 'edit_stream')) {
			AdminHelpers::goHome();
			exit;
		}
		$rStream = SourceHealthRepository::stream((int) RequestManager::get('id', 0));
		$GLOBALS['_TITLE'] = 'Source Health';
		LayoutRenderer::renderHeader('admin');
		$h = static fn ($v): string => htmlspecialchars((string) $v, ENT_QUOTES);
		echo '<div class="card"><div class="card-header"><h5 class="card-title mb-0">Source Health'
			. ($rStream !== null ? ' — ' . $h($rStream['stream_display_name']) . ' <small class="text-muted">#' . (int) $rStream['id'] . '</small>' : '') . '</h5></div><div class="card-body">'
			. ($rStream !== null ? SourceHealthTab::render($rStream, 'edit', true) : '<p class="text-muted mb-0">No such live stream.</p>')
			. '</div></div>' . self::scrollButtons();
		LayoutRenderer::renderFooter('admin');
		echo '</body></html>';
	}

	/** `source_audit` — every source of every live stream, by state (Content → Streams → Source Audit). */
	public function audit(): void {
		if (!Authorization::check('adv', 'streams')) {
			AdminHelpers::goHome();
			exit;
		}
		$GLOBALS['_TITLE'] = 'Source Audit';
		LayoutRenderer::renderHeader('admin');
		try {
			$rBody = SourceAuditPage::render(['status' => RequestManager::get('status', 'problems'), 'host' => RequestManager::get('host', ''), 'q' => RequestManager::get('q', ''), 'view' => RequestManager::get('view', ''), 'level' => RequestManager::get('level', '')], time());
		} catch (\Throwable $e) {
			error_log('source-health audit: ' . $e->getMessage());
			$rBody = '<p class="text-danger mb-0">The audit could not be shown: ' . htmlspecialchars($e->getMessage(), ENT_QUOTES) . '</p>';
		}
		echo '<div class="card"><div class="card-header"><h5 class="card-title mb-0">Source Audit</h5></div><div class="card-body">' . $rBody . '</div></div>';
		echo self::scrollButtons();
		LayoutRenderer::renderFooter('admin');
		echo '</body></html>';
	}

	/** `api?action=source_audit_recheck` — one source now (hash + stream_id), or put many at the front of the queue (hashes). */
	public function recheck(): never {
		$this->requireXhr();
		$this->gateAny([['adv', 'streams']]);
		if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
			$this->fail();
		}
		$rHashes = json_decode((string) RequestManager::get('hashes', ''), true);
		if (is_array($rHashes)) {
			$this->ok(['queued' => SourceHealthRepository::requeueAudit($rHashes)]);
		}
		$rHash = (string) RequestManager::get('hash', '');
		if (preg_match('/^[0-9a-f]{32}$/', $rHash) !== 1) {
			$this->fail();
		}
		$rResult = SourceAudit::recheckNow((int) RequestManager::get('stream_id', 0), $rHash);
		$rResult['ok'] ? $this->ok(['message' => $rResult['message']]) : $this->fail(['message' => $rResult['message']]);
	}

	/** `api?action=source_audit_providers` — add accounts found in sources to Stream Providers (through the core's own provider service). */
	public function providers(): never {
		$this->requireXhr();
		$this->gateAny([['adv', 'streams']]);
		$rHashes = json_decode((string) RequestManager::get('hashes', ''), true);
		if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST' || !is_array($rHashes)) {
			$this->fail();
		}
		$rHashes = array_values(array_filter($rHashes, static fn ($h): bool => is_string($h) && preg_match('/^[0-9a-f]{32}$/', $h) === 1));
		$this->ok(ProviderImport::add($rHashes, (bool) RequestManager::get('enabled', 1)));
	}

	/**
	 * `api?action=source_audit_action` — delete or move to the end of their
	 * stream sources that are off. op=preview says what would happen, op=apply
	 * does it; both are worked out again from the streams as stored now.
	 */
	public function action(): never {
		$this->requireXhr();
		$this->gateAny([['adv', 'edit_stream']]);
		$rAction = (string) RequestManager::get('do', '');
		$rItems = json_decode((string) RequestManager::get('items', '[]'), true);
		$rHost = strtolower(trim((string) RequestManager::get('host', '')));
		if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST' || !in_array($rAction, SourceActions::ACTIONS, true) || !is_array($rItems)) {
			$this->fail();
		}
		$rItems = array_values(array_filter(array_map(
			static fn ($i): ?array => is_array($i) && isset($i[0], $i[1]) && preg_match('/^[0-9a-f]{32}$/', (string) $i[1]) === 1 ? [(int) $i[0], (string) $i[1]] : null,
			$rItems
		)));
		if ($rItems === [] && $rHost === '') {
			$this->fail(['message' => 'Nothing selected.']);
		}
		$rMask = static fn (array $r): array => ['name' => $r['name'], 'source' => $r['source'] !== '' ? SourceHealthTab::mask($r['source']) : '', 'why' => $r['why']];
		if ((string) RequestManager::get('op', 'preview') === 'apply') {
			$rResult = SourceActions::apply($rAction, $rItems, $rHost, (int) ($GLOBALS['rUserInfo']['id'] ?? 0), time());
			$rResult['refused'] = array_map($rMask, $rResult['refused']);
			$this->ok($rResult);
		}
		$rState = SourceHealthRepository::auditState();
		[$rStreams, $rWanted] = SourceActions::select($rAction, $rItems, $rHost, $rState);
		$rPlan = SourceActions::plan($rAction, $rStreams, $rWanted, $rState, SourceHealthRepository::onAirSources(), time());
		$this->ok([
			'changes' => array_map(static fn (array $c): array => ['name' => $c['name'], 'sources' => array_map(static fn (array $m): string => '#' . ($m['position'] + 1) . ' ' . SourceHealthTab::mask($m['source']), $c['moved'])], $rPlan['changes']),
			'refused' => array_map($rMask, $rPlan['refused']),
		]);
	}

	/** `api?action=source_audit_add` — add provider channels picked in Find sources; the URLs are written from the providers here. */
	public function add(): never {
		$this->requireXhr();
		$this->gateAny([['adv', 'edit_stream']]);
		$rItems = json_decode((string) RequestManager::get('items', '[]'), true);
		if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST' || !is_array($rItems)) {
			$this->fail();
		}
		$rItems = array_slice(array_values(array_filter(array_map(
			static fn ($i): ?array => is_array($i) && count($i) === 3 && (int) $i[0] > 0 && (int) $i[1] > 0 && (int) $i[2] > 0 ? [(int) $i[0], (int) $i[1], (int) $i[2]] : null,
			$rItems
		))), 0, 3000);
		if ($rItems === []) {
			$this->fail(['message' => 'Nothing selected.']);
		}
		$rResult = SourceActions::add($rItems, (int) ($GLOBALS['rUserInfo']['id'] ?? 0), time());
		$rResult['refused'] = array_map(static fn (array $r): array => ['name' => $r['name'], 'source' => $r['source'] !== '' ? SourceHealthTab::mask($r['source']) : '', 'why' => $r['why']], $rResult['refused']);
		$this->ok($rResult);
	}

	/** `api?action=source_audit_undo` — put a batch of changes back. */
	public function undo(): never {
		$this->requireXhr();
		$this->gateAny([['adv', 'edit_stream']]);
		$rBatch = (string) RequestManager::get('batch', '');
		if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST' || preg_match('/^[0-9a-f]{16}$/', $rBatch) !== 1) {
			$this->fail();
		}
		$this->ok(SourceActions::undo($rBatch, time()));
	}

	/** Two floating buttons to the top and the bottom of a long page; hidden while the page is short. */
	private static function scrollButtons(): string {
		return '<div id="sh-scroll" style="position:fixed;right:18px;bottom:18px;z-index:1050;display:none;flex-direction:column;gap:6px">'
			. '<button type="button" class="btn btn-sm btn-primary shadow" title="To the top" aria-label="To the top" onclick="window.scrollTo({top:0,behavior:\'smooth\'})">&#9650;</button>'
			. '<button type="button" class="btn btn-sm btn-primary shadow" title="To the bottom" aria-label="To the bottom" onclick="window.scrollTo({top:document.documentElement.scrollHeight,behavior:\'smooth\'})">&#9660;</button>'
			. '</div><script>(function(){var b=document.getElementById("sh-scroll");function f(){b.style.display=document.documentElement.scrollHeight>window.innerHeight*1.5?"flex":"none";}'
			. 'window.addEventListener("resize",f);window.addEventListener("load",f);setTimeout(f,300);})();</script>';
	}

	public function probe(): never {
		$this->requireXhr();
		$this->gateAny([['adv', 'edit_stream']]);
		$rStreamID = (int) RequestManager::get('stream_id', 0);
		if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST' || $rStreamID <= 0) {
			$this->fail();
		}
		SourceHealthRepository::setProbe($rStreamID, (bool) RequestManager::get('enabled'), (int) ($GLOBALS['rUserInfo']['id'] ?? 0) ?: null);
		$this->ok(['enabled' => SourceHealthRepository::probeEnabled($rStreamID)]);
	}
}
