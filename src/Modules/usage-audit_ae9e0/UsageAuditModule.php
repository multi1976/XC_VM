<?php

namespace XcVm\Module\UsageAudit;

use XcVm\Core\Auth\Authorization;
use XcVm\Core\Http\Router;
use XcVm\Core\Module\BaseModule;
use XcVm\Core\Module\NavbarItem;
use XcVm\Core\Module\NavbarRegistry;

/**
 * Usage Audit: what is watched and what is not (Logs → Streams → Usage Audit).
 * Read-only: it reads the activity log, the live connections and the stream
 * tables, and keeps nothing of its own.
 */
class UsageAuditModule extends BaseModule {
	public function getName(): string {
		return 'usage-audit';
	}

	public function getVersion(): string {
		return '0.1.0';
	}

	public function registerRoutes(Router $router): void {
		$router->get('usage_audit', [UsageAuditController::class, 'page'], ['permission' => static fn (): bool => Authorization::check('adv', 'streams')]);
	}

	public function registerNavbar(NavbarRegistry $registry): void {
		NavbarRegistry::add((new NavbarItem('logs.streams.usage'))
			->parent('logs.streams')->url('usage_audit')
			->label('', 'Usage Audit')->permissions(['streams'])->order(25));
	}
}
