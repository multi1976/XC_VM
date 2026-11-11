<?php

namespace XcVm\Cli\CronJobs;

use XcVm\Cli\CommandInterface;
use XcVm\Cli\CronTrait;
use XcVm\Domain\Stream\StreamProcess;

/**
 * ProvidersCronJob — providers cron job
 *
 * @package XC_VM_CLI_CronJobs
 * @author  Divarion_D <https://github.com/Divarion-D>
 * @copyright 2025-2026 Vateron Media
 * @link    https://github.com/Vateron-Media/XC_VM
 * @license AGPL-3.0 https://www.gnu.org/licenses/agpl-3.0.html
 */

class ProvidersCronJob implements CommandInterface {
	use CronTrait;

	public function getName(): string {
		return 'cron:providers';
	}

	public function getDescription(): string {
		return 'Cron: sync providers (channels, VOD, series)';
	}

	public function execute(array $rArgs): int {
		if (!$this->assertRunAsXcVm()) {
			return 1;
		}

		$this->initCron('XC_VM[Providers]');

		$rTimeout = 300;
		set_time_limit($rTimeout);
		ini_set('max_execution_time', $rTimeout);

		$rProviderID = null;
		if (!empty($rArgs[0])) {
			$rProviderID = intval($rArgs[0]);
		}

		$this->loadCron($rProviderID);

		return 0;
	}

	private function readURL(string $rURL): ?array {
		$rContext = stream_context_create(['http' => ['timeout' => 30]]);
		return json_decode(@file_get_contents($rURL, false, $rContext) ?: 'null', true);
	}

	private function loadCron(?int $rProviderID): void {
		global $db;

		if ($rProviderID) {
			$db->query('SELECT `id`, `stream_display_name`, `title_sync` FROM `streams` WHERE `title_sync` LIKE ?;', $rProviderID . '_%');
		} else {
			$db->query('SELECT `id`, `stream_display_name`, `title_sync` FROM `streams` WHERE `title_sync` IS NOT NULL;');
		}

		$rSyncTitle = [];
		foreach ($db->get_rows() as $rRow) {
			list($rSyncID, $rSyncStream) = array_map('intval', explode('_', $rRow['title_sync']));
			if (!isset($rSyncTitle[$rSyncID])) {
				$rSyncTitle[$rSyncID] = [];
			}
			$rSyncTitle[$rSyncID][$rSyncStream] = [$rRow['id'], $rRow['stream_display_name']];
		}

		if ($rProviderID) {
			$db->query('SELECT * FROM `providers` WHERE `id` = ?;', $rProviderID);
		} else {
			// Oldest sync first: the run is time-limited, so a provider it did not
			// reach last hour goes first this hour instead of never.
			$db->query('SELECT * FROM `providers` WHERE `enabled` = 1 ORDER BY `last_changed` IS NOT NULL, `last_changed` ASC, `id` ASC;');
		}

		foreach ($db->get_rows() as $rRow) {
			$this->syncProvider($rRow, $rSyncTitle);
		}
	}

	/**
	 * Fetch one provider's details and the lists it is set to sync (sync_live,
	 * sync_vod, sync_series; all three when the columns are absent), and bring
	 * providers_streams in line. A list that could not be fetched leaves its
	 * stored entries alone; a list turned off has them removed.
	 *
	 * @param array<string, mixed> $rRow providers row
	 * @param array<int, array<int, array{0:int, 1:string}>> $rSyncTitle provider id => provider stream id => [stream id, name]
	 */
	private function syncProvider(array $rRow, array $rSyncTitle): void {
		global $db;
		$rWants = [
			'live'   => (int) ($rRow['sync_live'] ?? 1) === 1,
			'movie'  => (int) ($rRow['sync_vod'] ?? 1) === 1,
			'series' => (int) ($rRow['sync_series'] ?? 1) === 1,
		];
		$rArray = json_decode((string) ($rRow['data'] ?? ''), true);
		$rArray = is_array($rArray) ? $rArray : [];

		// A provider's `ip` field is meant to be a bare host, but a user
		// pasting a full URL by mistake (e.g. "https://example.com") would
		// otherwise double the scheme ("https://https://example.com").
		$rHost = preg_replace('#^https?://#i', '', (string) $rRow['ip']);
		$rURL = (($rRow['ssl'] ? 'https' : 'http')) . '://' . $rHost . ':' . $rRow['port'] . '/';
		if ($rRow['legacy']) {
			$rURL .= 'player_api.php?username=' . $rRow['username'] . '&password=' . $rRow['password'];
		} else {
			$rURL .= 'player_api/' . $rRow['username'] . '/' . $rRow['password'] . '?connections=1';
		}

		$rInfo = $this->readURL($rURL);
		if (!$rInfo) {
			// Unreachable: say so, keep what was fetched before. Its lists are not
			// asked for — they would fail too, and an empty answer must not wipe
			// the stored channels.
			$rArray['exp_date'] = -1;
			$db->query('UPDATE `providers` SET `data` = ?, `last_changed` = ?, `status` = 0 WHERE `id` = ?;', json_encode($rArray), time(), $rRow['id']);
			return;
		}
		$rUserInfo = $rInfo['user_info'] ?? [];
		$rArray['max_connections'] = $rUserInfo['max_connections'] ?? null;
		$rArray['active_connections'] = $rUserInfo['active_cons'] ?? 0;
		$rArray['exp_date'] = $rUserInfo['exp_date'] ?? null;

		$rCategories = [];
		foreach (['live' => 'get_live_categories', 'movie' => 'get_vod_categories'] as $rType => $rAction) {
			if (!$rWants[$rType]) {
				continue;
			}
			$rList = $this->readURL($rURL . '&action=' . $rAction);
			foreach (is_array($rList) ? $rList : [] as $rCategory) {
				if (isset($rCategory['category_id'])) {
					$rCategories[$rCategory['category_id']] = $rCategory['category_name'] ?? '';
				}
			}
		}

		// null: not fetched (turned off, or the request failed).
		$rLists = ['live' => null, 'movie' => null];
		foreach (['live' => 'get_live_streams', 'movie' => 'get_vod_streams'] as $rType => $rAction) {
			if ($rWants[$rType]) {
				$rList = $this->readURL($rURL . '&action=' . $rAction);
				$rLists[$rType] = is_array($rList) ? $rList : null;
			}
		}
		foreach (['live' => 'streams', 'movie' => 'movies'] as $rType => $rKey) {
			if (!$rWants[$rType]) {
				$rArray[$rKey] = 0;
			} elseif ($rLists[$rType] !== null) {
				$rArray[$rKey] = count($rLists[$rType]);
			}
		}
		if ($rWants['series']) {
			$rSeries = $this->readURL($rURL . '&action=get_series');
			if (is_array($rSeries)) {
				$rArray['series'] = count($rSeries);
			}
		} else {
			$rArray['series'] = 0;
		}

		$db->query('UPDATE `providers` SET `data` = ?, `last_changed` = ?, `status` = 1 WHERE `id` = ?;', json_encode($rArray), time(), $rRow['id']);

		$db->query('SELECT `type`, `stream_id`, `category_id`, `stream_display_name`, `stream_icon`, `channel_id` FROM `providers_streams` WHERE `provider_id` = ?;', $rRow['id']);
		$rExisting = ['live' => [], 'movie' => []];
		foreach ($db->get_rows() as $rStream) {
			$rType = $rStream['type'] === 'movie' ? 'movie' : 'live';
			$rExisting[$rType][$rStream['stream_id']] = md5($rStream['category_id'] . '_' . (($rStream['stream_display_name'] ?: '')) . '_' . (($rStream['stream_icon'] ?: '')) . '_' . (($rStream['channel_id'] ?: '')));
		}

		$rTime = time();
		foreach ($rLists as $rType => $rSelection) {
			if (!$rWants[$rType]) {
				// Turned off: its entries would only go stale.
				$db->query('DELETE FROM `providers_streams` WHERE `provider_id` = ? AND `type` = ?;', $rRow['id'], $rType);
				continue;
			}
			if ($rSelection === null) {
				continue; // the fetch failed: keep what is stored
			}
			$rNewIDs = [];
			foreach ($rSelection as $rStream) {
				// External provider payloads may omit any of these keys.
				$rStream += ['stream_id' => null, 'category_id' => '', 'name' => '', 'stream_icon' => '', 'epg_channel_id' => '', 'container_extension' => ''];
				if ($rStream['stream_id'] === null) {
					continue;
				}
				$rNewIDs[$rStream['stream_id']] = true;
				$rCategoryIDs = (isset($rStream['category_ids']) ? (is_array($rStream['category_ids']) ? $rStream['category_ids'] : []) : [$rStream['category_id']]);
				$rCategoryArray = [];
				foreach ($rCategoryIDs as $rCategoryID) {
					// The provider may reference a category id absent from the
					// categories feed; default to '' instead of warning.
					$rCategoryArray[] = $rCategories[$rCategoryID] ?? '';
				}
				$rCategoryIDs = '[' . implode(',', array_map('intval', $rCategoryIDs)) . ']';
				$rChannel = ($rType == 'live' ? $rStream['epg_channel_id'] : $rStream['container_extension']);
				if (isset($rExisting[$rType][$rStream['stream_id']])) {
					if (md5($rCategoryIDs . '_' . (($rStream['name'] ?: '')) . '_' . (($rStream['stream_icon'] ?: '')) . '_' . (($rChannel ?: ''))) != $rExisting[$rType][$rStream['stream_id']]) {
						$db->query('UPDATE `providers_streams` SET `category_id` = ?, `category_array` = ?, `stream_display_name` = ?, `stream_icon` = ?, `channel_id` = ?, `modified` = ? WHERE `provider_id` = ? AND `stream_id` = ? AND `type` = ?;', $rCategoryIDs, json_encode($rCategoryArray), $rStream['name'], $rStream['stream_icon'], $rChannel, $rTime, $rRow['id'], $rStream['stream_id'], $rType);
					}
				} else {
					$db->query('INSERT INTO `providers_streams`(`provider_id`, `type`, `stream_id`, `category_id`, `category_array`, `stream_display_name`, `stream_icon`, `channel_id`, `added`, `modified`) VALUES(?, ?, ?, ?, ?, ?, ?, ?, ?, ?);', $rRow['id'], $rType, $rStream['stream_id'], $rCategoryIDs, json_encode($rCategoryArray), $rStream['name'], $rStream['stream_icon'], $rChannel, $rTime, $rTime);
				}
				if ($rType == 'live' && isset($rSyncTitle[$rRow['id']][$rStream['stream_id']])) {
					if ($rStream['name'] != $rSyncTitle[$rRow['id']][$rStream['stream_id']][1]) {
						$db->query('UPDATE `streams` SET `stream_display_name` = ? WHERE `id` = ?;', $rStream['name'], $rSyncTitle[$rRow['id']][$rStream['stream_id']][0]);
						StreamProcess::updateStream($rSyncTitle[$rRow['id']][$rStream['stream_id']][0]);
					}
				}
			}
			$rDelete = array_keys(array_diff_key($rExisting[$rType], $rNewIDs));
			foreach (array_chunk($rDelete, 1000) as $rChunk) {
				$db->query('DELETE FROM `providers_streams` WHERE `provider_id` = ? AND `type` = ? AND `stream_id` IN (' . implode(',', array_map('intval', $rChunk)) . ');', $rRow['id'], $rType);
			}
		}
	}
}
