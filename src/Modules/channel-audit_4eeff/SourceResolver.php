<?php

namespace XcVm\Module\ChannelAudit;

use XcVm\Core\Cluster\ClusterSettings;
use XcVm\Core\Cluster\DataPlane;
use XcVm\Core\Config\SettingsManager;
use XcVm\Core\Http\ApiClient;
use XcVm\Domain\Server\ServerRepository;
use XcVm\Domain\Stream\StreamProcess;

/**
 * Turns what the admin chose (a folder on a server, or a created channel) into
 * the list of files to check, and each file into something ffprobe can open:
 * a local path on MAIN, or the panel's own getFile URL for a file that lives
 * on another server — the same addressing a created channel's build uses
 * (`s:<server>:<path>`, `vod:<id>`, or a plain local path).
 */
final class SourceResolver {
	/** What the created-channel file browser shows as video. */
	public const VIDEO_EXTENSIONS = ['mp4', 'mkv', 'avi', 'mpg', 'flv', '3gp', 'm4v', 'wmv', 'mov', 'ts'];

	/**
	 * The video files in a folder, as channel sources, in the order the
	 * created-channel browser adds them.
	 *
	 * @return list<array{source:string, name:string}>
	 * @throws \RuntimeException when the folder cannot be listed
	 */
	public static function folder(int $rServerID, string $rDir): array {
		$rDir = '/' . trim($rDir, '/');
		if (preg_match('#(^|/)\.\.(/|$)|[\x00-\x1f]#', $rDir)) {
			throw new \RuntimeException('invalid folder path');
		}
		if ($rServerID === (int) SERVER_ID) {
			if (!is_dir($rDir) || !ClusterSettings::pathAllowed($rDir, SettingsManager::get('lb_scan_roots'))) {
				throw new \RuntimeException('the folder does not exist or is outside the allowed scan roots (Settings → lb_scan_roots)');
			}
			$rFiles = [];
			foreach (scandir($rDir) ?: [] as $rName) {
				if ($rName[0] !== '.' && is_file($rDir . '/' . $rName) && in_array(strtolower(pathinfo($rName, PATHINFO_EXTENSION)), self::VIDEO_EXTENSIONS, true)) {
					$rFiles[] = $rName;
				}
			}
		} else {
			$rList = ApiClient::listDir($rServerID, $rDir . '/', self::VIDEO_EXTENSIONS);
			if (!is_array($rList) || !isset($rList['files'])) {
				throw new \RuntimeException('server #' . $rServerID . ' did not list the folder');
			}
			$rFiles = array_values(array_map('strval', (array) $rList['files']));
		}
		if ($rFiles === []) {
			throw new \RuntimeException('no video files in ' . $rDir);
		}
		return array_map(static fn (string $f): array => ['source' => 's:' . $rServerID . ':' . $rDir . '/' . $f, 'name' => $f], $rFiles);
	}

	/**
	 * A created channel's sources.
	 *
	 * @return list<array{source:string, name:string}>
	 */
	public static function channel(array $rSources): array {
		return array_map(static fn (string $s): array => ['source' => $s, 'name' => self::name($s)], $rSources);
	}

	/**
	 * Something ffprobe can open for a channel source.
	 *
	 * @throws \RuntimeException when the file cannot be reached from MAIN
	 */
	public static function input(string $rSource): string {
		if (strncmp($rSource, 'vod:', 4) === 0) {
			foreach (array_keys(ServerRepository::getAll() ?: []) as $rServerID) {
				$rPath = StreamProcess::resolveVodSource($rSource, (int) $rServerID);
				if ($rPath !== null) {
					return self::reach((int) $rServerID, $rPath);
				}
			}
			throw new \RuntimeException('the VOD is not encoded on any server');
		}
		if (strncmp($rSource, 's:', 2) === 0) {
			$rParts = explode(':', $rSource, 3);
			if (count($rParts) !== 3) {
				throw new \RuntimeException('malformed source');
			}
			return self::reach((int) $rParts[1], $rParts[2]);
		}
		return self::reach((int) SERVER_ID, $rSource);
	}

	public static function name(string $rSource): string {
		if (strncmp($rSource, 'vod:', 4) === 0) {
			return 'VOD #' . (int) substr($rSource, 4);
		}
		$rPath = strncmp($rSource, 's:', 2) === 0 ? (explode(':', $rSource, 3)[2] ?? $rSource) : $rSource;
		return basename($rPath);
	}

	private static function reach(int $rServerID, string $rPath): string {
		if ($rServerID === (int) SERVER_ID || is_file($rPath)) {
			if (!is_file($rPath)) {
				throw new \RuntimeException('file not found: ' . $rPath);
			}
			return $rPath;
		}
		$rServers = ServerRepository::getAll();
		if (!is_array($rServers) || !isset($rServers[$rServerID])) {
			throw new \RuntimeException('unknown server #' . $rServerID);
		}
		$rUrl = DataPlane::fileUrl($rServers, $rServerID, $rPath);
		if ($rUrl === DataPlane::UNAVAILABLE || $rUrl === '' || str_starts_with($rUrl, '&')) {
			throw new \RuntimeException('server #' . $rServerID . ' cannot serve the file to MAIN');
		}
		return $rUrl;
	}
}
