<?php

namespace XcVm\Module\LineAudit;

use XcVm\Core\Auth\Authorization;
use XcVm\Core\Http\RequestManager;
use XcVm\Core\Util\AdminHelpers;
use XcVm\Core\Util\LayoutRenderer;

class LineAuditController {
	/** `line_audit` — the Line Audit page. */
	public function page(): void {
		if (!Authorization::check('adv', 'users')) {
			AdminHelpers::goHome();
			exit;
		}
		$GLOBALS['_TITLE'] = 'Line Audit';
		LayoutRenderer::renderHeader('admin');
		try {
			$rBody = LineAuditPage::render([
				'view' => RequestManager::get('view', ''), 'days' => RequestManager::get('days', 1),
				'reseller' => RequestManager::get('reseller', 0), 'q' => RequestManager::get('q', ''),
			], time());
		} catch (\Throwable $e) {
			error_log('line-audit: ' . $e->getMessage());
			$rBody = '<p class="text-danger mb-0">The audit could not be shown: ' . htmlspecialchars($e->getMessage(), ENT_QUOTES) . '</p>';
		}
		echo '<div class="card"><div class="card-header"><h5 class="card-title mb-0">Line Audit</h5></div><div class="card-body">' . $rBody . '</div></div>';
		LayoutRenderer::renderFooter('admin');
		echo '</body></html>';
	}
}
