<?php

namespace XcVm\Module\LineAudit;

use XcVm\Cli\CommandInterface;
use XcVm\Cli\CronTrait;
use XcVm\Core\Cluster\NodeRole;

/**
 * `cron:line_audit` — every five minutes, MAIN only: record how the lines with
 * two connections or more (or at their limit) are connected, and forget
 * samples older than a week. Runs as xc_vm; started as root (from root's
 * crontab) it re-runs itself as xc_vm.
 */
class LineAuditCronJob implements CommandInterface {
	use CronTrait;

	public function getName(): string {
		return 'cron:line_audit';
	}

	public function getDescription(): string {
		return 'Cron: sample the live connections of user lines (Line Audit module)';
	}

	public function execute(array $rArgs): int {
		if ((posix_getpwuid(posix_geteuid())['name'] ?? null) === 'root') {
			passthru('sudo -u xc_vm ' . escapeshellarg(PHP_BIN) . ' ' . escapeshellarg(MAIN_HOME . 'console.php') . ' ' . $this->getName(), $rCode);
			return (int) $rCode;
		}
		if (!$this->assertRunAsXcVm()) {
			return 1;
		}
		if (!NodeRole::isMain()) {
			return 0;
		}
		$this->initCron('XC_VM[Line Audit]');
		self::sample(time());
		return 0;
	}

	public static function sample(int $rNow): int {
		$rConnections = LineAuditRepository::liveConnections();
		$rSamples = LineAuditAnalyzer::samples($rConnections, LineAuditRepository::lines(array_column($rConnections, 'user_id')), $rNow);
		LineAuditRepository::saveSamples($rSamples);
		LineAuditRepository::pruneSamples($rNow);
		return count($rSamples);
	}
}
