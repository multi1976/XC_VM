<?php

namespace XcVm\Module\SourceHealth;

use XcVm\Cli\CommandInterface;
use XcVm\Cli\CronTrait;
use XcVm\Core\Cluster\NodeRole;

/**
 * `cron:source_health` — CLI wrapper of SourceHealthCron (every 5 minutes, MAIN only).
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
