<?php

namespace XcVm\Module\ChannelAudit;

use XcVm\Cli\CommandInterface;
use XcVm\Core\Cluster\NodeRole;

/**
 * `channel_audit <job id>` — runs one audit in the background (started by the
 * Channel Audit page). MAIN only, as xc_vm.
 */
class ChannelAuditCommand implements CommandInterface {
	public function getName(): string {
		return 'channel_audit';
	}

	public function getDescription(): string {
		return 'Check the video files of a folder or created channel against a standard (Channel Audit module)';
	}

	public function execute(array $rArgs): int {
		if ((posix_getpwuid(posix_geteuid())['name'] ?? null) !== 'xc_vm') {
			echo "Please run as XC_VM!\n";
			return 1;
		}
		if (!NodeRole::isMain() || empty($rArgs[0]) || !ctype_digit((string) $rArgs[0])) {
			return 1;
		}
		cli_set_process_title('XC_VMChannelAudit[' . (int) $rArgs[0] . ']');
		set_time_limit(0);
		return AuditRunner::run((int) $rArgs[0]);
	}
}
