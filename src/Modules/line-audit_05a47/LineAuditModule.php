<?php

namespace XcVm\Module\LineAudit;

use XcVm\Cli\CommandRegistry;
use XcVm\Core\Auth\Authorization;
use XcVm\Core\Http\Router;
use XcVm\Core\Module\BaseModule;
use XcVm\Core\Module\NavbarItem;
use XcVm\Core\Module\NavbarRegistry;

/**
 * Line Audit: shared accounts, connection limits, refused connections,
 * expiring and unused lines, unstable clients (Users → Lines → Line Audit).
 * Read-only on core data; a cron samples the live connections every five
 * minutes, since the panel keeps no history of who was connected at once.
 */
class LineAuditModule extends BaseModule {
	public function getName(): string {
		return 'line-audit';
	}

	public function getVersion(): string {
		return '0.4.0';
	}

	public function registerRoutes(Router $router): void {
		$router->get('line_audit', [LineAuditController::class, 'page'], ['permission' => static fn (): bool => Authorization::check('adv', 'users')]);
		$router->api('line_audit_delete', [LineAuditController::class, 'delete']);
	}

	public function registerCommands(CommandRegistry $registry): void {
		$registry->register(new LineAuditCronJob());
	}

	public function registerNavbar(NavbarRegistry $registry): void {
		NavbarRegistry::add((new NavbarItem('lines.audit'))
			->parent('lines')->url('line_audit')
			->label('', 'Line Audit')->permissions(['users'])->order(40));
	}

	/** @return array<string, string> */
	public function getCronEntries(): array {
		return ['*/5 * * * *' => 'cron:line_audit'];
	}
}
