<?php

use XcVm\Core\Events\EventDispatcher;
use XcVm\Module\Watch\WatchCron;
use PHPUnit\Framework\TestCase;

/**
 * DB/filesystem-touching WatchCron methods, driven against TestDb and a
 * throwaway WATCH_TMP_PATH directory.
 */
final class WatchCronTest extends TestCase {

    private TestDb $db;
    private $watchTmp;

    protected function setUp(): void {
        EventDispatcher::setInstance(new EventDispatcher());

        $this->db = new TestDb();
        // cleanupMissing()'s own query, plus every table StreamRepository::
        // deleteStream() touches on the way to actually removing a row (kept
        // minimal — just the columns referenced in its WHERE/SET clauses —
        // so the regression test below exercises the real deletion path
        // instead of a re-implementation of cleanupMissing()'s own SQL).
        $this->db->exec('CREATE TABLE streams (id INTEGER PRIMARY KEY AUTOINCREMENT, type INTEGER, stream_source TEXT);');
        $this->db->exec('CREATE TABLE streams_servers (stream_id INTEGER, server_id INTEGER, server_stream_id INTEGER, parent_id INTEGER);');
        $this->db->exec('CREATE TABLE servers (id INTEGER PRIMARY KEY, server_type INTEGER);');
        $this->db->exec('CREATE TABLE lines_logs (stream_id INTEGER);');
        $this->db->exec('CREATE TABLE mag_claims (stream_id INTEGER);');
        $this->db->exec('CREATE TABLE streams_episodes (stream_id INTEGER);');
        $this->db->exec('CREATE TABLE streams_errors (stream_id INTEGER);');
        $this->db->exec('CREATE TABLE streams_logs (stream_id INTEGER);');
        $this->db->exec('CREATE TABLE streams_options (stream_id INTEGER);');
        $this->db->exec('CREATE TABLE streams_stats (stream_id INTEGER);');
        $this->db->exec('CREATE TABLE recordings (created_id INTEGER, stream_id INTEGER);');
        $this->db->exec('CREATE TABLE lines_activity (stream_id INTEGER);');
        $this->db->exec('CREATE TABLE signals (server_id INTEGER, time INTEGER, custom_data TEXT, cache INTEGER);');
        $this->db->exec('CREATE TABLE bouquets (id INTEGER PRIMARY KEY, bouquet_movies TEXT, bouquet_series TEXT);');
        WatchCron::setDb($this->db);
        \XcVm\Domain\Stream\StreamRepository::setDb($this->db);
        \XcVm\Domain\Vod\MovieService::setDb($this->db);

        // Isolated per-test staging dir for .bouquet fixtures — checkBouquets()
        // itself always globs the real WATCH_TMP_PATH constant, so tests copy
        // their fixtures in and clean them back out (see runCheckBouquetsAgainst()).
        $this->watchTmp = WATCH_TMP_PATH . 'cron_test_' . uniqid() . '/';
        mkdir($this->watchTmp, 0775, true);
    }

    protected function tearDown(): void {
        foreach (glob($this->watchTmp . '*') as $rFile) {
            unlink($rFile);
        }
        @rmdir($this->watchTmp);
    }

    // --- cleanupMissing: regression for the type=3/type=5 mismatch bug -----

    public function testCleanupMissingDeletesASeriesStreamWhoseFileIsGone(): void {
        // Series episodes are stored with streams.type = 5 (see WatchItem's
        // array('movie' => 2, 'series' => 5) mapping). cleanupMissing() used
        // to filter on type = 3, so a series folder's delete_missing pass
        // never matched anything at all — this exercises the real method.
        $this->db->query("INSERT INTO streams (id, type, stream_source) VALUES (1, 5, '[\"s:1:/media/series/show/ep1.mkv\"]');");
        $this->db->query('INSERT INTO streams_servers (stream_id, server_id) VALUES (1, 1);');

        WatchCron::cleanupMissing(array('type' => 'series', 'directory' => '/media/series'), array());

        $this->db->query('SELECT COUNT(*) AS `count` FROM `streams` WHERE `id` = 1;');
        $this->assertSame(0, (int) $this->db->get_col());
    }

    public function testCleanupMissingSkipsFilesOutsideTheWatchedDirectory(): void {
        $this->db->query("INSERT INTO streams (id, type, stream_source) VALUES (1, 5, '[\"s:1:/media/other/show/ep1.mkv\"]');");
        $this->db->query('INSERT INTO streams_servers (stream_id, server_id) VALUES (1, 1);');

        // A file outside $rFolderRow['directory'] must never be treated as
        // "missing" by this folder's cleanup pass.
        WatchCron::cleanupMissing(array('type' => 'series', 'directory' => '/media/series'), array());

        $this->db->query('SELECT COUNT(*) AS `count` FROM `streams` WHERE `id` = 1;');
        $this->assertSame(1, (int) $this->db->get_col());
    }

    public function testCleanupMissingKeepsStreamsWhoseFileStillExists(): void {
        $this->db->query("INSERT INTO streams (id, type, stream_source) VALUES (1, 5, '[\"s:1:/media/series/show/ep1.mkv\"]');");
        $this->db->query('INSERT INTO streams_servers (stream_id, server_id) VALUES (1, 1);');

        WatchCron::cleanupMissing(
            array('type' => 'series', 'directory' => '/media/series'),
            array('/media/series/show/ep1.mkv')
        );

        $this->db->query('SELECT COUNT(*) AS `count` FROM `streams` WHERE `id` = 1;');
        $this->assertSame(1, (int) $this->db->get_col());
    }

    public function testCleanupMissingIgnoresAnUnrecognizedFolderType(): void {
        $this->db->query("INSERT INTO streams (id, type, stream_source) VALUES (1, 5, '[\"s:1:/media/series/show/ep1.mkv\"]');");
        $this->db->query('INSERT INTO streams_servers (stream_id, server_id) VALUES (1, 1);');

        WatchCron::cleanupMissing(array('type' => 'plex', 'directory' => '/media/series'), array());

        $this->db->query('SELECT COUNT(*) AS `count` FROM `streams` WHERE `id` = 1;');
        $this->assertSame(1, (int) $this->db->get_col());
    }

    // --- checkBouquets ---------------------------------------------------

    public function testCheckBouquetsMergesFileQueuedItemsIntoTheBouquetColumns(): void {
        $this->db->query("INSERT INTO bouquets (id, bouquet_movies, bouquet_series) VALUES (1, '[1,2]', '[]');");
        $this->stageBouquetFile(array('type' => 'movie', 'bouquet_id' => 1, 'id' => 3));

        WatchCron::checkBouquets();

        $this->db->query('SELECT `bouquet_movies` FROM `bouquets` WHERE `id` = 1;');
        $this->assertSame('[1,2,3]', $this->db->get_col());
    }

    public function testCheckBouquetsDeduplicatesAlreadyPresentIds(): void {
        $this->db->query("INSERT INTO bouquets (id, bouquet_movies, bouquet_series) VALUES (1, '[3]', '[]');");
        $this->stageBouquetFile(array('type' => 'movie', 'bouquet_id' => 1, 'id' => 3));

        WatchCron::checkBouquets();

        $this->db->query('SELECT `bouquet_movies` FROM `bouquets` WHERE `id` = 1;');
        $this->assertSame('[3]', $this->db->get_col());
    }

    public function testCheckBouquetsIgnoresNonPositiveIds(): void {
        $this->db->query("INSERT INTO bouquets (id, bouquet_movies, bouquet_series) VALUES (1, '[]', '[]');");
        $this->stageBouquetFile(array('type' => 'movie', 'bouquet_id' => 1, 'id' => 0));

        WatchCron::checkBouquets();

        $this->db->query('SELECT `bouquet_movies` FROM `bouquets` WHERE `id` = 1;');
        $this->assertSame('[]', $this->db->get_col());
    }

    public function testCheckBouquetsConsumesTheStagedFiles(): void {
        $this->db->query("INSERT INTO bouquets (id, bouquet_movies, bouquet_series) VALUES (1, '[]', '[]');");
        $rPath = $this->stageBouquetFile(array('type' => 'movie', 'bouquet_id' => 1, 'id' => 3));

        WatchCron::checkBouquets();

        $this->assertFileDoesNotExist($rPath);
    }

    /**
     * checkBouquets() glob()s the module-wide WATCH_TMP_PATH constant, so a
     * fixture has to be written there directly (not into the per-test
     * subdirectory) — tearDown() only ever cleans up $this->watchTmp, so any
     * file left behind here after an assertion failure would leak into other
     * tests; keep every write inside a single call to this helper.
     */
    private function stageBouquetFile(array $rData): string {
        $rPath = WATCH_TMP_PATH . uniqid('test_', true) . '.bouquet';
        file_put_contents($rPath, json_encode($rData));
        return $rPath;
    }
}
