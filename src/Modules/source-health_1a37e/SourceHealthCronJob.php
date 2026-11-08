<?php

namespace XcVm\Module\SourceHealth;

use XcVm\Cli\CommandInterface;
use XcVm\Cli\CronTrait;
use XcVm\Core\Cluster\NodeRole;

/**
 * `cron:source_health` — CLI wrapper of SourceHealthCron (every 5 minutes, MAIN only).
 * Runs as xc_vm; started as root (from root's crontab) it re-runs itself as xc_vm.
 */
class SourceHealthCronJob implements CommandInterface {
	use CronTrait;

	public function getName(): string {
		return 'cron:source_health';
	}

	public function getDescription(): string {
		return 'Cron: record the fingerprint of every source on air (Source Health module)';
	}

	public function execute(array $rArgs): int {
		// Module cron lines land in root's crontab (StartupCommand::installRootCrontab()
		// writes them without `sudo -u`), so a root run hands itself over to the
		// panel user instead of refusing.
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
		$this->initCron('XC_VM[Source Health]');
		SourceHealthCron::run(time());
		return 0;
	}
}
