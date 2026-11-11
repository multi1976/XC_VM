<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use XcVm\Core\Events\EventDispatcher;
use XcVm\Core\Events\Stream\StreamsChangedEvent;
use XcVm\Infrastructure\Database\DatabaseFactory;
use XcVm\Module\SourceHealth\SourceActions;
use XcVm\Module\SourceHealth\SourceHealthRepository;
use XcVm\Tests\Support\InstallSchema;

/**
 * Deleting sources that are off, or moving them to the end of their stream:
 * what is refused, what is written, and that it can all be put back.
 */
final class SourceActionsTest extends TestCase {
	private const A = 'http://a.example/live/u/p/1.ts';
	private const B = 'http://b.example/live/u/p/2.ts';
	private const C = 'http://c.example/live/u/p/3.ts';
	private const D = 'http://d.example/live/u/p/4.ts';

	public static function setUpBeforeClass(): void {
		$rDir = glob(dirname(__DIR__, 2) . '/src/Modules/source-health_*')[0] ?? null;
		if ($rDir === null) {
			self::markTestSkipped('The source-health module is not installed in this tree.');
		}
		foreach (['SourceFingerprint', 'SourceHealthCalculator', 'SourceHealthRepository', 'SourceProber', 'SourceHealthTab', 'ProviderAccount', 'ProviderImport', 'SourceAudit', 'SourceActions'] as $rClass) {
			require_once $rDir . '/' . $rClass . '.php';
		}
	}

	private static function stream(int $rID, array $rSources): array {
		return ['id' => $rID, 'name' => 'S' . $rID, 'sources' => $rSources];
	}

	private static function want(int $rID, string ...$rSources): array {
		return [$rID => array_fill_keys(array_map('md5', $rSources), true)];
	}

	public function testWhatIsRefused(): void {
		$rNow = 1_000_000;
		$rState = [
			md5(self::A) => ['status' => 'dead', 'checked_at' => $rNow - 3600],
			md5(self::B) => ['status' => 'down', 'checked_at' => $rNow - 3600],
			md5(self::C) => ['status' => 'dead', 'checked_at' => $rNow - 2 * 86400],
			md5(self::D) => ['status' => 'ok', 'checked_at' => $rNow - 60],
		];
		$rStreams = [self::stream(1, [self::A, self::B, self::C, self::D])];
		$rDelete = SourceActions::plan('delete', $rStreams, self::want(1, self::A, self::B, self::C, self::D), $rState, [self::D], $rNow);
		$this->assertSame([[self::B, self::C, self::D]], array_column($rDelete['changes'], 'after'));           // only A: dead and fresh
		$this->assertSame([['source' => self::A, 'position' => 0]], $rDelete['changes'][0]['moved']);
		$rWhy = array_column($rDelete['refused'], 'why', 'source');
		$this->assertStringStartsWith('not dead (down)', $rWhy[self::B]);
		$this->assertStringContainsString('over 24 h old', $rWhy[self::C]);
		$this->assertSame('on air', $rWhy[self::D]);

		$rMove = SourceActions::plan('move_end', $rStreams, self::want(1, self::A, self::B), $rState, [self::D], $rNow);
		$this->assertSame([self::C, self::D, self::A, self::B], $rMove['changes'][0]['after']);                  // dead and down both move, in their order

		$rOnly = SourceActions::plan('delete', [self::stream(2, [self::A])], self::want(2, self::A), $rState, [], $rNow);
		$this->assertSame([], $rOnly['changes']);
		$this->assertSame('the stream would have no source left', $rOnly['refused'][0]['why']);

		$rLast = SourceActions::plan('move_end', [self::stream(3, [self::D, self::A])], self::want(3, self::A), $rState, [], $rNow);
		$this->assertSame([], $rLast['changes']);
		$this->assertSame('already at the end', $rLast['refused'][0]['why']);
		$this->assertSame('not checked yet', SourceActions::plan('move_end', [self::stream(4, ['http://new.example/1.ts', self::D])], self::want(4, 'http://new.example/1.ts'), $rState, [], $rNow)['refused'][0]['why']);
	}

	public function testApplyWritesTellsTheNodesAndUndoPutsItBack(): void {
		$rDb = new TestDb();
		foreach (['streams', 'streams_servers'] as $rTable) {
			$rDb->exec(InstallSchema::table($rTable));
		}
		$rSchema = (string) file_get_contents(glob(dirname(__DIR__, 2) . '/src/Modules/source-health_*')[0] . '/database.sql');
		foreach (array_filter(array_map('trim', explode(';', $rSchema))) as $rStatement) {
			$rDb->exec($rStatement);
		}
		DatabaseFactory::set($rDb);
		$rEvents = [];
		$rListener = new class ($rEvents) {
			public function __construct(public array &$rSeen) {
			}

			#[\XcVm\Core\Events\ListensTo(StreamsChangedEvent::class)]
			public function on(StreamsChangedEvent $e): void {
				$this->rSeen[] = $e->streamIds;
			}
		};
		EventDispatcher::subscribe($rListener);
		try {
			$rDb->query("INSERT INTO `streams` (`id`, `type`, `stream_display_name`, `stream_source`) VALUES (1, 1, 'One', ?), (2, 1, 'Two', ?), (3, 1, 'Three', ?)",
				json_encode([self::A, self::B, self::C]), json_encode([self::A, self::D]), json_encode([self::C]));
			$rDb->query("INSERT INTO `streams_servers` (`stream_id`, `server_id`, `pid`, `stream_status`, `current_source`) VALUES (1, 1, 10, 0, ?)", self::B);
			$rNow = time();
			$rVerdict = static fn (string $s): array => ['status' => $s, 'kind' => null, 'reason' => '', 'fails' => 3, 'first_fail' => $rNow - 86400, 'last_ok' => 0];
			SourceHealthRepository::saveAudit(md5(self::A), 'a.example', $rVerdict('dead'), null, $rNow - 60);
			SourceHealthRepository::saveAudit(md5(self::C), 'c.example', $rVerdict('dead'), null, $rNow - 60);
			$rSources = static fn (int $rID): array => json_decode((string) $rDb->fetchValue('SELECT `stream_source` FROM `streams` WHERE `id` = ?', $rID), true);

			// Delete everything dead on a.example and c.example: stream 3 keeps its only source.
			$rA = SourceActions::apply('delete', [[1, md5(self::A)], [2, md5(self::A)], [1, md5(self::C)], [3, md5(self::C)]], '', 7, $rNow);
			$this->assertSame([2, 3], [$rA['streams'], $rA['sources']]);
			$this->assertSame(['the stream would have no source left'], array_column($rA['refused'], 'why'));
			$this->assertSame([[self::B], [self::D], [self::C]], [$rSources(1), $rSources(2), $rSources(3)]);
			$this->assertSame([[1], [2]], $rListener->rSeen);

			// Move to end by provider host, then undo both, newest first.
			$rDb->query('UPDATE `streams` SET `stream_source` = ? WHERE `id` = 2', json_encode([self::A, self::D]));
			$rM = SourceActions::apply('move_end', [], 'a.example', 7, $rNow);
			$this->assertSame([[self::D, self::A]], [$rSources(2)]);
			$this->assertSame(1, $rM['sources']);
			$this->assertSame(['streams' => 1, 'sources' => 1], SourceActions::undo($rM['batch'], $rNow));
			$this->assertSame([self::A, self::D], $rSources(2));

			$this->assertSame(['streams' => 1, 'sources' => 2], SourceActions::undo($rA['batch'], $rNow));          // stream 2 has A again already
			$this->assertSame([[self::A, self::B, self::C], [self::A, self::D]], [$rSources(1), $rSources(2)]);  // each back in its place, A not doubled in 2
			$this->assertSame(['streams' => 0, 'sources' => 0], SourceActions::undo($rA['batch'], $rNow));        // once only

			$rHistory = SourceActions::history($rNow);
			$this->assertSame(['move_end', 'delete'], array_column($rHistory, 'action'));
			$this->assertNotNull($rHistory[1]['undone_at']);
			$this->assertSame(7, $rHistory[0]['user_id']);

			// Entries older than the undo window are forgotten.
			$this->assertSame([], SourceActions::history($rNow + SourceActions::UNDO_SEC + 10));
		} finally {
			EventDispatcher::clear();
			DatabaseFactory::reset();
		}
	}
}
