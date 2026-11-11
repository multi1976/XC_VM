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
			. '</div></div>';
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
			$rBody = SourceAuditPage::render(['status' => RequestManager::get('status', 'problems'), 'host' => RequestManager::get('host', ''), 'q' => RequestManager::get('q', '')], time());
		} catch (\Throwable $e) {
			error_log('source-health audit: ' . $e->getMessage());
			$rBody = '<p class="text-danger mb-0">The audit could not be shown: ' . htmlspecialchars($e->getMessage(), ENT_QUOTES) . '</p>';
		}
		echo '<div class="card"><div class="card-header"><h5 class="card-title mb-0">Source Audit</h5></div><div class="card-body">' . $rBody . '</div></div>';
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
