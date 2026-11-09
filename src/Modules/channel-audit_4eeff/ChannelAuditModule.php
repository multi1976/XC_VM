<?php

namespace XcVm\Module\ChannelAudit;

use XcVm\Cli\CommandRegistry;
use XcVm\Core\Http\Router;
use XcVm\Core\Module\BaseModule;
use XcVm\Core\Module\NavbarItem;
use XcVm\Core\Module\NavbarRegistry;

/**
 * Channel Audit — checks, file by file and strictly, whether the videos a
 * created channel is (or will be) built from share one exact format, so the
 * admin knows before building whether they can be joined by stream copy or
 * must be normalized first. It reads files and reports; it changes nothing.
 */
class ChannelAuditModule extends BaseModule {
	public function getName(): string {
		return 'channel-audit';
	}

	public function getVersion(): string {
		return '0.1.0';
	}

	public function registerRoutes(Router $router): void {
		$rPermission = static fn (): bool => \XcVm\Core\Auth\Authorization::check('adv', 'create_channel') || \XcVm\Core\Auth\Authorization::check('adv', 'edit_cchannel');
		$router->get('channel_audit', [ChannelAuditController::class, 'index'], ['permission' => $rPermission]);
		$router->api('channel_audit_standard_save', [ChannelAuditController::class, 'saveStandard']);
		$router->api('channel_audit_standard_delete', [ChannelAuditController::class, 'deleteStandard']);
		$router->api('channel_audit_start', [ChannelAuditController::class, 'start']);
		$router->api('channel_audit_jobs', [ChannelAuditController::class, 'jobs']);
		$router->api('channel_audit_report', [ChannelAuditController::class, 'report']);
		$router->api('channel_audit_cancel', [ChannelAuditController::class, 'cancel']);
		$router->api('channel_audit_delete', [ChannelAuditController::class, 'delete']);
	}

	public function registerCommands(CommandRegistry $registry): void {
		$registry->register(new ChannelAuditCommand());
	}

	public function registerNavbar(NavbarRegistry $registry): void {
		NavbarRegistry::add((new NavbarItem('content.channels.audit'))
			->parent('content.channels')->url('channel_audit')
			->label('', 'Channel Audit')->permissions(['create_channel', 'edit_cchannel'])->order(40));
	}
}
