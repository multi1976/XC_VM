<?php

namespace XcVm\Module\UsageAudit;

/**
 * The Usage Audit page: what is watched (channels, movies, series,
 * categories, the hours of the day) and what is not, over the last 24 hours,
 * 7 or 30 days. Read-only: it lists, the panel's own pages decide.
 */
final class UsageAuditPage {
	private const MAX_ROWS = 500;
	private const DAYS = [1 => 'Last 24 hours', 7 => 'Last 7 days', 30 => 'Last 30 days'];
	private const TABS = ['overview' => 'Overview', 'live' => 'Channels', 'movies' => 'Movies', 'series' => 'Series', 'categories' => 'Categories', 'never' => 'Not watched'];
	private const KINDS = ['live' => 'Channels and radio', 'movies' => 'Movies', 'series' => 'Series'];

	/** Category types each list is filtered by. */
	private const CATEGORY_TYPES = ['live' => ['live', 'radio'], 'movies' => ['movie'], 'series' => ['series']];

	/** @param array<string, mixed> $rQuery GET parameters: view, days, kind, q, cat (list of category ids) */
	public static function render(array $rQuery, int $rNow): string {
		$h = static fn ($v): string => htmlspecialchars((string) $v, ENT_QUOTES);
		$rView = isset(self::TABS[$rQuery['view'] ?? '']) ? (string) $rQuery['view'] : 'overview';
		$rDays = (int) ($rQuery['days'] ?? 7);
		$rDays = isset(self::DAYS[$rDays]) ? $rDays : 7;
		$rKind = isset(self::KINDS[$rQuery['kind'] ?? '']) ? (string) $rQuery['kind'] : 'live';
		$rSearch = mb_substr(trim((string) ($rQuery['q'] ?? '')), 0, 64);
		$rCats = array_slice(array_values(array_unique(array_filter(array_map('intval', (array) ($rQuery['cat'] ?? [])), static fn (int $v): bool => $v > 0))), 0, 200);
		$rFrom = $rNow - $rDays * 86400;

		// Category separators ("=== NEWS ===", a stream that only heads a group in
		// old players) are no channel: left out of every figure and list.
		$rStreams = UsageAuditRepository::streams();
		$rSeparators = array_filter($rStreams, static fn (array $s): bool => UsageAuditAnalyzer::isSeparator($s['name']));
		$rStreams = array_diff_key($rStreams, $rSeparators);
		$rSeries = UsageAuditRepository::series();
		$rGroupOf = [];
		foreach ($rSeries['episodes'] as $rStreamID => $rSeriesID) {
			$rGroupOf[$rStreamID] = 's' . $rSeriesID;
		}
		$rRead = UsageAuditRepository::sessions($rFrom, $rNow);
		if ($rSeparators !== []) {
			$rRead['sessions'] = array_values(array_filter($rRead['sessions'], static fn (array $r): bool => !isset($rSeparators[$r[1]])));
		}
		$rRestreamers = UsageAuditRepository::restreamers();
		$rUsage = UsageAuditAnalyzer::usage($rRead['sessions'], $rFrom, $rNow, $rRestreamers, $rGroupOf);
		$rCtx = [
			'streams' => $rStreams, 'series' => $rSeries['series'], 'categories' => UsageAuditRepository::categories(), 'running' => UsageAuditRepository::running(),
			'bouquets' => UsageAuditRepository::bouquets(), 'from' => $rFrom, 'now' => $rNow, 'days' => $rDays, 'search' => $rSearch, 'kind' => $rKind, 'cats' => $rCats, 'separators' => count($rSeparators),
			'coverage' => UsageAuditRepository::coverage(), 'truncated' => $rRead['truncated'], 'restreamers' => count($rRestreamers),
		];
		$rLive = self::pick($rUsage['groups'], static fn ($k): bool => is_int($k) && in_array($rStreams[$k]['type'] ?? 0, [...UsageAuditRepository::LIVE_TYPES, UsageAuditRepository::RADIO_TYPE], true));
		$rMovies = self::pick($rUsage['groups'], static fn ($k): bool => is_int($k) && ($rStreams[$k]['type'] ?? 0) === UsageAuditRepository::MOVIE_TYPE);
		$rShows = self::pick($rUsage['groups'], static fn ($k): bool => is_string($k) && $k[0] === 's');
		$rNever = self::notWatched($rUsage['groups'], $rCtx);
		$rFilterKind = match ($rView) {
			'live', 'movies', 'series' => $rView,
			'never' => $rKind,
			default => null,
		};

		$rHtml = '<ul class="nav nav-tabs mb-3">';
		foreach (self::TABS as $rKey => $rLabel) {
			$rHtml .= '<li class="nav-item"><a class="nav-link' . ($rView === $rKey ? ' active' : '') . '" href="' . $h(self::url(['view' => $rKey, 'days' => $rDays, 'q' => $rSearch])) . '">' . $h($rLabel)
				. ($rKey === 'never' ? ' <span class="badge rounded-pill bg-label-' . (count($rNever['live']) > 0 ? 'warning' : 'secondary') . '">' . count($rNever['live']) . '</span>' : '') . '</a></li>';
		}
		$rHtml .= '</ul><form method="get" action="./usage_audit" class="d-flex flex-wrap gap-2 mb-3 align-items-center"><input type="hidden" name="view" value="' . $h($rView) . '">'
			. ($rView === 'never' ? '<input type="hidden" name="kind" value="' . $h($rKind) . '">' : '')
			. '<select name="days" class="form-select form-select-sm" style="width:auto">';
		foreach (self::DAYS as $d => $rLabel) {
			$rHtml .= '<option value="' . $d . '"' . ($rDays === $d ? ' selected' : '') . '>' . $h($rLabel) . '</option>';
		}
		$rHtml .= '</select>' . ($rFilterKind === null ? '' : self::categoryFilter($rFilterKind, $rCtx, $rSeries['series']))
			. '<input type="search" name="q" value="' . $h($rSearch) . '" class="form-control form-control-sm" style="width:220px" placeholder="Name">'
			. '<button class="btn btn-sm btn-primary" type="submit">Apply</button></form>' . self::notes($rCtx);

		return $rHtml . match ($rView) {
			'live'       => self::usageTable('Channels and radio', $rLive, $rUsage['total'], $rCtx, true),
			'movies'     => self::usageTable('Movies', $rMovies, $rUsage['total'], $rCtx, false),
			'series'     => self::usageTable('Series (all episodes together)', $rShows, $rUsage['total'], $rCtx, false),
			'categories' => self::categoriesTab($rRead['sessions'], $rRestreamers, $rCtx, $rSeries),
			'never'      => self::neverTab($rNever, $rCtx),
			default      => self::overview($rUsage, $rLive, $rMovies, $rShows, $rNever, $rCtx),
		};
	}

	/**
	 * @param array<int|string, array<string, int>> $rGroups
	 * @return array<int|string, array<string, int>>
	 */
	private static function pick(array $rGroups, callable $rKeep): array {
		return array_filter($rGroups, $rKeep, ARRAY_FILTER_USE_KEY);
	}

	/**
	 * What nobody watched in the window: channels and radio, movies, series.
	 *
	 * @param array<int|string, array<string, int>> $rGroups
	 * @param array<string, mixed>                   $rCtx
	 * @return array{live: list<int>, movies: list<int>, series: list<int>}
	 */
	private static function notWatched(array $rGroups, array $rCtx): array {
		$rOut = ['live' => [], 'movies' => [], 'series' => []];
		foreach ($rCtx['streams'] as $rID => $s) {
			if (isset($rGroups[$rID])) {
				continue;
			}
			if (in_array($s['type'], [...UsageAuditRepository::LIVE_TYPES, UsageAuditRepository::RADIO_TYPE], true)) {
				$rOut['live'][] = $rID;
			} elseif ($s['type'] === UsageAuditRepository::MOVIE_TYPE) {
				$rOut['movies'][] = $rID;
			}
		}
		foreach (array_keys($rCtx['series']) as $rID) {
			if (!isset($rGroups['s' . $rID])) {
				$rOut['series'][] = $rID;
			}
		}
		return $rOut;
	}

	/** @param array<string, mixed> $rCtx */
	private static function notes(array $rCtx): string {
		$rOut = $rCtx['separators'] > 0 ? '<p class="text-muted small mb-2">' . $rCtx['separators'] . ' category separators ("=Name=") are left out: they head a list, nobody watches them.</p>' : '';
		if ($rCtx['coverage'] === 0) {
			$rOut .= '<div class="alert alert-warning py-2">The activity log is empty: nothing has been watched yet, or the panel does not keep it.</div>';
		} elseif ($rCtx['coverage'] > $rCtx['from']) {
			$rOut .= '<div class="alert alert-warning py-2">The activity log starts on ' . date('Y-m-d H:i', $rCtx['coverage']) . ': what came before is not known, so this window is shorter than it looks.</div>';
		}
		if ($rCtx['truncated']) {
			$rOut .= '<div class="alert alert-warning py-2">More than ' . number_format(UsageAuditRepository::MAX_SESSIONS) . ' sessions in this window: only the latest are counted. Choose a shorter window for exact figures.</div>';
		}
		return $rOut;
	}

	/**
	 * @param array{groups: array<int|string, array<string, int>>, total: array<string, int>, hours: array<int, int>} $rUsage
	 * @param array<int|string, array<string, int>> $rLive
	 * @param array<int|string, array<string, int>> $rMovies
	 * @param array<int|string, array<string, int>> $rShows
	 * @param array{live: list<int>, movies: list<int>, series: list<int>} $rNever
	 * @param array<string, mixed> $rCtx
	 */
	private static function overview(array $rUsage, array $rLive, array $rMovies, array $rShows, array $rNever, array $rCtx): string {
		$h = static fn ($v): string => htmlspecialchars((string) $v, ENT_QUOTES);
		$t = $rUsage['total'];
		$rSpan = max(1, $rCtx['now'] - $rCtx['from']);
		$rHourly = UsageAuditAnalyzer::hourly($rUsage['hours'], $rCtx['from'], $rCtx['now']);
		$rBusy = $rHourly === [] || max($rHourly) <= 0 ? null : (int) array_search(max($rHourly), $rHourly, true);
		$rQuiet = $rBusy === null ? null : (int) array_search(min($rHourly), $rHourly, true);
		$rTile = static fn (string $rLabel, string $rValue, string $rSub = ''): string => '<div class="col-6 col-md-4 col-xl-2"><div class="border rounded p-2 h-100"><div class="text-muted small">' . $h($rLabel) . '</div><div class="fs-5 fw-semibold">' . $h($rValue) . '</div>'
			. ($rSub !== '' ? '<div class="text-muted small">' . $h($rSub) . '</div>' : '') . '</div></div>';
		$rHtml = '<div class="row g-2 mb-3">'
			. $rTile('Viewer-hours', number_format($t['secs'] / 3600, 1), self::DAYS[$rCtx['days']])
			. $rTile('Viewers on average', number_format($t['secs'] / $rSpan, 1), 'at any moment')
			. $rTile('Most at once', (string) $t['peak'], $t['peak_at'] > 0 ? date('D d M H:i', $t['peak_at']) : '')
			. $rTile('Lines watching', (string) $t['lines'], number_format($t['sessions']) . ' sessions')
			. $rTile('Busiest hour', $rBusy === null ? '—' : sprintf('%02d:00', $rBusy), $rBusy === null ? '' : number_format($rHourly[$rBusy], 1) . ' viewers on average')
			. $rTile('Quietest hour', $rQuiet === null ? '—' : sprintf('%02d:00', $rQuiet), $rQuiet === null ? '' : number_format($rHourly[$rQuiet], 1) . ' viewers on average')
			. '</div>';
		if ($rCtx['restreamers'] > 0) {
			$rRestreamed = count(array_filter($rUsage['groups'], static fn (array $g): bool => $g['restream'] > 0));
			$rHtml .= '<p class="text-muted small">Restreamer lines (' . $rCtx['restreamers'] . ') are not counted as viewers: each one stands for viewers this panel cannot see. They pulled ' . $rRestreamed . ' streams in this window (column "Restreamers").</p>';
		}

		// The day, hour by hour.
		$rMax = max(0.01, ...array_values($rHourly));
		$rHtml .= '<h6 class="mt-3">Viewers by hour of the day <small class="text-muted">(average over ' . $h(strtolower(self::DAYS[$rCtx['days']])) . ', panel time)</small></h6><div class="mb-4" style="max-width:640px">';
		foreach ($rHourly as $rHour => $v) {
			$rHtml .= '<div class="d-flex align-items-center small" style="height:18px"><span class="text-muted" style="width:46px">' . sprintf('%02d:00', $rHour) . '</span>'
				. '<div class="flex-grow-1 me-2"><div class="bg-primary rounded" style="height:12px;width:' . max(0.5, round($v / $rMax * 100, 1)) . '%;opacity:' . ($rHour === $rBusy ? '1' : '.6') . '"></div></div><span style="width:44px" class="text-end">' . number_format($v, 1) . '</span></div>';
		}
		$rHtml .= '</div>';

		$rHtml .= '<div class="row g-4"><div class="col-xl-6">' . self::topList('Most watched channels', $rLive, 10, 'live', $rCtx) . '</div><div class="col-xl-6">'
			. self::topList('Most watched movies', $rMovies, 5, 'movies', $rCtx) . self::topList('Most watched series', $rShows, 5, 'series', $rCtx) . '</div></div>';

		$rLiveNever = $rNever['live'];
		$rRunning = count(array_filter($rLiveNever, static fn (int $id): bool => !empty($rCtx['running'][$id]['running']) && empty($rCtx['running'][$id]['on_demand'])));
		$rHtml .= '<h6 class="mt-4">Not watched in this window</h6><ul class="mb-0">'
			. '<li><a href="' . $h(self::url(['view' => 'never', 'days' => $rCtx['days'], 'kind' => 'live'])) . '">' . count($rLiveNever) . ' channels and radio</a>'
			. ($rRunning > 0 ? ', <strong>' . $rRunning . ' of them running all the time</strong>: each one takes a connection from its provider and server resources for nobody' : '') . '</li>'
			. '<li><a href="' . $h(self::url(['view' => 'never', 'days' => $rCtx['days'], 'kind' => 'movies'])) . '">' . count($rNever['movies']) . ' movies</a></li>'
			. '<li><a href="' . $h(self::url(['view' => 'never', 'days' => $rCtx['days'], 'kind' => 'series'])) . '">' . count($rNever['series']) . ' series</a></li></ul>';
		return $rHtml;
	}

	/**
	 * @param array<int|string, array<string, int>> $rGroups
	 * @param array<string, mixed>                  $rCtx
	 */
	private static function topList(string $rTitle, array $rGroups, int $rCount, string $rView, array $rCtx): string {
		$h = static fn ($v): string => htmlspecialchars((string) $v, ENT_QUOTES);
		$rWatched = array_filter($rGroups, static fn (array $g): bool => $g['secs'] > 0);
		$rHtml = '<h6>' . $h($rTitle) . ' <a class="small fw-normal" href="' . $h(self::url(['view' => $rView, 'days' => $rCtx['days']])) . '">all ' . count($rWatched) . '</a></h6>';
		if ($rWatched === []) {
			return $rHtml . '<p class="text-muted">Nothing watched in this window.</p>';
		}
		$rHtml .= '<table class="table table-sm mb-3"><thead><tr><th>Name</th><th class="text-end">Viewer-hours</th><th class="text-end">Most at once</th><th class="text-end">Lines</th></tr></thead><tbody>';
		foreach (array_slice($rWatched, 0, $rCount, true) as $rKey => $g) {
			$rHtml .= '<tr><td>' . self::nameLink($rKey, $rCtx) . '</td><td class="text-end">' . number_format($g['secs'] / 3600, 1) . '</td><td class="text-end">' . $g['peak'] . '</td><td class="text-end">' . $g['lines'] . '</td></tr>';
		}
		return $rHtml . '</tbody></table>';
	}

	/**
	 * @param array<int|string, array<string, int>> $rGroups
	 * @param array<string, int>                    $rTotal
	 * @param array<string, mixed>                  $rCtx
	 */
	private static function usageTable(string $rTitle, array $rGroups, array $rTotal, array $rCtx, bool $rLiveCols): string {
		$h = static fn ($v): string => htmlspecialchars((string) $v, ENT_QUOTES);
		$rSpan = max(1, $rCtx['now'] - $rCtx['from']);
		$rRows = array_filter($rGroups, static fn ($g, $k): bool => ($rCtx['search'] === '' || mb_stripos(self::name($k, $rCtx), $rCtx['search']) !== false) && self::inCategories($k, $rCtx), ARRAY_FILTER_USE_BOTH);
		$rSecs = array_sum(array_column($rGroups, 'secs'));
		$rHtml = '<p class="text-muted small">' . $h($rTitle) . ': ' . count($rGroups) . ' watched, ' . number_format($rSecs / 3600, 1) . ' viewer-hours'
			. ($rTotal['secs'] > 0 ? ' (' . round($rSecs / $rTotal['secs'] * 100) . '% of all viewing)' : '') . '. "Lines" counts lines that stayed at least a minute; "Most at once" is the most viewers at the same moment.</p>';
		if ($rRows === []) {
			return $rHtml . '<p class="text-muted">Nothing to show.</p>';
		}
		$rHtml .= '<div class="table-responsive"><table class="table table-sm table-hover"><thead><tr><th>#</th><th>Name</th><th>Category</th><th class="text-end">Viewer-hours</th><th class="text-end">Share</th><th class="text-end">Viewers on average</th><th class="text-end">Most at once</th><th class="text-end">Lines</th><th class="text-end">Sessions</th><th class="text-end">Average session</th>'
			. ($rLiveCols ? '<th class="text-end">Restreamers</th><th>Now</th>' : '') . '</tr></thead><tbody>';
		$i = 0;
		foreach (array_slice($rRows, 0, self::MAX_ROWS, true) as $rKey => $g) {
			$rHtml .= '<tr><td class="text-muted">' . ++$i . '</td><td>' . self::nameLink($rKey, $rCtx) . '</td><td class="small">' . $h(self::category($rKey, $rCtx)) . '</td>'
				. '<td class="text-end">' . number_format($g['secs'] / 3600, 1) . '</td><td class="text-end">' . ($rTotal['secs'] > 0 ? number_format($g['secs'] / $rTotal['secs'] * 100, 1) . '%' : '') . '</td>'
				. '<td class="text-end">' . number_format($g['secs'] / $rSpan, 2) . '</td><td class="text-end" title="' . ($g['peak_at'] > 0 ? $h(date('Y-m-d H:i', $g['peak_at'])) : '') . '">' . $g['peak'] . '</td>'
				. '<td class="text-end">' . $g['lines'] . '</td><td class="text-end">' . $g['sessions'] . '</td><td class="text-end">' . ($g['sessions'] > 0 ? UsageAuditAnalyzer::duration(intdiv($g['secs'], $g['sessions'])) : '') . '</td>'
				. ($rLiveCols ? '<td class="text-end">' . ($g['restream'] ?: '') . '</td><td>' . self::state(is_int($rKey) ? $rKey : 0, $rCtx) . '</td>' : '') . '</tr>';
		}
		return $rHtml . '</tbody></table></div>' . (count($rRows) > self::MAX_ROWS ? '<p class="text-muted small">First ' . self::MAX_ROWS . ' of ' . count($rRows) . '.</p>' : '');
	}

	/**
	 * Viewing per category: a stream in several categories counts in its first;
	 * an episode in its series' category.
	 *
	 * @param list<array{0:int,1:int,2:int,3:int}> $rSessions
	 * @param array<int, true>                     $rRestreamers
	 * @param array<string, mixed>                 $rCtx
	 * @param array{episodes: array<int, int>, series: array<int, array{name:string, categories:list<int>}>} $rSeries
	 */
	private static function categoriesTab(array $rSessions, array $rRestreamers, array $rCtx, array $rSeries): string {
		$h = static fn ($v): string => htmlspecialchars((string) $v, ENT_QUOTES);
		$rCatOf = [];
		foreach ($rCtx['streams'] as $rID => $s) {
			$rCat = isset($rSeries['episodes'][$rID]) ? ($rSeries['series'][$rSeries['episodes'][$rID]]['categories'][0] ?? 0) : ($s['categories'][0] ?? 0);
			$rCatOf[$rID] = 'c' . $rCat;
		}
		$rUsage = UsageAuditAnalyzer::usage($rSessions, $rCtx['from'], $rCtx['now'], $rRestreamers, $rCatOf);
		$rTotal = max(1, $rUsage['total']['secs']);
		// What each category holds, to set beside what of it is watched.
		$rHolds = [];
		foreach ($rCtx['streams'] as $rID => $s) {
			if ($s['type'] !== UsageAuditRepository::EPISODE_TYPE) {
				$rHolds[$rCatOf[$rID]] = ($rHolds[$rCatOf[$rID]] ?? 0) + 1;
			}
		}
		foreach ($rSeries['series'] as $s) {
			$rKey = 'c' . ($s['categories'][0] ?? 0);
			$rHolds[$rKey] = ($rHolds[$rKey] ?? 0) + 1;
		}
		$rWatchedIn = [];
		foreach ($rSessions as [$rUser, $rStream]) {
			if (isset($rRestreamers[$rUser])) {
				continue;
			}
			$rItem = isset($rSeries['episodes'][$rStream]) ? 's' . $rSeries['episodes'][$rStream] : $rStream;
			$rWatchedIn[$rCatOf[$rStream] ?? 'c0'][$rItem] = true;
		}
		$rHtml = '<p class="text-muted small">A stream in several categories counts in its first; an episode in its series\' category. "Items watched" is how many of what the category holds were opened at least once.</p>'
			. '<div class="table-responsive"><table class="table table-sm table-hover"><thead><tr><th>Category</th><th>Type</th><th class="text-end">Viewer-hours</th><th class="text-end">Share</th><th class="text-end">Most at once</th><th class="text-end">Lines</th><th class="text-end">Items watched</th></tr></thead><tbody>';
		$rKeys = array_unique([...array_keys($rUsage['groups']), ...array_keys($rHolds)]);
		usort($rKeys, static fn ($a, $b): int => [($rUsage['groups'][$b]['secs'] ?? 0), $rHolds[$b] ?? 0] <=> [($rUsage['groups'][$a]['secs'] ?? 0), $rHolds[$a] ?? 0]);
		foreach ($rKeys as $rKey) {
			$c = $rCtx['categories'][(int) substr((string) $rKey, 1)] ?? null;
			$rName = $c['name'] ?? '(no category)';
			if ($rCtx['search'] !== '' && mb_stripos($rName, $rCtx['search']) === false) {
				continue;
			}
			$g = $rUsage['groups'][$rKey] ?? ['secs' => 0, 'peak' => 0, 'lines' => 0];
			$rItems = count($rWatchedIn[$rKey] ?? []);
			$rHtml .= '<tr' . ($g['secs'] === 0 ? ' class="text-muted"' : '') . '><td>' . $h($rName) . '</td><td class="small">' . $h($c['type'] ?? '') . '</td><td class="text-end">' . number_format($g['secs'] / 3600, 1) . '</td>'
				. '<td class="text-end">' . number_format($g['secs'] / $rTotal * 100, 1) . '%</td><td class="text-end">' . $g['peak'] . '</td><td class="text-end">' . $g['lines'] . '</td>'
				. '<td class="text-end">' . $rItems . ' of ' . ($rHolds[$rKey] ?? 0) . '</td></tr>';
		}
		return $rHtml . '</tbody></table></div>';
	}

	/**
	 * @param array{live: list<int>, movies: list<int>, series: list<int>} $rNever
	 * @param array<string, mixed>                                          $rCtx
	 */
	private static function neverTab(array $rNever, array $rCtx): string {
		$h = static fn ($v): string => htmlspecialchars((string) $v, ENT_QUOTES);
		$rKind = $rCtx['kind'];
		$rHtml = '<div class="btn-group btn-group-sm mb-3">';
		foreach (self::KINDS as $rKey => $rLabel) {
			$rHtml .= '<a class="btn btn-' . ($rKind === $rKey ? 'primary' : 'outline-primary') . '" href="' . $h(self::url(['view' => 'never', 'days' => $rCtx['days'], 'kind' => $rKey, 'q' => $rCtx['search']])) . '">' . $h($rLabel) . ' (' . count($rNever[$rKey]) . ')</a>';
		}
		$rHtml .= '</div>';
		$rIDs = $rNever[$rKind];
		$rIsSeries = $rKind === 'series';
		$rName = static fn (int $id): string => $rIsSeries ? $rCtx['series'][$id]['name'] : $rCtx['streams'][$id]['name'];
		$rIDs = array_values(array_filter($rIDs, static fn (int $id): bool => ($rCtx['search'] === '' || mb_stripos($rName($id), $rCtx['search']) !== false) && self::inCategories($rIsSeries ? 's' . $id : $id, $rCtx)));
		// When each was last watched, as far back as the log goes: for a series, its latest episode.
		$rEpisodes = [];
		if ($rIsSeries) {
			$rWanted = array_flip($rIDs);
			foreach (UsageAuditRepository::series()['episodes'] as $rStream => $rSeriesID) {
				if (isset($rWanted[$rSeriesID])) {
					$rEpisodes[$rSeriesID][] = $rStream;
				}
			}
			$rLast = [];
			$rEpisodeLast = UsageAuditRepository::lastWatched(array_merge([], ...array_values($rEpisodes)));
			foreach ($rEpisodes as $rSeriesID => $rStreamIDs) {
				$rLast[$rSeriesID] = max([0, ...array_map(static fn (int $s): int => $rEpisodeLast[$s] ?? 0, $rStreamIDs)]);
			}
		} else {
			$rLast = UsageAuditRepository::lastWatched($rIDs);
		}
		$rBouquets = $rIsSeries ? $rCtx['bouquets']['series'] : $rCtx['bouquets']['streams'];

		if ($rKind === 'live') {
			$rState = static fn (int $id): int => !empty($rCtx['running'][$id]['running']) && empty($rCtx['running'][$id]['on_demand']) ? 0 : (($rBouquets[$id] ?? 0) === 0 ? 2 : 1);
			usort($rIDs, static fn (int $a, int $b): int => [$rState($a), $rLast[$b] ?? 0, $rName($a)] <=> [$rState($b), $rLast[$a] ?? 0, $rName($b)]);
			$rAlways = count(array_filter($rIDs, static fn (int $id): bool => $rState($id) === 0));
			$rNoBouquet = count(array_filter($rIDs, static fn (int $id): bool => ($rBouquets[$id] ?? 0) === 0));
			$rNoSource = count(array_filter($rIDs, static fn (int $id): bool => $rCtx['streams'][$id]['type'] !== 3 && $rCtx['streams'][$id]['sources'] === 0));
			$rHtml .= '<ul class="small mb-3">'
				. '<li><strong>' . $rAlways . '</strong> running all the time (not on demand): each one holds a provider connection and server resources for nobody. Making them on demand frees both until someone asks.</li>'
				. '<li><strong>' . $rNoBouquet . '</strong> in no bouquet: no line is offered them, so nobody can watch them.</li>'
				. '<li><strong>' . $rNoSource . '</strong> without a source.</li></ul>';
		}
		$rCoverage = $rCtx['coverage'];
		$rHtml .= '<p class="text-muted small">Nobody watched these in ' . $h(strtolower(self::DAYS[$rCtx['days']])) . ' (restreamer lines included: a stream a restreamer pulls is not listed). "Last watched" goes back as far as the activity log, '
			. ($rCoverage > 0 ? 'which starts on ' . date('Y-m-d', $rCoverage) : 'which is empty') . '. Nothing here is changed from this page.</p>';
		if ($rIDs === []) {
			return $rHtml . '<p class="text-muted">Nothing to show.</p>';
		}
		$rHtml .= '<div class="table-responsive"><table class="table table-sm table-hover"><thead><tr><th>Name</th><th>Category</th><th class="text-end">Bouquets</th>'
			. ($rKind === 'live' ? '<th class="text-end">Sources</th><th>Now</th>' : '') . ($rIsSeries ? '<th class="text-end">Episodes</th>' : '<th>Added</th>') . '<th>Last watched</th></tr></thead><tbody>';
		foreach (array_slice($rIDs, 0, self::MAX_ROWS) as $rID) {
			$rKey = $rIsSeries ? 's' . $rID : $rID;
			$rB = $rBouquets[$rID] ?? 0;
			$rHtml .= '<tr><td>' . self::nameLink($rKey, $rCtx) . '</td><td class="small">' . $h(self::category($rKey, $rCtx)) . '</td>'
				. '<td class="text-end">' . ($rB > 0 ? $rB : '<span class="badge bg-label-secondary">none</span>') . '</td>';
			if ($rKind === 'live') {
				$s = $rCtx['streams'][$rID];
				$rHtml .= '<td class="text-end">' . ($s['type'] === 3 ? '<span class="text-muted small">created</span>' : ($s['sources'] > 0 ? $s['sources'] : '<span class="badge bg-label-danger">0</span>')) . '</td><td>' . self::state($rID, $rCtx) . '</td>';
			}
			$rHtml .= $rIsSeries ? '<td class="text-end">' . count($rEpisodes[$rID] ?? []) . '</td>' : '<td class="small">' . ($rCtx['streams'][$rID]['added'] > 0 ? date('Y-m-d', $rCtx['streams'][$rID]['added']) : '') . '</td>';
			$rHtml .= '<td class="small">' . (($rLast[$rID] ?? 0) > 0 ? date('Y-m-d H:i', $rLast[$rID]) . ' <span class="text-muted">(' . self::ago($rCtx['now'] - $rLast[$rID]) . ')</span>' : '<span class="text-muted">not in the log</span>') . '</td></tr>';
		}
		return $rHtml . '</tbody></table></div>' . (count($rIDs) > self::MAX_ROWS ? '<p class="text-muted small">First ' . self::MAX_ROWS . ' of ' . count($rIDs) . '.</p>' : '');
	}

	/**
	 * A multiple choice of the categories that hold something of this kind.
	 *
	 * @param array<string, mixed>                                   $rCtx
	 * @param array<int, array{name:string, categories:list<int>}> $rSeries
	 */
	private static function categoryFilter(string $rKind, array $rCtx, array $rSeries): string {
		$h = static fn ($v): string => htmlspecialchars((string) $v, ENT_QUOTES);
		$rUsed = [];
		if ($rKind === 'series') {
			foreach ($rSeries as $s) {
				$rUsed += array_fill_keys($s['categories'], true);
			}
		} else {
			$rTypes = $rKind === 'movies' ? [UsageAuditRepository::MOVIE_TYPE] : [...UsageAuditRepository::LIVE_TYPES, UsageAuditRepository::RADIO_TYPE];
			foreach ($rCtx['streams'] as $s) {
				if (in_array($s['type'], $rTypes, true)) {
					$rUsed += array_fill_keys($s['categories'], true);
				}
			}
		}
		$rOptions = [];
		foreach ($rCtx['categories'] as $rID => $c) {
			if (isset($rUsed[$rID]) && in_array($c['type'], self::CATEGORY_TYPES[$rKind], true)) {
				$rOptions[$rID] = $c['name'];
			}
		}
		natcasesort($rOptions);
		$rHtml = '<select name="cat[]" id="usage-audit-cat" multiple class="form-select form-select-sm" style="width:auto;min-width:240px" data-placeholder="All categories" size="1">';
		foreach ($rOptions as $rID => $rName) {
			$rHtml .= '<option value="' . (int) $rID . '"' . (in_array($rID, $rCtx['cats'], true) ? ' selected' : '') . '>' . $h($rName) . '</option>';
		}
		return $rHtml . '</select><script>window.addEventListener("load",function(){if(window.jQuery&&jQuery.fn.select2){jQuery("#usage-audit-cat").select2({placeholder:"All categories",allowClear:true,width:"320px"});}});</script>';
	}

	/** @param array<string, mixed> $rCtx */
	private static function inCategories(int|string $rKey, array $rCtx): bool {
		if ($rCtx['cats'] === []) {
			return true;
		}
		$rIDs = is_string($rKey) ? ($rCtx['series'][(int) substr($rKey, 1)]['categories'] ?? []) : ($rCtx['streams'][$rKey]['categories'] ?? []);
		return array_intersect($rIDs, $rCtx['cats']) !== [];
	}

	/** @param array<string, mixed> $rCtx */
	private static function state(int $rID, array $rCtx): string {
		$r = $rCtx['running'][$rID] ?? null;
		if ($r === null) {
			return '<span class="text-muted small">on no server</span>';
		}
		if ($r['on_demand']) {
			return '<span class="badge bg-label-info">on demand</span>' . ($r['running'] ? ' <span class="badge bg-label-success">on air</span>' : '');
		}
		return $r['running'] ? '<span class="badge bg-label-warning">always on</span>' : '<span class="badge bg-label-secondary">stopped</span>';
	}

	/** @param array<string, mixed> $rCtx */
	private static function name(int|string $rKey, array $rCtx): string {
		if (is_string($rKey)) {
			return $rCtx['series'][(int) substr($rKey, 1)]['name'] ?? 'Series #' . substr($rKey, 1);
		}
		return $rCtx['streams'][$rKey]['name'] ?? 'Stream #' . $rKey . ' (deleted)';
	}

	/** @param array<string, mixed> $rCtx */
	private static function nameLink(int|string $rKey, array $rCtx): string {
		$rName = htmlspecialchars(self::name($rKey, $rCtx), ENT_QUOTES);
		if (is_string($rKey)) {
			return '<a href="./serie?id=' . (int) substr($rKey, 1) . '">' . $rName . '</a>';
		}
		$rPage = match ($rCtx['streams'][$rKey]['type'] ?? 0) {
			1 => 'stream', 2 => 'movie', 3 => 'created_channel', 4 => 'radio', default => null,
		};
		$rType = ($rCtx['streams'][$rKey]['type'] ?? 0) === 4 ? ' <span class="badge bg-label-secondary">radio</span>' : '';
		return ($rPage === null ? $rName : '<a href="./' . $rPage . '?id=' . $rKey . '">' . $rName . '</a>') . $rType;
	}

	/** @param array<string, mixed> $rCtx */
	private static function category(int|string $rKey, array $rCtx): string {
		$rIDs = is_string($rKey) ? ($rCtx['series'][(int) substr($rKey, 1)]['categories'] ?? []) : ($rCtx['streams'][$rKey]['categories'] ?? []);
		return implode(', ', array_map(static fn (int $id): string => $rCtx['categories'][$id]['name'] ?? '#' . $id, $rIDs));
	}

	private static function ago(int $rSecs): string {
		return $rSecs >= 86400 ? intdiv($rSecs, 86400) . ' days ago' : UsageAuditAnalyzer::duration(max(0, $rSecs)) . ' ago';
	}

	/** @param array<string, mixed> $rQuery */
	private static function url(array $rQuery): string {
		if (($rQuery['days'] ?? null) === 7) {
			unset($rQuery['days']);   // the default
		}
		return './usage_audit?' . http_build_query(array_filter($rQuery, static fn ($v): bool => $v !== null && $v !== ''));
	}
}
