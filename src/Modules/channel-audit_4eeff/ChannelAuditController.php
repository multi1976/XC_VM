<?php

namespace XcVm\Module\ChannelAudit;

use XcVm\Core\Auth\Authorization;
use XcVm\Core\Http\RequestManager;
use XcVm\Core\Localization\Translator;
use XcVm\Core\Util\AdminHelpers;
use XcVm\Core\Util\LayoutRenderer;
use XcVm\Public\Controllers\Admin\Ajax\BaseAjaxController;

/**
 * The Channel Audit page and its AJAX actions (`api?action=channel_audit_*`).
 * Everyone who may create or edit a created channel may audit one.
 */
class ChannelAuditController extends BaseAjaxController {
	private const PERMISSIONS = [['adv', 'create_channel'], ['adv', 'edit_cchannel']];

	public function index(): void {
		if (!Authorization::check('adv', 'create_channel') && !Authorization::check('adv', 'edit_cchannel')) {
			AdminHelpers::goHome();
			exit;
		}
		$GLOBALS['_TITLE'] = 'Channel Audit';
		$language = Translator::class;
		$rServers = $GLOBALS['rServers'] ?? [];
		$rStandards = AuditRepository::standards();
		$rChannels = AuditRepository::createdChannels();
		$rDefaults = AuditStandard::DEFAULTS;
		$rMainID = (int) SERVER_ID;

		LayoutRenderer::renderHeader('admin');
		require __DIR__ . '/views/channel_audit.php';
	}

	// ---- standards ----

	public function saveStandard(): never {
		$this->guard(true);
		$rName = trim((string) RequestManager::get('name', ''));
		[$rSpec, $rErrors] = AuditStandard::normalize((array) RequestManager::getAll());
		if ($rName === '' || mb_strlen($rName) > 100) {
			$rErrors[] = 'name must be 1–100 characters';
		}
		if ($rErrors !== []) {
			$this->fail(['errors' => $rErrors]);
		}
		$rID = AuditRepository::saveStandard((int) RequestManager::get('id', 0), $rName, $rSpec);
		$this->ok(['id' => $rID, 'standards' => AuditRepository::standards()]);
	}

	public function deleteStandard(): never {
		$this->guard(true);
		AuditRepository::deleteStandard((int) RequestManager::get('id', 0));
		$this->ok(['standards' => AuditRepository::standards()]);
	}

	// ---- audits ----

	public function start(): never {
		$this->guard(true);
		$rStandard = AuditRepository::standard((int) RequestManager::get('standard_id', 0));
		if ($rStandard === null) {
			$this->fail(['errors' => ['choose a standard']]);
		}
		$rJob = [
			'standard_name' => $rStandard['name'],
			'spec'          => json_encode($rStandard['spec']),
			'deep'          => RequestManager::get('deep') ? 1 : 0,
			'created_by'    => (int) ($GLOBALS['rUserInfo']['id'] ?? 0) ?: null,
		];

		if (RequestManager::get('kind') === 'channel') {
			$rStreamID = (int) RequestManager::get('stream_id', 0);
			$rChannel = AuditRepository::createdChannel($rStreamID);
			if ($rChannel === null || $rChannel['sources'] === []) {
				$this->fail(['errors' => ['choose a created channel that has sources']]);
			}
			$rJob += ['kind' => 'channel', 'stream_id' => $rStreamID, 'label' => $rChannel['name'], 'total' => count($rChannel['sources'])];
		} else {
			$rServerID = (int) RequestManager::get('server_id', SERVER_ID);
			$rDir = (string) RequestManager::get('folder', '');
			try {
				$rFiles = SourceResolver::folder($rServerID, $rDir);
			} catch (\Throwable $e) {
				$this->fail(['errors' => [$e->getMessage()]]);
			}
			$rDir = '/' . trim($rDir, '/');
			$rJob += ['kind' => 'folder', 'server_id' => $rServerID, 'target' => $rDir, 'label' => $rDir, 'total' => count($rFiles)];
		}

		$rID = AuditRepository::createJob($rJob);
		shell_exec(PHP_BIN . ' ' . MAIN_HOME . 'console.php channel_audit ' . (int) $rID . ' >/dev/null 2>&1 &');
		$this->ok(['id' => $rID]);
	}

	public function jobs(): never {
		$this->guard(false);
		$rJobs = AuditRepository::jobs();
		foreach ($rJobs as &$rJob) {
			$rJob = self::reapIfDead($rJob);
			unset($rJob['pid']);
		}
		unset($rJob);
		$this->ok(['jobs' => $rJobs]);
	}

	public function report(): never {
		$this->guard(false);
		$rJob = AuditRepository::job((int) RequestManager::get('id', 0));
		if ($rJob === null) {
			$this->fail();
		}
		$rJob = self::reapIfDead($rJob);
		$rSpec = json_decode((string) $rJob['spec'], true) ?: [];
		$rJob['standard'] = AuditStandard::describe($rSpec + AuditStandard::DEFAULTS);
		unset($rJob['spec'], $rJob['pid']);
		$rItems = array_map(static function (array $rItem): array {
			$rItem['report'] = $rItem['report'] !== null ? json_decode((string) $rItem['report'], true) : null;
			$rItem['source'] = self::shownSource((string) $rItem['source']);
			return $rItem;
		}, AuditRepository::items((int) $rJob['id']));
		$this->ok(['job' => $rJob, 'items' => $rItems]);
	}

	public function cancel(): never {
		$this->guard(true);
		$rJob = AuditRepository::job((int) RequestManager::get('id', 0));
		if ($rJob !== null && in_array($rJob['status'], ['queued', 'running'], true)) {
			AuditRepository::updateJob((int) $rJob['id'], ['status' => 'cancelled', 'finished_at' => time()]);
			// The runner sees the status within 2 s and kills its ffprobe/ffmpeg itself.
		}
		$this->ok();
	}

	public function delete(): never {
		$this->guard(true);
		$rJob = AuditRepository::job((int) RequestManager::get('id', 0));
		if ($rJob !== null && !in_array($rJob['status'], ['queued', 'running'], true)) {
			AuditRepository::deleteJob((int) $rJob['id']);
		}
		$this->ok();
	}

	private function guard(bool $rPost): void {
		$this->requireXhr();
		$this->gateAny(self::PERMISSIONS);
		if ($rPost && ($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
			$this->fail();
		}
	}

	/** A job left "running" by a process that is gone (server restart, kill) is marked failed. */
	private static function reapIfDead(array $rJob): array {
		if (in_array($rJob['status'], ['running', 'queued'], true)) {
			$rPid = (int) ($rJob['pid'] ?? 0);
			$rStale = $rPid > 0 ? !posix_kill($rPid, 0) : ($rJob['status'] === 'queued' && time() - (int) $rJob['created_at'] > 120);
			if ($rStale) {
				AuditRepository::updateJob((int) $rJob['id'], ['status' => 'failed', 'error' => 'the audit process stopped', 'pid' => null, 'finished_at' => time()]);
				$rJob['status'] = 'failed';
				$rJob['error'] = 'the audit process stopped';
			}
		}
		return $rJob;
	}

	/** `s:1:/srv/media/a.mp4` → `/srv/media/a.mp4 (server #1)` */
	private static function shownSource(string $rSource): string {
		if (strncmp($rSource, 's:', 2) === 0) {
			$rParts = explode(':', $rSource, 3);
			return ($rParts[2] ?? '') . ' (server #' . (int) $rParts[1] . ')';
		}
		return $rSource;
	}
}
