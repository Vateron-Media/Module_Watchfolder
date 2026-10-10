<?php

use PHPUnit\Framework\TestCase;
use XcVm\Core\Cluster\NodeFlows;
use XcVm\Core\Cluster\NodeRole;
use XcVm\Core\Cluster\NodeRpc;
use XcVm\Core\Config\SettingsManager;
use XcVm\Core\Events\EventDispatcher;
use XcVm\Core\Events\Vod\VodImportResultEvent;
use XcVm\Domain\Cluster\NodeRegistry;
use XcVm\Domain\Vod\VodItemImporter;
use XcVm\Infrastructure\Database\DatabaseFactory;
use XcVm\Infrastructure\Tmdb\TmdbApiService;
use XcVm\Module\Watchfolder\WatchCron;
use XcVm\Tests\Support\InstallSchema;

/**
 * MAIN scans the watch folders of the load balancers in the cluster API
 * (mode 1 or 2: no TMDb key there, and in mode 2 no database): it lists
 * them through the node's system API and imports their new files for them.
 */
final class WatchRemoteScanTest extends TestCase {
    private string $rDir;

    protected function setUp(): void {
        $this->rDir = sys_get_temp_dir() . '/watch-remote-' . bin2hex(random_bytes(4)) . '/';
        mkdir($this->rDir);
        foreach (glob(WATCH_TMP_PATH . 'seen_*.json') as $rFile) {
            unlink($rFile);
        }
    }

    protected function tearDown(): void {
        NodeRpc::useTransport(null);
        NodeRole::useServers(null);
        NodeFlows::usePath(null);
        exec('rm -rf ' . escapeshellarg($this->rDir));
    }

    private static function call(string $rMethod, ...$rArgs) {
        $m = new ReflectionMethod(WatchCron::class, $rMethod);
        $m->setAccessible(true);
        return $m->invoke(null, ...$rArgs);
    }

    private static function folder(array $rOverrides = array()): array {
        return $rOverrides + array('id' => 7, 'type' => 'movie', 'directory' => '/mnt/films', 'category_id' => '[1]', 'bouquets' => '[]', 'disable_tmdb' => 0, 'ignore_no_match' => 0, 'fb_bouquets' => '[]', 'fb_category_id' => '[]', 'language' => '', 'read_native' => 0, 'movie_symlink' => 0, 'remove_subtitles' => 0, 'auto_encode' => 0, 'auto_upgrade' => 0, 'fallback_title' => 0, 'ffprobe_input' => 1, 'extract_metadata' => 1, 'transcode_profile_id' => 0, 'duplicate_tmdb' => 0, 'target_container' => 'auto');
    }

    private const SETTINGS = array('max_genres' => 0, 'alternative_titles' => 0, 'fallback_parser' => 0);

    /** A node whose listing has no times: a file is taken once seen 30 s apart, and forgotten once gone. */
    public function testWithoutTimesAFileIsSettledOnceSeenLongEnough(): void {
        $this->assertSame(array(), WatchCron::settled(7, array('/a.mkv', '/b.mkv'), 1000));
        $this->assertSame(array(), WatchCron::settled(7, array('/a.mkv'), 1029), 'not yet 30 s');
        $this->assertSame(array('/a.mkv' => true), WatchCron::settled(7, array('/a.mkv', '/c.mkv'), 1030));
        $this->assertSame(array('/a.mkv' => 1000, '/c.mkv' => 1030), json_decode(file_get_contents(WATCH_TMP_PATH . 'seen_7.json'), true), '/b.mkv no longer listed: forgotten');
    }

    /** A new node answers times, an old one plain paths, and a refusal (outside its Scan Roots) is no listing at all. */
    public function testTheRemoteListingReadsWhatTheNodeAnswers(): void {
        $rAnswers = array();
        NodeRpc::useTransport(static function (string $rKind, array $rServers, array $rData) use (&$rAnswers): string {
            return array_shift($rAnswers);
        });
        $rAnswers = array('{"/mnt/films/a.mkv":1700000000}');
        $this->assertSame(array('files' => array('/mnt/films/a.mkv' => 1700000000), 'subtitles' => array()), self::call('remoteListing', 2, self::folder(), array('mkv')));
        $rAnswers = array('["/mnt/films/a.mkv"]');
        $this->assertSame(array('files' => array('/mnt/films/a.mkv' => null), 'subtitles' => array()), self::call('remoteListing', 2, self::folder(), array('mkv')));
        $rAnswers = array('{"result":false}');
        $this->assertNull(self::call('remoteListing', 2, self::folder(), array('mkv')));
        $rAnswers = array('{"/mnt/films/a.mkv":1700000000}', '["/mnt/films/a.srt"]');
        $this->assertSame(array('/mnt/films/a.srt'), self::call('remoteListing', 2, self::folder(array('auto_subtitles' => 1)), array('mkv'))['subtitles']);
    }

    /** Another server's new, settled files are imported for it; a fresh file and one it already has are not. */
    public function testAnotherServersFilesAreImportedForIt(): void {
        $rNow = 1700001000;
        $rTimes = array('/mnt/films/old.mkv' => $rNow - 3600, '/mnt/films/fresh.mkv' => $rNow - 5, '/mnt/films/known.mkv' => $rNow - 3600, '/mnt/films/seen.mkv' => null);
        $rKnown = array(json_encode(array('s:2:/mnt/films/known.mkv')) => 0);
        $rItems = WatchCron::importItems(self::folder(array('auto_subtitles' => 1)), 2, array_keys($rTimes), $rTimes, array('/mnt/films/seen.mkv' => true), array('/mnt/films/old.srt'), $rKnown, self::SETTINGS, 0, $rNow);
        $this->assertSame(array('s:2:/mnt/films/old.mkv', 's:2:/mnt/films/seen.mkv'), array_column($rItems, 'file'));
        $this->assertTrue($rItems[0]['import']);
        $this->assertSame(array(2), $rItems[0]['servers']);
        $this->assertSame(array('old', 0, 0), array($rItems[0]['title'], $rItems[0]['direct_source'], $rItems[0]['direct_proxy']), 'what import mode reads: the name to match, and no direct source');
        $this->assertSame(array(0, 0), array($rItems[0]['ffprobe_input'], $rItems[0]['extract_metadata']), 'no ffprobe here, where the file is not');
        $this->assertSame(2, $rItems[0]['subtitles']['location']);
        $this->assertSame(array(), $rItems[1]['subtitles']);
    }

    /**
     * What MAIN queues for another server's file goes through core's importer
     * as Series → Import's does: matched on the file's name, and a stream on
     * that server. Import mode reads the name from `title` alone: without
     * one the parser had nothing, and every file ended "No TMDb match".
     */
    public function testAnotherServersEpisodeGoesThroughTheImporter(): void {
        TmdbApiService::requireLibrary();
        $rDb = new TestDb();
        $rDb->exec('CREATE TABLE streams (id INTEGER PRIMARY KEY AUTO_INCREMENT, `type` int NULL, `stream_source` mediumtext NULL, `target_container` varchar(255) NULL, `read_native` tinyint NULL, `movie_symlink` tinyint NULL, `remove_subtitles` tinyint NULL, `transcode_profile_id` int NULL, `direct_source` tinyint NULL, `direct_proxy` tinyint NULL, `order` int NULL, `stream_display_name` mediumtext NULL, `movie_properties` mediumtext NULL, `movie_subtitles` mediumtext NULL, `series_no` int NULL, `added` int NULL, `tmdb_language` varchar(255) NULL);');
        $rDb->exec('CREATE TABLE streams_servers (stream_id INTEGER, server_id INTEGER, parent_id INTEGER);');
        $rDb->exec('CREATE TABLE streams_episodes (season_num INTEGER, series_id INTEGER, stream_id INTEGER, episode_num INTEGER);');
        $rDb->exec('CREATE TABLE streams_series (id INTEGER PRIMARY KEY AUTO_INCREMENT, tmdb_id INTEGER, title TEXT, seasons TEXT);');
        $rDb->exec("INSERT INTO streams_series (id, tmdb_id, title, seasons) VALUES (5, 1399, 'Les Psys', '[]');");
        $rDb->exec(InstallSchema::table('watch_categories')); // core's genre map, read when a payload does not pin it
        DatabaseFactory::set($rDb);
        VodItemImporter::setDb($rDb);
        $rGlobalDb = $GLOBALS['db'] ?? null;
        $GLOBALS['db'] = $rDb;
        $rSettings = SettingsManager::getAll();
        SettingsManager::set(array('tmdb_api_key' => '', 'tmdb_language' => '', 'parse_type' => 'guessit', 'fallback_parser' => 0, 'percentage_match' => 80, 'download_images' => 0));
        $rResults = array();
        EventDispatcher::listen(VodImportResultEvent::class, static function (VodImportResultEvent $rEvent) use (&$rResults): void {
            $rResults[] = $rEvent;
        });
        $rTmdb = new FakeTmdbClient(array(
            'searchTVShow' => fn() => array(new TVShow(array('id' => 1399, 'name' => 'Les Psys', 'first_air_date' => '2026-01-01'))),
            'getTVShow' => fn() => new TVShow(array('id' => 1399, 'name' => 'Les Psys', 'seasons' => array(array('poster_path' => '/s1.jpg')), 'genres' => array(), 'credits' => array('cast' => array(), 'crew' => array()), 'episode_run_time' => array(45))),
            'getSeason' => fn() => new Season(array('episodes' => array(array('episode_number' => 2, 'name' => 'Épisode 2', 'id' => 222, 'air_date' => '2026-01-08', 'overview' => '', 'vote_average' => 7.1, 'still_path' => null)))),
        ));

        $rFile = '/mnt/series/Les Psys/Les Psys (2026) - S01E02 - Épisode 2.mkv';
        $rItems = WatchCron::importItems(self::folder(array('type' => 'series', 'directory' => '/mnt/series')), 2, array($rFile), array($rFile => 1700000000), array(), array(), array(), self::SETTINGS, 0, 1700001000);
        try {
            ob_start();
            try {
                VodItemImporter::run($rItems[0], 60, $rTmdb);
            } finally {
                $rOutput = ob_get_clean();
            }

            $this->assertCount(1, $rResults, $rOutput);
            $this->assertSame(VodImportResultEvent::STATUS_IMPORTED, $rResults[0]->status, $rOutput);
            $rDb->query('SELECT `stream_display_name`, `series_no`, `stream_source`, `direct_source`, `direct_proxy` FROM `streams` WHERE `id` = ?;', $rResults[0]->streamId);
            $rRow = $rDb->get_row();
            $this->assertSame('Les Psys - S01E02 - Épisode 2', $rRow['stream_display_name']);
            $this->assertSame(array('s:2:' . $rFile), json_decode($rRow['stream_source'], true), 'the stream points at the file on its server');
            $this->assertSame(array(5, 0, 0), array((int) $rRow['series_no'], $rRow['direct_source'], $rRow['direct_proxy']), 'a file on a server\'s disk, not a direct source');
            $rDb->query('SELECT `server_id` FROM `streams_servers` WHERE `stream_id` = ?;', $rResults[0]->streamId);
            $this->assertSame('2', (string) $rDb->get_col(), 'and runs there');
        } finally {
            EventDispatcher::unlisten(VodImportResultEvent::class);
            SettingsManager::set($rSettings);
            DatabaseFactory::reset();
            $GLOBALS['db'] = $rGlobalDb;
            @unlink(WATCH_TMP_PATH . 'lock_1399');
            @unlink(WATCH_TMP_PATH . 'series_1399');
        }
    }

    /** This server's own folders import as before: plain paths, stat'ed here, no import mode. */
    public function testThisServersFilesImportAsBefore(): void {
        touch($this->rDir . 'old.mkv', time() - 3600);
        touch($this->rDir . 'fresh.mkv', time());
        $rItems = WatchCron::importItems(self::folder(array('directory' => $this->rDir)), intval(SERVER_ID), array($this->rDir . 'old.mkv', $this->rDir . 'fresh.mkv'), null, array(), array(), array(), self::SETTINGS, 0, time());
        $this->assertSame(array($this->rDir . 'old.mkv'), array_column($rItems, 'file'));
        $this->assertArrayNotHasKey('import', $rItems[0]);
        $this->assertSame(1, $rItems[0]['ffprobe_input']);
    }

    /** MAIN scans its own folders and those of the nodes enrolled in mode 1 or 2; a revoked node is not one. */
    public function testMainScansTheFoldersOfTheNodesInTheClusterApi(): void {
        $rDb = new TestDb();
        $rDb->exec(InstallSchema::table('cluster_nodes'));
        foreach (array(array(2, 2, 'active'), array(3, 1, 'quarantined'), array(4, 2, 'revoked')) as list($rID, $rMode, $rState)) {
            $rDb->query('INSERT INTO `cluster_nodes` (`server_id`, `node_uuid`, `mode`, `state`) VALUES (?, ?, ?, ?);', $rID, 'uuid-' . $rID, $rMode, $rState);
        }
        NodeRegistry::setDb($rDb);
        NodeRole::useServers(static fn(): array => array(intval(SERVER_ID) => array('is_main' => 1)));
        $this->assertSame(array(intval(SERVER_ID), 2, 3), WatchCron::scannedServers());
        NodeRole::useServers(static fn(): array => array(intval(SERVER_ID) => array('is_main' => 0)));
        $this->assertSame(array(intval(SERVER_ID)), WatchCron::scannedServers(), 'a node scans only its own');
    }

    /** A node in the cluster API leaves its folders to MAIN; a legacy one (mode 0) scans them itself. */
    public function testANodeInTheClusterApiLeavesItsFoldersToMain(): void {
        NodeRole::useServers(static fn(): array => array(intval(SERVER_ID) => array('is_main' => 0)));
        $rFlows = $this->rDir . 'flows.json';
        foreach (array(2 => true, 1 => true, 0 => false) as $rMode => $rByMain) {
            file_put_contents($rFlows, json_encode(array('mode' => $rMode, 'flows' => 255, 'state' => 'active')));
            NodeFlows::usePath($rFlows);
            $this->assertSame($rByMain, WatchCron::scannedByMain(), 'mode ' . $rMode);
        }
        NodeRole::useServers(static fn(): array => array(intval(SERVER_ID) => array('is_main' => 1)));
        $this->assertFalse(WatchCron::scannedByMain(), 'MAIN scans');
    }
}
