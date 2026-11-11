<?php

namespace XcVm\Module\SourceHealth;

use XcVm\Cli\CommandRegistry;
use XcVm\Core\Container\ServiceContainer;
use XcVm\Core\Events\ListensTo;
use XcVm\Core\Http\Router;
use XcVm\Core\Events\Stream\StreamsDeletedEvent;
use XcVm\Core\Module\BaseModule;
use XcVm\Core\Module\NavbarItem;
use XcVm\Core\Module\NavbarRegistry;
use XcVm\Core\Module\StreamFormRegistry;

/**
 * Source Health — phase 1 of smarter source selection: measure first.
 *
 * Adds a read-only "Source Health" tab to the Edit Stream page and a cron that
 * records the output profile of every source while it is on air. It reads core
 * tables and changes nothing about how streams run.
 */
class SourceHealthModule extends BaseModule {
	public function getName(): string {
		return 'source-health';
	}

	public function getVersion(): string {
		return '0.3.1';
	}

	public function boot(ServiceContainer $container): void {
		StreamFormRegistry::add(
			'source-health',
			'Source Health',
			static fn (?array $rStream, string $rMode): string => SourceHealthTab::render($rStream, $rMode)
		);
	}

	public function registerRoutes(Router $router): void {
		$router->get('source_health', [SourceHealthController::class, 'page'], ['permission' => static fn (): bool => \XcVm\Core\Auth\Authorization::check('adv', 'edit_stream')]);
		$router->api('source_health_probe', [SourceHealthController::class, 'probe']);
		$router->api('source_audit_recheck', [SourceHealthController::class, 'recheck']);
		$router->get('source_audit', [SourceHealthController::class, 'audit'], ['permission' => static fn (): bool => \XcVm\Core\Auth\Authorization::check('adv', 'streams')]);
	}

	public function registerNavbar(NavbarRegistry $registry): void {
		NavbarRegistry::add((new NavbarItem('content.streams.source_audit'))
			->parent('content.streams')->url('source_audit')
			->label('', 'Source Audit')->permissions(['streams'])->order(45));
	}

	public function registerCommands(CommandRegistry $registry): void {
		$registry->register(new SourceHealthCronJob());
	}

	public function getCronEntries(): array {
		return ['*/5 * * * *' => 'cron:source_health'];
	}

	#[ListensTo(StreamsDeletedEvent::class)]
	public function onStreamsDeleted(StreamsDeletedEvent $rEvent): void {
		SourceHealthRepository::deleteForStreams($rEvent->streamIds);
	}
}
