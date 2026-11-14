<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use XcVm\Core\Events\EventDispatcher;
use XcVm\Core\Events\Stream\StreamsChangedEvent;
use XcVm\Infrastructure\Database\DatabaseFactory;
use XcVm\Module\SourceHealth\SourceActions;
use XcVm\Module\SourceHealth\SourceAuditPage;
use XcVm\Module\SourceHealth\SourceFinder;
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
		foreach (['SourceFingerprint', 'SourceHealthCalculator', 'SourceHealthRepository', 'SourceProber', 'SourceHealthTab', 'ProviderAccount', 'ProviderImport', 'SourceAudit', 'SourceActions', 'SourceFinder', 'StreamHealth', 'SourceAuditPage'] as $rClass) {
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

	public function testFindSourcesOffersAndAddsProviderChannelsAndUndoTakesThemOut(): void {
		$rP = ['id' => 4, 'name' => 'Prov', 'host' => 'p.example', 'ip' => 'p.example', 'port' => 8080, 'user' => 'u', 'pass' => 'pw', 'ssl' => false, 'hls' => false, 'legacy' => true, 'max' => 10, 'active' => 2, 'exp' => null];
		$rQ = ['id' => 5, 'name' => 'Full', 'host' => 'q.example', 'ip' => 'q.example', 'port' => 80, 'user' => 'v', 'pass' => 'pw', 'ssl' => true, 'hls' => false, 'legacy' => false, 'max' => 1, 'active' => 1, 'exp' => null];
		$this->assertSame('http://p.example:8080/live/u/pw/77.ts', SourceFinder::url($rP, 77));
		$this->assertSame(SourceFinder::identity('http://p.example:8080/live/u/pw/77.ts'), SourceFinder::identity('http://p.example:8080/u/pw/77'));

		$rChannels = ['tring sport 2' => [
			['provider' => 5, 'channel' => 9, 'name' => 'AL: Tring Sport 2'],
			['provider' => 4, 'channel' => 77, 'name' => 'Tring Sport 2 HD'],
			['provider' => 4, 'channel' => 78, 'name' => 'TRING SPORT 2'],
			['provider' => 9, 'channel' => 1, 'name' => 'Tring Sport 2'],           // a provider that is disabled
		]];
		$rStreams = [['id' => 1, 'name' => 'Tring Sport 2', 'sources' => ['http://p.example:8080/u/pw/78']], ['id' => 2, 'name' => 'Sport 2', 'sources' => []]];
		$rFound = SourceFinder::candidates($rStreams, $rChannels, [4 => $rP, 5 => $rQ], [], [], ['q.example' => 0.9, 'p.example' => 0.4], time());
		$this->assertSame([1], array_keys($rFound));                                                       // "Sport 2" is not "Tring Sport 2"
		$this->assertSame([[5, 9], [4, 77]], array_map(static fn (array $c): array => [$c['provider'], $c['channel']], $rFound[1]));   // 78 it has; 77 is on its own account: after a full but independent one
		$rDead = SourceFinder::candidates($rStreams, $rChannels, [4 => $rP], [md5('http://p.example:8080/live/u/pw/77.ts') => ['status' => 'dead']], [], [], time());
		$this->assertSame([], $rDead);

		// One login on two domains is one account: offered, marked, ranked last; one channel of it offered once.
		$rM = ['id' => 6, 'name' => 'Max', 'host' => 'my.max.example', 'ip' => 'my.max.example', 'port' => 8080, 'user' => 'world', 'pass' => 'pw', 'ssl' => false, 'hls' => false, 'legacy' => true, 'max' => 80, 'active' => 10, 'exp' => null];
		$rC = ['id' => 7, 'name' => 'Cyber', 'host' => 'cyber.example', 'ip' => 'cyber.example', 'port' => 8080, 'user' => 'World', 'pass' => 'pw', 'ssl' => false, 'hls' => false, 'legacy' => true, 'max' => 80, 'active' => 10, 'exp' => null];
		$rD = ['id' => 8, 'name' => 'Cyber2', 'host' => 'cyber2.example', 'ip' => 'cyber2.example', 'port' => 80, 'user' => 'world', 'pass' => 'pw', 'ssl' => false, 'hls' => false, 'legacy' => true, 'max' => 80, 'active' => 10, 'exp' => null];
		$rOwn = ['klan news' => [
			['provider' => 7, 'channel' => 1139, 'name' => 'Klan News'], ['provider' => 8, 'channel' => 1139, 'name' => 'Klan News'],
			['provider' => 6, 'channel' => 2250, 'name' => 'Klan News HD'], ['provider' => 4, 'channel' => 5, 'name' => 'Klan News'],
		]];
		$rFound = SourceFinder::candidates([['id' => 3, 'name' => 'Klan News', 'sources' => [self::A, 'http://my.max.example:8080/live/world/pw/1139.ts']]], $rOwn, [4 => $rP, 6 => $rM, 7 => $rC, 8 => $rD], [], [], [], time());
		$this->assertSame([4, ''], [$rFound[3][0]['provider'], $rFound[3][0]['same']]);                    // the independent one first
		$rSame = array_column(array_slice($rFound[3], 1), 'same', 'provider');
		ksort($rSame);
		$this->assertSame([6 => 'same account as source #2 (another channel id): shares its connection limit', 7 => 'same account as source #2 on another domain or port: shares its connection limit and falls with it'], $rSame);

		$rDb = new TestDb();
		foreach (['streams', 'streams_servers', 'providers', 'providers_streams'] as $rTable) {
			$rDb->exec(InstallSchema::table($rTable));
		}
		foreach (array_filter(array_map('trim', explode(';', (string) file_get_contents(glob(dirname(__DIR__, 2) . '/src/Modules/source-health_*')[0] . '/database.sql')))) as $rStatement) {
			$rDb->exec($rStatement);
		}
		DatabaseFactory::set($rDb);
		try {
			$rNow = time();
			$rDb->query("INSERT INTO `providers` (`id`, `name`, `ip`, `port`, `username`, `password`, `data`, `enabled`, `status`, `ssl`, `hls`, `legacy`) VALUES (4, 'Prov', 'p.example', 8080, 'u', 'pw', ?, 1, 1, 0, 0, 1)",
				json_encode(['max_connections' => 10, 'active_connections' => 2, 'streams' => 2]));
			$rDb->query("INSERT INTO `providers_streams` (`provider_id`, `stream_id`, `stream_display_name`, `type`) VALUES (4, 77, 'Tring Sport 2 HD', 'live'), (4, 50, 'News 24', 'live')");
			$rDb->query("INSERT INTO `streams` (`id`, `type`, `stream_display_name`, `stream_source`) VALUES (1, 1, 'Tring Sport 2', ?), (2, 1, 'News 24', ?)",
				json_encode([self::A]), json_encode([self::B]));
			$rDb->query("INSERT INTO `streams_servers` (`stream_id`, `server_id`, `pid`, `stream_status`, `current_source`) VALUES (2, 1, 10, 0, ?)", self::B);
			SourceHealthRepository::saveAudit(md5(self::A), 'a.example', ['status' => 'dead', 'kind' => null, 'reason' => '', 'fails' => 3, 'first_fail' => $rNow - 86400, 'last_ok' => 0], null, $rNow - 60);
			$rSources = static fn (int $rID): array => json_decode((string) $rDb->fetchValue('SELECT `stream_source` FROM `streams` WHERE `id` = ?', $rID), true);

			$rPage = SourceAuditPage::render(['view' => 'find', 'level' => 'problems'], $rNow);
			$this->assertStringContainsString('Tring Sport 2 HD', $rPage);
			$this->assertStringContainsString('data-item="[1,4,77]"', $rPage);
			$this->assertStringNotContainsString('pw', $rPage);                                               // no account in the page

			// Nothing in stream 1 works: the new source goes first. Stream 2 is on air: it goes last.
			$rDb->query("INSERT INTO `streams` (`id`, `type`, `stream_display_name`, `stream_source`) VALUES (3, 1, 'Twice', ?)", json_encode(['http://x.example/live/me/pw/1.ts', 'http://y.example:8080/me/pw/1']));
			$this->assertStringContainsString('one account: #1 #2', SourceAuditPage::render(['view' => 'streams', 'level' => 'all'], $rNow));
			$rDb->query('DELETE FROM `streams` WHERE `id` = 3');
			$rAdd = SourceActions::add([[1, 4, 77], [2, 4, 50], [1, 4, 77], [2, 4, 999]], 7, $rNow);
			$this->assertSame([2, 2], [$rAdd['streams'], $rAdd['sources']]);
			$this->assertSame(['the stream has this channel already', 'the provider or its channel is no longer listed'], array_column($rAdd['refused'], 'why'));
			$this->assertSame(['http://p.example:8080/live/u/pw/77.ts', self::A], $rSources(1));
			$this->assertSame([self::B, 'http://p.example:8080/live/u/pw/50.ts'], $rSources(2));
			$this->assertSame('add', SourceActions::history($rNow)[0]['action']);
			$this->assertSame(['streams' => 2, 'sources' => 2], SourceActions::undo($rAdd['batch'], $rNow));
			$this->assertSame([[self::A], [self::B]], [$rSources(1), $rSources(2)]);
		} finally {
			EventDispatcher::clear();
			DatabaseFactory::reset();
		}
	}
}
