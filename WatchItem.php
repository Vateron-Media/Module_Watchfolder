<?php

namespace XcVm\Module\Watch;

use XcVm\Core\Config\SettingsManager;
use XcVm\Core\Database\QueryHelper;
use XcVm\Core\Util\ImageUtils;
use XcVm\Domain\Bouquet\BouquetService;
use XcVm\Domain\Stream\StreamProcess;
use XcVm\Streaming\Codec\FfmpegPaths;

/**
 * WatchItem — модуль обработки отдельного элемента Watch Folder (фильм/сериал).
 *
 * @package XC_VM_Module_Watch
 * @author  Divarion_D <https://github.com/Divarion-D>
 * @copyright 2025-2026 Vateron Media
 * @link    https://github.com/Vateron-Media/XC_VM
 * @license AGPL-3.0 https://www.gnu.org/licenses/agpl-3.0.html
 */

class WatchItem {
    use \XcVm\Infrastructure\Database\DatabaseAware;

    /** Как долго доверять series_*.data кэшу перед перечитыванием из БД. */
    private const SERIES_CACHE_TTL_SECONDS = 360;

    /**
     * Добавить элемент в букет (через БД при импорте, иначе — файлом для checkBouquets()).
     *
     * @param string $rType
     * @param int $rBouquetID
     * @param int $rID
     * @param bool $rImport
     * @param string $rSourceFile
     */
    public static function addToBouquet($rType, $rBouquetID, $rID, $rImport, $rSourceFile) {
        if ($rImport) {
            BouquetService::addItems($rType, $rBouquetID, $rID);
        } else {
            file_put_contents(WATCH_TMP_PATH . md5($rSourceFile . '_' . $rType . '_' . $rBouquetID . '_' . $rID) . '.bouquet', json_encode(array('type' => $rType, 'bouquet_id' => $rBouquetID, 'id' => $rID)));
        }
    }

    /**
     * Распарсить имя релиза (guessit / ptn).
     *
     * @param string $rRelease
     * @param string $rType
     * @return array|null
     */
    public static function parserelease($rRelease, $rType = 'guessit') {
        if ($rType == 'guessit') {
            $rCommand = MAIN_HOME . 'bin/guess ' . escapeshellarg($rRelease . '.mkv');
        } else {
            $rCommand = '/usr/bin/python3 ' . MAIN_HOME . 'bin/python/release.py ' . escapeshellarg(str_replace('-', '_', $rRelease));
        }
        $rResult = json_decode(shell_exec($rCommand), true);
        if (!is_array($rResult)) {
            $rResult = array();
        }
        // Явный шаблон "S01 E01" / "S01E01": парсеры (особенно PTN) ломаются на
        // числовых названиях вроде "9-1-1" — берут цифру названия как сезон и
        // выдают мусорный title. Если в исходной строке есть явный SxxExx,
        // надёжно извлекаем сезон/эпизод/название регуляркой и переопределяем.
        if (preg_match('/^(.+?)[\s._-]+[Ss](\d{1,3})[\s._-]*[Ee](\d{1,4})/', $rRelease, $rMatch)) {
            $rCleanTitle = trim(preg_replace('/\s+/', ' ', preg_replace('/[._]+/', ' ', $rMatch[1])));
            // Убираем год в конце названия ("Boston Blue (2025)" → "Boston Blue") —
            // он ломает поиск в TMDB. Год сохраняем отдельно для поиска. Защита:
            // не трогаем, если год — это всё название (например сериал "1923").
            if (preg_match('/^(.*?)[\s._-]*\(?((?:19|20)\d{2})\)?$/', $rCleanTitle, $rYearMatch) && trim($rYearMatch[1]) !== '') {
                $rResult['year'] = intval($rYearMatch[2]);
                $rCleanTitle = trim($rYearMatch[1]);
            }
            if ($rCleanTitle !== '') {
                $rResult['title'] = $rCleanTitle;
                $rResult['season'] = intval($rMatch[2]);
                $rResult['episode'] = intval($rMatch[3]);
            }
        }
        return $rResult;
    }

    /**
     * Получить фильм из кэша по TMDB ID.
     *
     * @param int $rTMDBID
     * @return array|null
     */
    public static function getMovie($rTMDBID) {
        if (file_exists(WATCH_TMP_PATH . 'movie_' . $rTMDBID . '.cache')) {
            return json_decode(file_get_contents(WATCH_TMP_PATH . 'movie_' . $rTMDBID . '.cache'), true);
        }
    }

    /**
     * Получить эпизод из кэша по TMDB ID, сезону и номеру.
     *
     * @param int $rTMDBID
     * @param int $rSeason
     * @param int $rEpisode
     * @return array|null
     */
    public static function getEpisode($rTMDBID, $rSeason, $rEpisode) {
        if (file_exists(WATCH_TMP_PATH . 'series_' . $rTMDBID . '.cache')) {
            $rData = json_decode(file_get_contents(WATCH_TMP_PATH . 'series_' . $rTMDBID . '.cache'), true);
            if (isset($rData[$rSeason . '_' . $rEpisode])) {
                return $rData[$rSeason . '_' . $rEpisode];
            }
        }
    }

    /**
     * Нормализовать заголовок для сравнения.
     *
     * @param string $rTitle
     * @return string
     */
    public static function parseTitle($rTitle) {
        // Нормализуем разделители: дефис, подчёркивание И точку → пробел, чтобы
        // названия вроде "9-1-1" и аббревиатуры вроде "S.W.A.T." совпадали
        // независимо от того, как их выдал парсер: парсеры отдают "S W A T", а
        // TMDb — "S.W.A.T.". Без нормализации точки такие тайтлы давали ~53% и
        // отсеивались по порогу. Точка убрана и из keep-набора регэкспа ниже.
        // Сравнение симметрично для обеих сторон — существующие совпадения не ломает.
        $rTitle = str_replace(array('-', '_', '.'), ' ', $rTitle);
        $rTitle = strtolower(preg_replace("/(?![=\$'€%-])\\p{P}/u", '', $rTitle));
        return trim(preg_replace('/\\s+/u', ' ', $rTitle));
    }

    /**
     * Проверить источник через ffprobe.
     *
     * @param string $rFilename
     * @return array|null
     */
    public static function checksource($rFilename) {
        $rCommand = 'timeout 10 ' . FfmpegPaths::probe() . ' -show_streams -show_format -v quiet ' . escapeshellarg($rFilename) . ' -of json';
        return json_decode(shell_exec($rCommand), true);
    }

    /**
     * Получить сериал по TMDB ID.
     *
     * @param int $rID
     * @return array|null
     */
    public static function getSeriesByTMDB($rID) {
        $db = self::db();
        if (!(file_exists(WATCH_TMP_PATH . 'series_' . intval($rID) . '.data') && time() - filemtime(WATCH_TMP_PATH . 'series_' . intval($rID) . '.data') < self::SERIES_CACHE_TTL_SECONDS)) {
            $db->query('SELECT * FROM `streams_series` WHERE `tmdb_id` = ?;', $rID);
            if ($db->num_rows() == 1) {
                return $db->get_row();
            }
            return null;
        } else {
            return json_decode(file_get_contents(WATCH_TMP_PATH . 'series_' . intval($rID) . '.data'), true);
        }
    }

    /**
     * Получить URL трейлера сериала с TMDB.
     *
     * @param int $rTMDBID
     * @param string|null $rLanguage
     * @return string
     */
    public static function getSeriesTrailer($rTMDBID, $rLanguage = null) {
        $rURL = 'https://api.themoviedb.org/3/tv/' . intval($rTMDBID) . '/videos?api_key=' . urlencode(SettingsManager::getAll()['tmdb_api_key']);
        if ($rLanguage) {
            $rURL .= '&language=' . urlencode($rLanguage);
        } else {
            if (strlen(SettingsManager::getAll()['tmdb_language']) > 0) {
                $rURL .= '&language=' . urlencode(SettingsManager::getAll()['tmdb_language']);
            }
        }
        $rJSON = json_decode(file_get_contents($rURL), true);
        foreach ($rJSON['results'] as $rVideo) {
            if (strtolower($rVideo['type']) == 'trailer' && strtolower($rVideo['site']) == 'youtube') {
                return $rVideo['key'];
            }
        }
        return '';
    }

    /**
     * Получить сериал по ID.
     *
     * @param int $rID
     * @return array|null
     */
    public static function getSerie($rID) {
        $db = self::db();
        $db->query('SELECT * FROM `streams_series` WHERE `id` = ?;', $rID);
        if ($db->num_rows() == 1) {
            return $db->get_row();
        }
    }

    /**
     * Получить следующий порядковый номер для streams.
     *
     * @return int
     */
    public static function getNextOrder() {
        $db = self::db();
        $db->query('SELECT MAX(`order`) AS `order` FROM `streams`;');
        if ($db->num_rows() != 1) {
            return 0;
        }
        return intval($db->get_row()['order']) + 1;
    }

    /**
     * Записать результат обработки файла в watch_logs (дублировался ~10 раз в run()).
     *
     * @param int $rThreadType
     * @param string $rFile
     * @param int $rStatus
     * @param int $rStreamID
     */
    private static function logWatchResult($rThreadType, $rFile, $rStatus, $rStreamID = 0) {
        self::db()->query('INSERT INTO `watch_logs`(`type`, `server_id`, `filename`, `status`, `stream_id`) VALUES(?, ?, ?, ?, ?);', $rThreadType, SERVER_ID, htmlspecialchars($rFile, ENT_QUOTES, 'UTF-8'), $rStatus, $rStreamID);
    }

    /**
     * Общая логика "апгрейда на месте": если новый файл не лучше уже импортированного — не трогаем.
     * Иначе обновляет streams/streams_servers, логирует и передаёт управление $rWriteCache
     * для типоспецифичной перезаписи кэша (movie_*.cache / series_*.cache), затем всегда завершает процесс.
     *
     * @param array $rUpgradeData
     * @param array $rThreadData
     * @param string $rFile
     * @param array $rImportArray
     * @param int $rThreadType
     * @param string $rLabel
     * @param callable $rWriteCache
     * @return never
     */
    private static function applyUpgrade($rUpgradeData, $rThreadData, $rFile, $rImportArray, $rThreadType, $rLabel, callable $rWriteCache) {
        if (!$rThreadData['auto_upgrade']) {
            echo 'Upgrade disabled' . "\n";
            exit();
        }
        if (substr($rUpgradeData['source'], 0, 3 + strlen(strval(SERVER_ID))) != 's:' . SERVER_ID . ':') {
            echo "Old file path doesn't match this server, don't upgrade." . "\n";
            exit();
        }
        list(, $rActualPath) = explode('s:' . SERVER_ID . ':', $rUpgradeData['source']);
        if (file_exists($rActualPath) && filesize($rActualPath) >= filesize($rFile)) {
            echo "File isn't a better source, don't upgrade." . "\n";
            exit();
        }
        echo 'Upgrade ' . $rLabel . '!' . "\n";
        $db = self::db();
        $db->query('UPDATE `streams` SET `stream_source` = ?, `target_container` = ? WHERE `id` = ?;', $rImportArray['stream_source'], $rImportArray['target_container'], $rUpgradeData['id']);
        $db->query('UPDATE `streams_servers` SET `bitrate` = NULL, `current_source` = NULL, `to_analyze` = 0, `pid` = NULL, `stream_started` = NULL, `stream_info` = NULL, `compatible` = 0, `video_codec` = NULL, `audio_codec` = NULL, `resolution` = NULL, `stream_status` = 0 WHERE `stream_id` = ? AND `server_id` = ?', $rUpgradeData['id'], SERVER_ID);
        if ($rThreadData['auto_encode']) {
            StreamProcess::queueMovie($rUpgradeData['id']);
        }
        self::logWatchResult($rThreadType, $rFile, 6);
        $rWriteCache($rUpgradeData);
        exit();
    }

    /**
     * Топ-N имён из cast/crew TMDB (дублировался для movie и series).
     *
     * @param array $rCredits
     * @param int $rLimit
     * @return string[]
     */
    private static function extractTopCast(array $rCredits, $rLimit = 5) {
        $rCast = array();
        foreach (($rCredits['cast'] ?? array()) as $rMember) {
            if (count($rCast) >= $rLimit) {
                break;
            }
            $rCast[] = $rMember['name'];
        }
        return $rCast;
    }

    /**
     * Топ-N режиссёров (department/known_for_department == Directing) из crew TMDB.
     *
     * @param array $rCredits
     * @param int $rLimit
     * @return string[]
     */
    private static function extractTopDirectors(array $rCredits, $rLimit = 5) {
        $rDirectors = array();
        foreach (($rCredits['crew'] ?? array()) as $rMember) {
            if (count($rDirectors) >= $rLimit) {
                break;
            }
            if (($rMember['department'] == 'Directing' || $rMember['known_for_department'] == 'Directing') && !in_array($rMember['name'], $rDirectors)) {
                $rDirectors[] = $rMember['name'];
            }
        }
        return $rDirectors;
    }

    /**
     * Топ-N названий жанров TMDB (лимит передаётся как есть, чтобы сохранить
     * существующее поведение movie (фиксировано 3) и series (настройка max_genres)).
     *
     * @param array $rGenres
     * @param int $rLimit
     * @return string[]
     */
    private static function extractTopGenreNames(array $rGenres, $rLimit) {
        $rNames = array();
        foreach ($rGenres as $rGenre) {
            if (count($rNames) >= $rLimit) {
                break;
            }
            $rNames[] = $rGenre['name'];
        }
        return $rNames;
    }

    /**
     * Дополнить $rCategoryIDs категориями, сопоставленными жанрам TMDB через watch_categories.
     *
     * @param array $rGenres
     * @param array $rWatchCategoryMap watch_categories для нужного type, keyed by genre_id
     * @param int $rMaxGenres
     * @param array $rCategoryIDs
     * @return array
     */
    private static function resolveGenreCategoryIDs(array $rGenres, array $rWatchCategoryMap, $rMaxGenres, array $rCategoryIDs) {
        $rParsed = (0 < $rMaxGenres) ? array_slice($rGenres, 0, (int) $rMaxGenres) : $rGenres;
        foreach ($rParsed as $rGenre) {
            $rGenreId = (int) ($rGenre['id'] ?? 0);
            $rCategoryID = (int) ($rWatchCategoryMap[$rGenreId]['category_id'] ?? 0);
            if ($rCategoryID > 0 && !in_array($rCategoryID, $rCategoryIDs, true)) {
                $rCategoryIDs[] = $rCategoryID;
            }
        }
        return $rCategoryIDs;
    }

    /**
     * Дополнить $rBouquetIDs букетами, сопоставленными жанрам TMDB через watch_categories.
     *
     * @param array $rGenres
     * @param array $rWatchCategoryMap watch_categories для нужного type, keyed by genre_id
     * @param int $rMaxGenres
     * @param array $rBouquetIDs
     * @return array
     */
    private static function resolveGenreBouquetIDs(array $rGenres, array $rWatchCategoryMap, $rMaxGenres, array $rBouquetIDs) {
        $rParsed = (0 < $rMaxGenres) ? array_slice($rGenres, 0, (int) $rMaxGenres) : $rGenres;
        foreach ($rParsed as $rGenre) {
            $rGenreId = (int) ($rGenre['id'] ?? 0);
            $rBouquets = json_decode($rWatchCategoryMap[$rGenreId]['bouquets'] ?? '[]', true) ?: array();
            foreach ($rBouquets as $rBouquetID) {
                if (!in_array($rBouquetID, $rBouquetIDs)) {
                    $rBouquetIDs[] = $rBouquetID;
                }
            }
        }
        return $rBouquetIDs;
    }

    /**
     * Общие поля $rImportArray, выставляемые во всех трёх ветках run() (movie-match,
     * series-match, no-match). $rSetEnableTranscode=false сохраняет существующее
     * поведение series-match, где enable_transcode исторически не выставлялся.
     *
     * @param array $rImportArray
     * @param array $rThreadData
     * @param bool $rSetEnableTranscode
     */
    private static function applyCommonStreamSettings(array &$rImportArray, array $rThreadData, $rSetEnableTranscode = true) {
        $rImportArray['read_native'] = $rThreadData['read_native'];
        $rImportArray['movie_symlink'] = $rThreadData['movie_symlink'];
        $rImportArray['remove_subtitles'] = $rThreadData['remove_subtitles'];
        $rImportArray['transcode_profile_id'] = $rThreadData['transcode_profile_id'];
        if ($rSetEnableTranscode && $rThreadData['transcode_profile_id'] > 0) {
            $rImportArray['enable_transcode'] = 1;
        }
        if ($rThreadData['import']) {
            $rImportArray['direct_source'] = $rThreadData['direct_source'];
            $rImportArray['direct_proxy'] = $rThreadData['direct_proxy'];
        }
        $rImportArray['order'] = self::getNextOrder();
    }

    public static function run($rThreadData = null, $rTimeout = 60) {
        $db = self::db();

        if (!is_array($rThreadData)) {
            echo "watch_item: invalid thread data\n";
            return;
        }

        $rThreadData['file'] = strval($rThreadData['file'] ?? '');
        $rThreadData['directory'] = strval($rThreadData['directory'] ?? '');
        $rThreadData['import'] = !empty($rThreadData['import']);
        $rThreadData['type'] = strval($rThreadData['type'] ?? '');
        $rThreadData['extract_metadata'] = !empty($rThreadData['extract_metadata']);
        $rThreadData['ffprobe_input'] = !empty($rThreadData['ffprobe_input']);
        $rThreadData['fallback_parser'] = !empty($rThreadData['fallback_parser']);
        $rThreadData['fallback_title'] = !empty($rThreadData['fallback_title']);
        $rThreadData['disable_tmdb'] = !empty($rThreadData['disable_tmdb']);
        $rThreadData['ignore_no_match'] = !empty($rThreadData['ignore_no_match']);
        $rThreadData['alternative_titles'] = !empty($rThreadData['alternative_titles']);

        $rSettings = SettingsManager::getAll();

        if (($rThreadData['file'] === '') || (!$rThreadData['import'] && $rThreadData['directory'] === '')) {
            echo "watch_item: missing file or directory\n";
            return;
        }

        if (!in_array($rThreadData['type'], array('movie', 'series'), true)) {
            echo "watch_item: unsupported type\n";
            return;
        }

        if (strpos($rThreadData['file'], $rThreadData['directory']) === 0 || $rThreadData['import']) {
            $rWatchCategories = (is_array($rThreadData['watch_categories'] ?? null) ? $rThreadData['watch_categories'] : array());
            if (!isset($rWatchCategories[1]) || !is_array($rWatchCategories[1])) {
                $rWatchCategories[1] = array();
            }
            if (!isset($rWatchCategories[2]) || !is_array($rWatchCategories[2])) {
                $rWatchCategories[2] = array();
            }
            $rLanguage = null;
            $rReleaseSeason = NULL;
            $rReleaseEpisode = NULL;
            $rYear = null;

            if (!empty($rThreadData['language'])) {
                $rTMDB = new \TMDB($rSettings['tmdb_api_key'], $rThreadData['language']);
                $rLanguage = $rThreadData['language'];
            } else {
                if (!empty($rSettings['tmdb_language'])) {
                    $rTMDB = new \TMDB($rSettings['tmdb_api_key'], $rSettings['tmdb_language']);
                } else {
                    $rTMDB = new \TMDB($rSettings['tmdb_api_key']);
                }
            }
            if ($rThreadData['type'] != 'movie') {
                $rThreadData['extract_metadata'] = false;
            }
            $rImportArray = QueryHelper::verifyPostTable('streams');
            if (!is_array($rImportArray)) {
                echo "watch_item: failed to prepare import schema\n";
                return;
            }
            $rImportArray['type'] = array('movie' => 2, 'series' => 5)[$rThreadData['type']];
            if ($rImportArray['type']) {
                $rThreadType = array('movie' => 1, 'series' => 2)[$rThreadData['type']];
                $rFile = $rThreadData['file'];
                if ($rThreadData['import']) {
                    $rImportArray['stream_source'] = json_encode(array($rFile), JSON_UNESCAPED_UNICODE);
                } else {
                    $rImportArray['stream_source'] = json_encode(array('s:' . SERVER_ID . ':' . $rFile), JSON_UNESCAPED_UNICODE);
                    $db->query('DELETE FROM `watch_logs` WHERE `filename` = ? AND `type` = ? AND `server_id` = ?;', htmlspecialchars($rFile, ENT_QUOTES, 'UTF-8'), $rThreadType, SERVER_ID);
                }
                if ($rThreadData['target_container'] != 'auto' && $rThreadData['target_container']) {
                    $rImportArray['target_container'] = $rThreadData['target_container'];
                } else {
                    $rImportArray['target_container'] = pathinfo(explode('?', $rFile)[0])['extension'];
                }
                if (empty($rImportArray['target_container'])) {
                    $rImportArray['target_container'] = 'mp4';
                }
                $rSourceData = null;
                if ($rThreadData['ffprobe_input'] || $rThreadData['extract_metadata']) {
                    $rSourceData = self::checksource($rFile);
                }
                if (!$rThreadData['ffprobe_input'] || isset($rSourceData['streams'])) {
                    $rMatch = $rPaths = null;
                    $rMetaMatch = false;
                    if ($rThreadData['extract_metadata'] && isset($rSourceData['format']) && !empty($rSourceData['tags']['title'])) {
                        if (!empty($rSourceData['tags']['date'])) {
                            $rYear = (intval(explode('-', $rSourceData['tags']['date'])[0]));
                        }
                        $rPaths = array($rSourceData['tags']['title']);
                        $rMetaMatch = true;
                    }
                    if (!$rPaths) {
                        if ($rThreadData['fallback_title']) {
                            $rPaths = array(pathinfo($rFile)['filename'], basename(pathinfo($rFile)['dirname']));
                        } else {
                            $rPaths = array(pathinfo($rFile)['filename']);
                        }
                        $rMetaMatch = false;
                    }
                    foreach ($rPaths as $rFilename) {
                        echo 'Scanning: ' . $rFilename . "\n";
                        $rTitle = null;
                        $rAltTitle = null;
                        if ($rThreadData['import']) {
                            $rFilename = $rThreadData['title'];
                        }
                        if ($rThreadData['fallback_parser'] && !$rThreadData['disable_tmdb'] && !$rMetaMatch) {
                            $rParseTypes = array($rSettings['parse_type'], ($rSettings['parse_type'] == 'guessit' ? 'ptn' : 'guessit'));
                        } else {
                            $rParseTypes = array($rSettings['parse_type']);
                        }
                        foreach ($rParseTypes as $rParseType) {
                            if ($rThreadData['disable_tmdb'] || $rMetaMatch) {
                            } else {
                                $rRelease = self::parserelease($rFilename, $rParseType);
                                $rTitle = $rRelease['title'];
                                if (isset($rRelease['excess'])) {
                                    // Strip the excess token as a WHOLE WORD — never
                                    // trim($title, $excess): its 2nd arg is a char-mask,
                                    // so trim('Marshals…', 'MULTI') eats the leading 'M'
                                    // and yields 'arshals…', breaking the TMDb search.
                                    $rExcess = is_array($rRelease['excess']) ? ($rRelease['excess'][0] ?? '') : $rRelease['excess'];
                                    if ($rExcess !== '') {
                                        $rTitle = preg_replace('/\b' . preg_quote((string) $rExcess, '/') . '\b/u', ' ', $rTitle);
                                        $rTitle = trim(preg_replace('/\s+/u', ' ', $rTitle));
                                    }
                                }
                                if (isset($rRelease['group'])) {
                                    $rAltTitle = $rTitle . '-' . $rRelease['group'];
                                } else {
                                    if (isset($rRelease['alternative_title'])) {
                                        $rAltTitle = $rTitle . ' - ' . $rRelease['alternative_title'];
                                    }
                                }
                                if (isset($rRelease['year'])) {
                                    if (!$rYear) {
                                        $rYear = intval($rRelease['year']);
                                    }
                                }

                                if ($rThreadData['type'] != 'movie') {
                                    if ($rReleaseSeason == null && isset($rRelease['season'])) {
                                        $rReleaseSeason = $rRelease['season'];
                                    }
                                    if ($rReleaseEpisode == null && isset($rRelease['episode'])) {
                                        if (is_array($rRelease['episode'])) {
                                            $rReleaseEpisode = $rRelease['episode'][0];
                                        } else {
                                            $rReleaseEpisode = $rRelease['episode'];
                                        }
                                    }
                                }
                            }
                            if (!($rThreadData['type'] == 'series' && (!$rReleaseSeason || !$rReleaseEpisode))) {
                                if (!$rTitle) {
                                    $rTitle = $rFilename;
                                }
                                echo 'Title: ' . $rTitle . "\n";
                                if (!$rThreadData['disable_tmdb']) {
                                    $rMatches = array();
                                    $rSearchYear = $rYear;
                                    foreach (range(0, 1) as $rIgnoreYear) {
                                        if ($rIgnoreYear) {
                                            if ($rSearchYear) {
                                                $rSearchYear = null;
                                            } else {
                                                break;
                                            }
                                        }
                                        if ($rThreadData['type'] == 'movie') {
                                            print_r('Searching Movie: ' . $rTitle . ' Year: ' . $rSearchYear . "\n");
                                            $rResults = $rTMDB->searchMovie($rTitle, $rSearchYear);
                                        } else {
                                            print_r('Searching TV Show: ' . $rTitle . ' Year: ' . $rSearchYear . "\n");
                                            $rResults = $rTMDB->searchTVShow($rTitle, $rSearchYear);
                                        }
                                        foreach ($rResults as $rResultArr) {
                                            $tmdbTitles = [];

                                            if ($rThreadData['type'] === 'movie') {
                                                $tmdbTitles[] = $rResultArr->get('title');
                                                $tmdbTitles[] = $rResultArr->get('original_title');
                                            } else {
                                                $tmdbTitles[] = $rResultArr->get('name');
                                                $tmdbTitles[] = $rResultArr->get('original_name');
                                            }

                                            $tmdbTitles = array_filter($tmdbTitles);

                                            $rPercentage = 0;
                                            $rPercentageAlt = 0;

                                            foreach ($tmdbTitles as $tmdbTitle) {
                                                similar_text(self::parseTitle($rTitle), self::parseTitle($tmdbTitle), $p);
                                                $rPercentage = max($rPercentage, $p);

                                                if ($rAltTitle) {
                                                    similar_text(self::parseTitle($rAltTitle), self::parseTitle($tmdbTitle), $pAlt);
                                                    $rPercentageAlt = max($rPercentageAlt, $pAlt);
                                                }
                                            }

                                            $rPercentageAlt = 0;
                                            if ($rAltTitle) {
                                                similar_text(self::parseTitle($rAltTitle), self::parseTitle(($rResultArr->get('title') ?: $rResultArr->get('name'))), $rPercentageAlt);
                                            }
                                            $rReleaseDate = (string) ($rResultArr->get('release_date') ?: $rResultArr->get('first_air_date'));
                                            $rReleaseYear = intval(substr($rReleaseDate, 0, 4));
                                            if ($rSettings['percentage_match'] <= $rPercentage || $rSettings['percentage_match'] <= $rPercentageAlt) {
                                                if ($rSearchYear && !in_array($rReleaseYear, range(intval($rSearchYear) - 1, intval($rSearchYear) + 1))) {
                                                } else {
                                                    if ($rAltTitle && self::parseTitle(($rResultArr->get('title') ?: $rResultArr->get('name'))) == self::parseTitle($rAltTitle)) {
                                                        $rMatches = array(array('percentage' => 100, 'data' => $rResultArr));
                                                        break;
                                                    }
                                                    foreach ($tmdbTitles as $tmdbTitle) {
                                                        if ($rAltTitle && self::parseTitle($tmdbTitle) === self::parseTitle($rAltTitle)) {
                                                            $rMatches = [['percentage' => 100, 'data' => $rResultArr]];
                                                            break 2;
                                                        }

                                                        if (!$rAltTitle && self::parseTitle($tmdbTitle) === self::parseTitle($rTitle)) {
                                                            $rMatches = [['percentage' => 100, 'data' => $rResultArr]];
                                                            break 2;
                                                        }
                                                    }
                                                    $rMatches[] = array('percentage' => $rPercentage, 'data' => $rResultArr);
                                                }
                                            } else {
                                                if ($rThreadData['alternative_titles'] && in_array($rReleaseYear, range(intval($rSearchYear) - 1, intval($rSearchYear) + 1))) {
                                                    $rPartialMatch = false;

                                                    foreach ($tmdbTitles as $tmdbTitle) {
                                                        if (strpos(self::parseTitle($rTitle), self::parseTitle($tmdbTitle)) === 0) {
                                                            $rPartialMatch = true;
                                                            break;
                                                        }

                                                        if ($rAltTitle && strpos(self::parseTitle($rAltTitle), self::parseTitle($tmdbTitle)) === 0) {
                                                            $rPartialMatch = true;
                                                            break;
                                                        }
                                                    }
                                                    if ($rPartialMatch) {
                                                        if ($rThreadData['type'] == 'movie') {
                                                            $rTitleData = $rTMDB->getMovieTitles($rResultArr->get('id'));
                                                        } else {
                                                            $rTitleData = $rTMDB->getSeriesTitles($rResultArr->get('id'));
                                                        }
                                                        $rAlternativeTitles = (is_array($rTitleData) && isset($rTitleData['titles']) && is_array($rTitleData['titles']) ? $rTitleData['titles'] : array());
                                                        foreach ($rAlternativeTitles as $rAlternativeTitle) {
                                                            if ($rAltTitle && self::parseTitle($rAlternativeTitle['title']) == self::parseTitle($rAltTitle)) {
                                                                $rMatches = array(array('percentage' => 100, 'data' => $rResultArr));
                                                                break;
                                                            }
                                                            if (self::parseTitle($rAlternativeTitle['title']) != self::parseTitle($rTitle) || $rAltTitle) {
                                                            } else {
                                                                $rMatches = array(array('percentage' => 100, 'data' => $rResultArr));
                                                                break;
                                                            }
                                                        }
                                                    }
                                                }
                                            }
                                        }
                                        if (count($rMatches) > 0) {
                                            break;
                                        }
                                    }
                                    if (count($rMatches) > 0) {
                                        $rMax = max(array_column($rMatches, 'percentage'));
                                        $rKeys = array_filter(array_map(function ($rMatches) use ($rMax) {
                                            return ($rMatches['percentage'] == $rMax ? $rMatches['data'] : null);
                                        }, $rMatches));
                                        list($rMatch) = array_values($rKeys);
                                    }
                                }
                                if ($rMatch) {
                                    break;
                                }
                            } else {
                                self::logWatchResult($rThreadType, $rFile, 4);
                                exit();
                            }
                        }
                    }
                    if (!$rMatch && !$rThreadData['ignore_no_match']) {
                        echo 'No match!' . "\n";
                        self::logWatchResult($rThreadType, $rFile, 4);
                        exit();
                    }
                    $rBouquetIDs = array();
                    $rCategoryIDs = array();
                    if (!empty($rThreadData['category_id'])) {
                        if (is_array($rThreadData['category_id'])) {
                            $rCategoryIDs = array_map('intval', $rThreadData['category_id']);
                        } else {
                            $rCategoryIDs = array(intval($rThreadData['category_id']));
                        }
                    }
                    if (!empty($rThreadData['bouquets'])) {
                        if (is_array($rThreadData['bouquets'])) {
                            $rBouquetIDs = array_map('intval', $rThreadData['bouquets']);
                        } else {
                            $rBouquetIDs = json_decode($rThreadData['bouquets'], true);
                        }
                    }
                    if ($rMatch) {
                        if ($rThreadData['type'] == 'movie') {
                            if ($rThreadData['duplicate_tmdb']) {
                                $rUpgradeData = null;
                            } else {
                                $rUpgradeData = self::getMovie($rMatch->get('id'));
                            }

                            if ($rUpgradeData) {
                                self::applyUpgrade($rUpgradeData, $rThreadData, $rFile, $rImportArray, $rThreadType, 'movie', function ($rUpgradeData) use ($rMatch, $rFile) {
                                    file_put_contents(WATCH_TMP_PATH . 'movie_' . $rMatch->get('id') . '.cache', json_encode(array('id' => $rUpgradeData['id'], 'source' => 's:' . SERVER_ID . ':' . $rFile)));
                                });
                            }
                            $rMovie = $rTMDB->getMovie($rMatch->get('id'));
                            $rMovieData = json_decode($rMovie->getJSON(), true);
                            $rMovieData['trailer'] = $rMovie->getTrailer();
                            $rThumb = 'https://image.tmdb.org/t/p/w600_and_h900_bestv2' . $rMovieData['poster_path'];
                            $rBG = 'https://image.tmdb.org/t/p/w1280' . $rMovieData['backdrop_path'];
                            if ($rSettings['download_images']) {
                                $rThumb = ImageUtils::downloadImage($rThumb);
                                $rBG = ImageUtils::downloadImage($rBG);
                            }
                            $rCast = self::extractTopCast($rMovieData['credits']);
                            $rDirectors = self::extractTopDirectors($rMovieData['credits']);
                            $rCountry = '';
                            if (isset($rMovieData['production_countries'][0]['name'])) {
                                $rCountry = $rMovieData['production_countries'][0]['name'];
                            }
                            $rGenres = self::extractTopGenreNames($rMovieData['genres'], 3);
                            $rSeconds = intval($rMovieData['runtime']) * 60;
                            $rImportArray['stream_display_name'] = $rMovieData['title'];
                            if (strlen($rMovieData['release_date']) > 0) {
                                $rImportArray['year'] = intval(substr($rMovieData['release_date'], 0, 4));
                            }
                            $rImportArray['tmdb_id'] = ($rMovieData['id'] ?: null);
                            $rImportArray['movie_properties'] = array('kinopoisk_url' => 'https://www.themoviedb.org/movie/' . $rMovieData['id'], 'tmdb_id' => $rMovieData['id'], 'name' => $rMovieData['title'], 'o_name' => $rMovieData['original_title'], 'cover_big' => $rThumb, 'movie_image' => $rThumb, 'release_date' => $rMovieData['release_date'], 'episode_run_time' => $rMovieData['runtime'], 'youtube_trailer' => $rMovieData['trailer'], 'director' => implode(', ', $rDirectors), 'actors' => implode(', ', $rCast), 'cast' => implode(', ', $rCast), 'description' => $rMovieData['overview'], 'plot' => $rMovieData['overview'], 'age' => '', 'mpaa_rating' => '', 'rating_count_kinopoisk' => 0, 'country' => $rCountry, 'genre' => implode(', ', $rGenres), 'backdrop_path' => array($rBG), 'duration_secs' => $rSeconds, 'duration' => sprintf('%02d:%02d:%02d', $rSeconds / 3600, ($rSeconds / 60) % 60, $rSeconds % 60), 'video' => array(), 'audio' => array(), 'bitrate' => 0, 'rating' => $rMovieData['vote_average']);
                            $rImportArray['rating'] = ($rImportArray['movie_properties']['rating'] ?: 0);
                            self::applyCommonStreamSettings($rImportArray, $rThreadData);
                            $rImportArray['tmdb_language'] = $rLanguage;
                            if (count($rCategoryIDs) == 0 && !empty($rMovieData['genres']) && is_array($rMovieData['genres'])) {
                                $rCategoryIDs = self::resolveGenreCategoryIDs($rMovieData['genres'], $rWatchCategories[1], $rThreadData['max_genres'], $rCategoryIDs);
                            }
                            if (count($rBouquetIDs) == 0) {
                                $rBouquetIDs = self::resolveGenreBouquetIDs($rMovieData['genres'] ?? [], $rWatchCategories[1], $rThreadData['max_genres'], $rBouquetIDs);
                            }
                        } else {
                            $rShow = $rTMDB->getTVShow($rMatch->get('id'));
                            if ($rThreadData['duplicate_tmdb']) {
                                $rUpgradeData = null;
                            } else {
                                $rUpgradeData = self::getEpisode($rMatch->get('id'), $rReleaseSeason, $rReleaseEpisode);
                            }
                            if ($rUpgradeData) {
                                self::applyUpgrade($rUpgradeData, $rThreadData, $rFile, $rImportArray, $rThreadType, 'episode', function ($rUpgradeData) use ($rMatch, $rReleaseSeason, $rReleaseEpisode, $rFile) {
                                    $rCacheData = json_decode(file_get_contents(WATCH_TMP_PATH . 'series_' . $rMatch->get('id') . '.cache'), true);
                                    $rCacheData[$rReleaseSeason . '_' . $rReleaseEpisode] = array('id' => $rUpgradeData['id'], 'source' => 's:' . SERVER_ID . ':' . $rFile);
                                    file_put_contents(WATCH_TMP_PATH . 'series_' . $rMatch->get('id') . '.cache', json_encode($rCacheData));
                                });
                            }
                            $rShowData = json_decode($rShow->getJSON(), true);
                            if ($rShowData['id']) {
                                while (file_exists(WATCH_TMP_PATH . 'lock_' . intval($rShowData['id']))) {
                                    if ($rTimeout < time() - filemtime(WATCH_TMP_PATH . 'lock_' . intval($rShowData['id']))) {
                                        unlink(WATCH_TMP_PATH . 'lock_' . intval($rShowData['id']));
                                    }
                                    usleep(100000);
                                }
                                $rFileLock = fopen(WATCH_TMP_PATH . 'lock_' . intval($rShowData['id']), 'w');
                                while (!flock($rFileLock, LOCK_EX)) {
                                    usleep(100000);
                                }
                                fwrite($rFileLock, time());
                                $rSeasonData = array();
                                foreach ($rShowData['seasons'] as $rSeason) {
                                    $rSeason['cover'] = 'https://image.tmdb.org/t/p/w600_and_h900_bestv2' . $rSeason['poster_path'];
                                    if ($rSettings['download_images']) {
                                        $rSeason['cover'] = ImageUtils::downloadImage($rSeason['cover'], 2);
                                    }
                                    $rSeason['cover_big'] = $rSeason['cover'];
                                    unset($rSeason['poster_path']);
                                    $rSeasonData[] = $rSeason;
                                }
                                $rSeries = self::getSeriesByTMDB($rShowData['id']);
                                if (!$rSeries) {
                                    $rSeriesArray = array('title' => $rShowData['name'], 'category_id' => array(), 'episode_run_time' => 0, 'tmdb_id' => $rShowData['id'], 'cover' => '', 'genre' => '', 'plot' => $rShowData['overview'], 'cast' => '', 'rating' => $rShowData['vote_average'], 'director' => '', 'release_date' => $rShowData['first_air_date'], 'last_modified' => time(), 'seasons' => $rSeasonData, 'backdrop_path' => array(), 'youtube_trailer' => '', 'year' => null);
                                    $rSeriesArray['youtube_trailer'] = self::getSeriesTrailer($rShowData['id'], (!empty($rThreadData['language']) ? $rThreadData['language'] : $rSettings['tmdb_language']));
                                    $rSeriesArray['cover'] = 'https://image.tmdb.org/t/p/w600_and_h900_bestv2' . $rShowData['poster_path'];
                                    $rSeriesArray['cover_big'] = $rSeriesArray['cover'];
                                    $rSeriesArray['backdrop_path'] = array('https://image.tmdb.org/t/p/w1280' . $rShowData['backdrop_path']);
                                    if ($rSettings['download_images']) {
                                        $rSeriesArray['cover'] = ImageUtils::downloadImage($rSeriesArray['cover'], 2);
                                        $rSeriesArray['backdrop_path'] = array(ImageUtils::downloadImage($rSeriesArray['backdrop_path'][0]));
                                    }
                                    $rCast = self::extractTopCast($rShowData['credits']);
                                    $rSeriesArray['cast'] = implode(', ', $rCast);
                                    $rDirectors = self::extractTopDirectors($rShowData['credits']);
                                    $rSeriesArray['director'] = implode(', ', $rDirectors);
                                    $rGenres = self::extractTopGenreNames($rShowData['genres'], $rThreadData['max_genres']);
                                    if ($rShowData['first_air_date']) {
                                        $rSeriesArray['year'] = intval(substr($rShowData['first_air_date'], 0, 4));
                                    }
                                    $rSeriesArray['genre'] = implode(', ', $rGenres);
                                    $rSeriesArray['episode_run_time'] = intval($rShowData['episode_run_time'][0] ?? 0);
                                    if (count($rCategoryIDs) == 0) {
                                        $rCategoryIDs = self::resolveGenreCategoryIDs($rShowData['genres'], $rWatchCategories[2], $rThreadData['max_genres'], $rCategoryIDs);
                                    }
                                    if (count($rCategoryIDs) == 0 && !empty($rThreadData['fb_category_id'])) {
                                        if (is_array($rThreadData['fb_category_id'])) {
                                            $rCategoryIDs = array_map('intval', $rThreadData['fb_category_id']);
                                        } else {
                                            $rCategoryIDs = array(intval($rThreadData['fb_category_id']));
                                        }
                                    }
                                    if (count($rBouquetIDs) == 0) {
                                        $rBouquetIDs = self::resolveGenreBouquetIDs($rShowData['genres'], $rWatchCategories[2], $rThreadData['max_genres'], $rBouquetIDs);
                                    }
                                    if (count($rBouquetIDs) == 0 && !empty($rThreadData['fb_bouquets'])) {
                                        if (is_array($rThreadData['fb_bouquets'])) {
                                            $rBouquetIDs = array_map('intval', $rThreadData['fb_bouquets']);
                                        } else {
                                            $rBouquetIDs = json_decode($rThreadData['fb_bouquets'], true);
                                        }
                                    }
                                    if (count($rCategoryIDs) != 0) {
                                        $rSeriesArray['tmdb_language'] = $rLanguage;
                                        $rSeriesArray['category_id'] = '[' . implode(',', array_map('intval', $rCategoryIDs)) . ']';
                                        $rPrepare = QueryHelper::prepareArray($rSeriesArray);
                                        $rQuery = 'INSERT INTO `streams_series`(' . $rPrepare['columns'] . ') VALUES(' . $rPrepare['placeholder'] . ');';
                                        if ($db->query($rQuery, ...$rPrepare['data'])) {
                                            $rInsertID = $db->last_insert_id();
                                            $rSeries = self::getSerie($rInsertID);
                                            file_put_contents(WATCH_TMP_PATH . 'series_' . intval($rShowData['id']), json_encode($rSeries));
                                            foreach ($rBouquetIDs as $rBouquet) {
                                                self::addToBouquet('series', $rBouquet, $rInsertID, $rThreadData['import'], $rFile);
                                            }
                                        } else {
                                            $rSeries = null;
                                        }
                                    } else {
                                        self::logWatchResult($rThreadType, $rFile, 3);
                                        exit();
                                    }
                                } else {
                                    $db->query('UPDATE `streams_series` SET `seasons` = ? WHERE `id` = ?;', json_encode($rSeasonData, JSON_UNESCAPED_UNICODE), $rSeries['id']);
                                    if (!file_exists(WATCH_TMP_PATH . 'series_' . intval($rShowData['id']))) {
                                        file_put_contents(WATCH_TMP_PATH . 'series_' . intval($rShowData['id']), json_encode($rSeries));
                                    }
                                }
                                flock($rFileLock, LOCK_UN);
                                unlink(WATCH_TMP_PATH . 'lock_' . intval($rShowData['id']));
                                self::applyCommonStreamSettings($rImportArray, $rThreadData, false);
                                if ($rReleaseSeason && $rReleaseEpisode) {
                                    if (is_array($rRelease['episode']) && count($rRelease['episode']) == 2) {
                                        $rImportArray['stream_display_name'] = $rShowData['name'] . ' - S' . sprintf('%02d', intval($rReleaseSeason)) . 'E' . sprintf('%02d', $rRelease['episode'][0]) . '-' . sprintf('%02d', $rRelease['episode'][1]);
                                    } else {
                                        $rImportArray['stream_display_name'] = $rShowData['name'] . ' - S' . sprintf('%02d', intval($rReleaseSeason)) . 'E' . sprintf('%02d', $rReleaseEpisode);
                                    }
                                    $rEpisodes = json_decode($rTMDB->getSeason($rShowData['id'], intval($rReleaseSeason))->getJSON(), true);
                                    foreach (($rEpisodes['episodes'] ?? array()) as $rEpisode) {
                                        if (intval($rEpisode['episode_number']) == $rReleaseEpisode) {
                                            $rImage = '';
                                            if (strlen($rEpisode['still_path'] ?? '') > 0) {
                                                $rImage = 'https://image.tmdb.org/t/p/w1280' . $rEpisode['still_path'];
                                                if ($rSettings['download_images']) {
                                                    $rImage = ImageUtils::downloadImage($rImage, 5);
                                                }
                                            }
                                            if (strlen($rEpisode['name'] ?? '') > 0) {
                                                $rImportArray['stream_display_name'] .= ' - ' . $rEpisode['name'];
                                            }
                                            $rSeconds = intval($rShowData['episode_run_time'][0] ?? 0) * 60;
                                            $rImportArray['movie_properties'] = array('tmdb_id' => $rEpisode['id'], 'release_date' => $rEpisode['air_date'], 'plot' => $rEpisode['overview'], 'duration_secs' => $rSeconds, 'duration' => sprintf('%02d:%02d:%02d', $rSeconds / 3600, ($rSeconds / 60) % 60, $rSeconds % 60), 'movie_image' => $rImage, 'video' => array(), 'audio' => array(), 'bitrate' => 0, 'rating' => $rEpisode['vote_average'], 'season' => $rReleaseSeason);
                                            if (strlen($rImportArray['movie_properties']['movie_image']) == 0) {
                                                unset($rImportArray['movie_properties']['movie_image']);
                                            }
                                        }
                                    }
                                    if (strlen($rImportArray['stream_display_name']) == 0) {
                                        $rImportArray['stream_display_name'] = 'No Episode Title';
                                    }
                                }
                            }
                        }
                    } else {
                        if ($rThreadData['type'] == 'movie') {
                            $rImportArray['stream_display_name'] = $rTitle;
                            if ($rYear) {
                                $rImportArray['year'] = $rYear;
                            }
                        } else {
                            if ($rReleaseSeason && $rReleaseEpisode) {
                                $rImportArray['stream_display_name'] = $rTitle . ' - S' . sprintf('%02d', intval($rReleaseSeason)) . 'E' . sprintf('%02d', $rReleaseEpisode) . ' - ';
                            }
                        }
                        self::applyCommonStreamSettings($rImportArray, $rThreadData);
                        $rImportArray['tmdb_language'] = $rLanguage;
                    }
                    if ($rThreadData['type'] == 'movie') {
                        if (count($rCategoryIDs) == 0 && !empty($rThreadData['fb_category_id'])) {
                            if (is_array($rThreadData['fb_category_id'])) {
                                $rCategoryIDs = array_map('intval', $rThreadData['fb_category_id']);
                            } else {
                                $rCategoryIDs = array(intval($rThreadData['fb_category_id']));
                            }
                        }
                        if (count($rBouquetIDs) == 0 && !empty($rThreadData['fb_bouquets'])) {
                            if (is_array($rThreadData['fb_bouquets'])) {
                                $rBouquetIDs = array_map('intval', $rThreadData['fb_bouquets']);
                            } else {
                                $rBouquetIDs = json_decode($rThreadData['fb_bouquets'], true);
                            }
                        }
                        $rImportArray['category_id'] = '[' . implode(',', array_map('intval', $rCategoryIDs)) . ']';
                        if (count($rCategoryIDs) == 0) {
                            self::logWatchResult($rThreadType, $rFile, 3);
                            exit();
                        }
                    } else {
                        if ($rSeries) {
                            $rImportArray['series_no'] = $rSeries['id'];
                        } else {
                            self::logWatchResult($rThreadType, $rFile, 4);
                            exit();
                        }
                    }
                    if ($rThreadData['subtitles']) {
                        $rImportArray['movie_subtitles'] = $rThreadData['subtitles'];
                    }
                    $rImportArray['added'] = time();
                    $rPrepare = QueryHelper::prepareArray($rImportArray);
                    $rQuery = 'INSERT INTO `streams`(' . $rPrepare['columns'] . ') VALUES(' . $rPrepare['placeholder'] . ');';
                    if ($db->query($rQuery, ...$rPrepare['data'])) {
                        $rInsertID = $db->last_insert_id();
                        if ($rThreadData['import']) {
                            foreach ($rThreadData['servers'] as $rServerID) {
                                $db->query('INSERT INTO `streams_servers`(`stream_id`, `server_id`, `parent_id`) VALUES(?, ?, NULL);', $rInsertID, $rServerID);
                            }
                        } else {
                            $db->query('INSERT INTO `streams_servers`(`stream_id`, `server_id`, `parent_id`) VALUES(?, ?, NULL);', $rInsertID, SERVER_ID);
                        }
                        if ($rThreadData['type'] == 'movie') {
                            if ($rMatch && !$rThreadData['import']) {
                                file_put_contents(WATCH_TMP_PATH . 'movie_' . $rMatch->get('id') . '.cache', json_encode(array('id' => $rInsertID, 'source' => 's:' . SERVER_ID . ':' . $rFile)));
                            }
                            foreach ($rBouquetIDs as $rBouquet) {
                                self::addToBouquet('movie', $rBouquet, $rInsertID, $rThreadData['import'], $rFile);
                            }
                        } else {
                            $db->query('INSERT INTO `streams_episodes`(`season_num`, `series_id`, `stream_id`, `episode_num`) VALUES(?, ?, ?, ?);', $rReleaseSeason, $rSeries['id'], $rInsertID, $rReleaseEpisode);
                        }
                        if ($rThreadData['auto_encode']) {
                            if ($rThreadData['import']) {
                                foreach ($rThreadData['servers'] as $rServerID) {
                                    StreamProcess::queueMovie($rInsertID, $rServerID);
                                }
                            } else {
                                StreamProcess::queueMovie($rInsertID);
                            }
                        }
                        echo 'Success!' . "\n";
                        self::logWatchResult($rThreadType, $rFile, 1, $rInsertID);
                        exit();
                    } else {
                        echo 'Insert failed!' . "\n";
                        self::logWatchResult($rThreadType, $rFile, 2);
                        exit();
                    }
                } else {
                    echo 'File is broken!' . "\n";
                    self::logWatchResult($rThreadType, $rFile, 5);
                    exit();
                }
            } else {
                exit();
            }
        } else {
            echo 'Incorrect root directory!';
            exit();
        }
    }
}
