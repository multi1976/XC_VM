<?php

namespace XcVm\Module\LineAudit;

use XcVm\Core\Auth\Authorization;
use XcVm\Core\Http\RequestManager;
use XcVm\Core\Util\AdminHelpers;
use XcVm\Core\Util\LayoutRenderer;
use XcVm\Domain\Line\LineRepository;
use XcVm\Public\Controllers\Admin\Ajax\BaseAjaxController;

class LineAuditController extends BaseAjaxController {
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
				'can_delete' => Authorization::check('adv', 'edit_user'),
			], time());
		} catch (\Throwable $e) {
			error_log('line-audit: ' . $e->getMessage());
			$rBody = '<p class="text-danger mb-0">The audit could not be shown: ' . htmlspecialchars($e->getMessage(), ENT_QUOTES) . '</p>';
		}
		echo '<div class="card"><div class="card-header"><h5 class="card-title mb-0">Line Audit</h5></div><div class="card-body">' . $rBody . '</div></div>';
		LayoutRenderer::renderFooter('admin');
		echo '</body></html>';
	}

	/**
	 * `api?action=line_audit_delete` — delete lines picked on the Unused tab.
	 * Each is checked again here: a line used, renewed or disabled since the
	 * page was opened, or a MAG/Enigma2 line, is left alone.
	 */
	public function delete(): never {
		$this->requireXhr();
		$this->gate('adv', 'edit_user');
		$rIDs = json_decode((string) RequestManager::get('ids', '[]'), true);
		if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST' || !is_array($rIDs)) {
			$this->fail();
		}
		[$rDelete, $rSkipped] = self::deletable(array_slice(array_values(array_unique(array_filter(array_map('intval', $rIDs), static fn (int $i): bool => $i > 0))), 0, 1000), time());
		if ($rDelete !== [] && !LineRepository::deleteMany($rDelete)) {
			$this->fail(['message' => 'The lines could not be deleted.']);
		}
		error_log('line-audit: user ' . (int) ($GLOBALS['rUserInfo']['id'] ?? 0) . ' deleted unused lines ' . implode(',', $rDelete));
		$this->ok(['deleted' => count($rDelete), 'skipped' => $rSkipped]);
	}

	/**
	 * Which of the picked lines are still unused and may be deleted here.
	 *
	 * @param list<int> $rIDs
	 * @return array{0: list<int>, 1: int} [ids to delete, how many left alone]
	 */
	public static function deletable(array $rIDs, int $rNow): array {
		if ($rIDs === []) {
			return [[], 0];   // lines([]) reads every line
		}
		$rOk = [];
		foreach (LineAuditRepository::lines($rIDs) as $rID => $l) {
			if (LineAuditPage::unused($l, $rNow) && LineAuditPage::deletable($l)) {
				$rOk[] = (int) $rID;
			}
		}
		return [$rOk, count($rIDs) - count($rOk)];
	}
}
