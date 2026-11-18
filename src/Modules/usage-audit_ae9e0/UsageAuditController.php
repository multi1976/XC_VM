<?php

namespace XcVm\Module\UsageAudit;

use XcVm\Core\Auth\Authorization;
use XcVm\Core\Http\RequestManager;
use XcVm\Core\Util\AdminHelpers;
use XcVm\Core\Util\LayoutRenderer;
use XcVm\Public\Controllers\Admin\Ajax\BaseAjaxController;

class UsageAuditController extends BaseAjaxController {
	/** `usage_audit` — the Usage Audit page. */
	public function page(): void {
		if (!Authorization::check('adv', 'streams')) {
			AdminHelpers::goHome();
			exit;
		}
		$GLOBALS['_TITLE'] = 'Usage Audit';
		LayoutRenderer::renderHeader('admin');
		try {
			$rBody = UsageAuditPage::render([
				'view' => RequestManager::get('view', ''), 'days' => RequestManager::get('days', 7),
				'kind' => RequestManager::get('kind', ''), 'q' => RequestManager::get('q', ''),
			], time());
		} catch (\Throwable $e) {
			error_log('usage-audit: ' . $e->getMessage());
			$rBody = '<p class="text-danger mb-0">The audit could not be shown: ' . htmlspecialchars($e->getMessage(), ENT_QUOTES) . '</p>';
		}
		echo '<div class="card"><div class="card-header"><h5 class="card-title mb-0">Usage Audit</h5></div><div class="card-body">' . $rBody . '</div></div>';
		LayoutRenderer::renderFooter('admin');
		echo '</body></html>';
	}
}
