<?php

namespace XcVm\Module\SourceHealth;

use XcVm\Core\Http\RequestManager;
use XcVm\Public\Controllers\Admin\Ajax\BaseAjaxController;

/**
 * `api?action=source_health_probe` — turn active probes of one stream's
 * sources on or off (the switch on the Source Health tab).
 */
class SourceHealthController extends BaseAjaxController {
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
