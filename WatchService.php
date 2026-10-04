<?php

namespace XcVm\Module\Watch;

use XcVm\Core\Config\SettingsManager;
use XcVm\Core\Database\QueryHelper;
use XcVm\Core\Http\ApiClient;
use XcVm\Core\Util\AdminHelpers;
use XcVm\Domain\Server\ServerRepository;

/**
 * WatchService — watch service
 *
 * @package XC_VM_Module_Watch
 * @author  Divarion_D <https://github.com/Divarion-D>
 * @copyright 2025-2026 Vateron Media
 * @link    https://github.com/Vateron-Media/XC_VM
 * @license AGPL-3.0 https://www.gnu.org/licenses/agpl-3.0.html
 */

class WatchService {

    use \XcVm\Infrastructure\Database\DatabaseAware;

	/**
	 * Save the folder-scan settings. TMDb matching, parallel imports and the
	 * genre mapping are core's (Settings → VOD Import).
	 *
	 * @param array $rData
	 * @return array
	 */
	public static function editWatchSettings($rData) {
		$db = self::db();
		$db->query('UPDATE `settings` SET `scan_seconds` = ?, `max_items` = ?;', intval($rData['scan_seconds'] ?? 0), intval($rData['max_items'] ?? 0));

		SettingsManager::clearCache();

		return array('status' => STATUS_SUCCESS);
	}

	public static function processWatchFolder($rData) {
		$db = self::db();
		if (isset($rData['edit'])) {
			$rArray = AdminHelpers::overwriteData(self::getWatchFolder($rData['edit']), $rData);
		} else {
			$rArray = QueryHelper::verifyPostTable('watch_folders', $rData);
			unset($rArray['id']);
		}

		$rPath = $rData['selected_path'];
		if (!(0 < strlen($rPath) && $rPath != '/')) {
			return array('status' => STATUS_INVALID_DIR, 'data' => $rData);
		}

		if (isset($rData['edit'])) {
			$db->query('SELECT COUNT(*) AS `count` FROM `watch_folders` WHERE `directory` = ? AND `server_id` = ? AND `type` = ? AND `id` <> ?;', $rPath, $rArray['server_id'], $rData['folder_type'], $rArray['id']);
		} else {
			$db->query('SELECT COUNT(*) AS `count` FROM `watch_folders` WHERE `directory` = ? AND `server_id` = ? AND `type` = ?;', $rPath, $rArray['server_id'], $rData['folder_type']);
		}

		if (0 < $db->get_row()['count']) {
			return array('status' => STATUS_EXISTS_DIR, 'data' => $rData);
		}

		$bouquets = is_array($rData['bouquets'] ?? null) ? $rData['bouquets'] : array();
		$fbBouquets = is_array($rData['fb_bouquets'] ?? null) ? $rData['fb_bouquets'] : array();

		$rArray['type'] = $rData['folder_type'];
		$rArray['directory'] = $rPath;
		$rArray['bouquets'] = '[' . implode(',', array_map('intval', $bouquets)) . ']';
		$rArray['fb_bouquets'] = '[' . implode(',', array_map('intval', $fbBouquets)) . ']';
		$rArray['allowed_extensions'] = is_array($rData['allowed_extensions'] ?? null) && count($rData['allowed_extensions']) > 0 ? json_encode($rData['allowed_extensions']) : '[]';
		$rArray['target_container'] = ($rData['target_container'] == 'auto' ? null : $rData['target_container']);
		$rArray['category_id'] = intval($rData['category_id_' . $rData['folder_type']]);
		$rArray['fb_category_id'] = intval($rData['fb_category_id_' . $rData['folder_type']]);

		foreach (array('remove_subtitles', 'duplicate_tmdb', 'extract_metadata', 'fallback_title', 'disable_tmdb', 'ignore_no_match', 'auto_subtitles', 'auto_upgrade', 'read_native', 'movie_symlink', 'auto_encode', 'ffprobe_input', 'delete_missing', 'active') as $rKey) {
			$rArray[$rKey] = isset($rData[$rKey]) ? 1 : 0;
		}

		$rPrepare = QueryHelper::prepareArray($rArray);
		$rQuery = 'REPLACE INTO `watch_folders`(' . $rPrepare['columns'] . ') VALUES(' . $rPrepare['placeholder'] . ');';

		if ($db->query($rQuery, ...$rPrepare['data'])) {
			$rInsertID = $db->last_insert_id();
			return array('status' => STATUS_SUCCESS, 'data' => array('insert_id' => $rInsertID));
		}

		return array('status' => STATUS_FAILURE, 'data' => $rData);
	}

	/**
	 * Admin API: one folder.
	 *
	 * @param int $rID
	 * @return array
	 */
	public static function apiGetFolder(int $rID) {
		$rFolder = self::getWatchFolder($rID);
		return $rFolder ? array('status' => STATUS_SUCCESS, 'data' => $rFolder) : array('status' => STATUS_FAILURE);
	}

	/**
	 * Admin API: create a folder, or edit the one `$rID` names; replies with
	 * the stored folder.
	 *
	 * @param array    $rData
	 * @param int|null $rID
	 * @return array
	 */
	public static function apiSaveFolder(array $rData, ?int $rID = null) {
		unset($rData['edit'], $rData['id']);
		if ($rID !== null) {
			if (!self::getWatchFolder($rID)) {
				return array('status' => STATUS_FAILURE);
			}
			$rData['edit'] = $rID;
		}
		$rReturn = self::processWatchFolder($rData);
		if (isset($rReturn['data']['insert_id'])) {
			$rReturn['data'] = self::apiGetFolder((int) $rReturn['data']['insert_id'])['data'] ?? null;
		}
		return $rReturn;
	}

	/**
	 * Admin API: delete a folder.
	 *
	 * @param int $rID
	 * @return array
	 */
	public static function apiDeleteFolder(int $rID) {
		return array('status' => self::deleteWatchFolder($rID) ? STATUS_SUCCESS : STATUS_FAILURE);
	}

	/**
	 * A watch-folder row by id.
	 *
	 * @param int $rID
	 * @return array|false The row, or false if there is none.
	 */
	public static function getWatchFolder(int $rID) {
		$db = self::db();
		$db->query('SELECT * FROM `watch_folders` WHERE `id` = ?;', $rID);
		return $db->num_rows() == 1 ? $db->get_row() : false;
	}

	/**
	 * Delete a watch folder.
	 *
	 * @param int $rID
	 * @return bool False if there is no such folder.
	 */
	public static function deleteWatchFolder(int $rID) {
		if (!self::getWatchFolder($rID)) {
			return false;
		}
		self::db()->query('DELETE FROM `watch_folders` WHERE `id` = ?;', $rID);
		return true;
	}

	public static function getWatchFolders($rType = null) {
		$db = self::db();
		if ($rType) {
			$db->query("SELECT * FROM `watch_folders` WHERE `type` = ? AND `type` <> 'plex' ORDER BY `id` ASC;", $rType);
		} else {
			$db->query("SELECT * FROM `watch_folders` WHERE `type` <> 'plex' ORDER BY `id` ASC;");
		}

		return $db->get_rows();
	}

	public static function forceWatch($rServerID, $rWatchID) {
		return ApiClient::systemRequest($rServerID, array('action' => 'watch_force', 'id' => $rWatchID));
	}

	public static function enableWatch() {
		return self::db()->query("UPDATE `watch_folders` SET `active` = 1 WHERE `type` <> 'plex';");
	}

	public static function disableWatch() {
		return self::db()->query("UPDATE `watch_folders` SET `active` = 0 WHERE `type` <> 'plex';");
	}

	public static function killWatch() {
		$db = self::db();
		$db->query("SELECT DISTINCT(`server_id`) AS `server_id` FROM `watch_folders` WHERE `type` <> 'plex';");
		foreach ($db->get_rows() as $rRow) {
			if (ServerRepository::getAll()[$rRow['server_id']]['server_online']) {
				ApiClient::systemRequest($rRow['server_id'], array('action' => 'kill_watch'));
			}
		}
		return true;
	}

	/**
	 * Remove a deleted bouquet from every watch folder that referenced it.
	 *
	 * Reacts to BouquetDeletedEvent so core bouquet deletion no longer needs to
	 * touch the watch_folders table directly.
	 *
	 * @param int $rBouquetID Deleted bouquet id.
	 * @return void
	 */
	public static function handleBouquetDeleted($rBouquetID) {
		$db = self::db();
		$db->query("SELECT `id`, `bouquets`, `fb_bouquets` FROM `watch_folders` WHERE JSON_CONTAINS(`bouquets`, ?, '\$') OR JSON_CONTAINS(`fb_bouquets`, ?, '\$');", $rBouquetID, $rBouquetID);

		foreach ($db->get_rows() as $rRow) {
			$rBouquets = json_decode($rRow['bouquets'], true) ?: array();
			if (($rKey = array_search($rBouquetID, $rBouquets)) !== false) {
				unset($rBouquets[$rKey]);
			}

			$rFbBouquets = json_decode($rRow['fb_bouquets'], true) ?: array();
			if (($rKey = array_search($rBouquetID, $rFbBouquets)) !== false) {
				unset($rFbBouquets[$rKey]);
			}

			$db->query(
				"UPDATE `watch_folders` SET `bouquets` = ?, `fb_bouquets` = ? WHERE `id` = ?;",
				'[' . implode(',', array_map('intval', $rBouquets)) . ']',
				'[' . implode(',', array_map('intval', $rFbBouquets)) . ']',
				$rRow['id']
			);
		}
	}

	/**
	 * Drop watch scan logs / refresh-queue rows for deleted streams.
	 *
	 * Reacts to StreamsDeletedEvent so core stream deletion no longer needs to
	 * touch the watch_refresh / watch_logs tables directly.
	 *
	 * @param int[] $rStreamIDs Deleted stream ids.
	 * @return void
	 */
	public static function handleStreamsDeleted(array $rStreamIDs) {
		if (empty($rStreamIDs)) {
			return;
		}
		$rIn = implode(',', array_map('intval', $rStreamIDs));
		$db = self::db();
		$db->query('DELETE FROM `watch_refresh` WHERE `stream_id` IN (' . $rIn . ');');
		$db->query('DELETE FROM `watch_logs` WHERE `stream_id` IN (' . $rIn . ');');
	}

	/**
	 * Mark a watch-folder log row as imported and link the created stream.
	 *
	 * Reacts to core's VodImportedEvent (dispatched by MovieService) so core no
	 * longer touches this module's watch_logs table directly. A no-op when the
	 * imported file was not one this watcher logged.
	 *
	 * @param int    $rStreamID  Created VOD stream id.
	 * @param string $rPath      Source file path (server prefix already stripped).
	 * @param int    $rType      VOD type (1 = movie).
	 * @return void
	 */
	public static function markImported($rStreamID, $rPath, $rType = 1) {
		if ((string) $rPath === '') {
			return;
		}
		$db = self::db();
		// logImportResult() stores filenames HTML-escaped.
		$db->query('UPDATE `watch_logs` SET `status` = 1, `stream_id` = ? WHERE `filename` = ? AND `type` = ?;', (int) $rStreamID, htmlspecialchars((string) $rPath, ENT_QUOTES, 'UTF-8'), (int) $rType);
	}

	/**
	 * Record a file's import outcome, replacing any earlier row for that file.
	 *
	 * @param int    $rType     1 = movie, 2 = series.
	 * @param int    $rServerID
	 * @param string $rFilename Raw path or URL (stored HTML-escaped, as the log view expects).
	 * @param int    $rStatus   VodImportResultEvent::STATUS_*
	 * @param int    $rStreamID
	 * @return void
	 */
	public static function logImportResult($rType, $rServerID, $rFilename, $rStatus, $rStreamID = 0) {
		$db = self::db();
		$rFilename = htmlspecialchars((string) $rFilename, ENT_QUOTES, 'UTF-8');
		$db->query('DELETE FROM `watch_logs` WHERE `filename` = ? AND `type` = ? AND `server_id` = ?;', $rFilename, (int) $rType, (int) $rServerID);
		$db->query('INSERT INTO `watch_logs`(`type`, `server_id`, `filename`, `status`, `stream_id`) VALUES(?, ?, ?, ?, ?);', (int) $rType, (int) $rServerID, $rFilename, (int) $rStatus, (int) $rStreamID);
	}

	/**
	 * Truncate all folder-watch logs. Backs the module's "Clear Watch Logs"
	 * Quick Tool (QuickToolsProviderInterface), moved out of core post.php.
	 *
	 * @return void
	 */
	public static function clearAllLogs() {
		self::db()->query('TRUNCATE `watch_logs`;');
	}
}
