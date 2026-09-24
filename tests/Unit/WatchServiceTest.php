<?php

use XcVm\Module\Watch\WatchService;
use PHPUnit\Framework\TestCase;

/**
 * DB-touching WatchService methods, driven against TestDb (in-memory SQLite)
 * via the setDb() seam from the DatabaseAware trait — the same pattern
 * XC_VM's own BouquetServiceTest uses.
 */
final class WatchServiceTest extends TestCase {

    private TestDb $db;

    protected function setUp(): void {
        $this->db = new TestDb();
        $this->db->exec(
            'CREATE TABLE watch_categories (id INTEGER PRIMARY KEY AUTOINCREMENT, type INTEGER, genre_id INTEGER, genre TEXT, category_id INTEGER, bouquets TEXT);'
        );
        $this->db->exec(
            'CREATE TABLE watch_refresh (id INTEGER PRIMARY KEY AUTOINCREMENT, stream_id INTEGER);'
        );
        $this->db->exec(
            'CREATE TABLE watch_logs (id INTEGER PRIMARY KEY AUTOINCREMENT, type INTEGER, server_id INTEGER, filename TEXT, status INTEGER, stream_id INTEGER);'
        );
        $this->db->exec(
            'CREATE TABLE watch_folders (id INTEGER PRIMARY KEY AUTOINCREMENT, bouquets TEXT, fb_bouquets TEXT);'
        );
        WatchService::setDb($this->db);
    }

    private function categoryRows(): array {
        $this->db->query('SELECT * FROM `watch_categories` ORDER BY `id` ASC;');
        return $this->db->get_rows();
    }

    // --- insertMissingGenre (dedup of the 4x near-identical INSERT) --------

    public function testInsertMissingGenreInsertsWhenAbsent(): void {
        WatchService::insertMissingGenre(28, 'Action', 1, array(1 => array(), 2 => array()));

        $rRows = $this->categoryRows();
        $this->assertCount(1, $rRows);
        $this->assertSame('1', (string) $rRows[0]['type']);
        $this->assertSame('28', (string) $rRows[0]['genre_id']);
        $this->assertSame('Action', $rRows[0]['genre']);
        $this->assertSame('0', (string) $rRows[0]['category_id']);
        $this->assertSame('[]', $rRows[0]['bouquets']);
    }

    public function testInsertMissingGenreIsANoOpWhenAlreadyPresent(): void {
        WatchService::insertMissingGenre(28, 'Action', 1, array(1 => array(28), 2 => array()));

        $this->assertCount(0, $this->categoryRows());
    }

    public function testInsertMissingGenreTracksTypesIndependently(): void {
        // The same genre id can be missing for movies (type 1) but already
        // present for series (type 2) — each call only checks its own type.
        WatchService::insertMissingGenre(28, 'Action', 1, array(1 => array(), 2 => array(28)));
        WatchService::insertMissingGenre(28, 'Action', 2, array(1 => array(), 2 => array(28)));

        $rRows = $this->categoryRows();
        $this->assertCount(1, $rRows);
        $this->assertSame('1', (string) $rRows[0]['type']);
    }

    // --- applyGenreCategoryUpdates (dedup of the genre/genretv form loops) --

    public function testApplyGenreCategoryUpdatesWritesCategoryAndBouquets(): void {
        $this->db->query("INSERT INTO watch_categories (id, type, genre_id) VALUES (1, 1, 28);");

        WatchService::applyGenreCategoryUpdates(
            array('genre_28' => 5, 'bouquet_28' => array('3', '1', '2')),
            'genre',
            'bouquet',
            1
        );

        $this->db->query('SELECT `category_id`, `bouquets` FROM `watch_categories` WHERE `id` = 1;');
        $rRow = $this->db->get_row();
        $this->assertSame('5', (string) $rRow['category_id']);
        $this->assertSame('[3,1,2]', $rRow['bouquets']);
    }

    public function testApplyGenreCategoryUpdatesDefaultsBouquetsToEmptyArray(): void {
        $this->db->query("INSERT INTO watch_categories (id, type, genre_id) VALUES (1, 1, 28);");

        WatchService::applyGenreCategoryUpdates(array('genre_28' => 5), 'genre', 'bouquet', 1);

        $this->db->query('SELECT `bouquets` FROM `watch_categories` WHERE `id` = 1;');
        $this->assertSame('[]', $this->db->get_col());
    }

    public function testApplyGenreCategoryUpdatesIgnoresUnrelatedKeys(): void {
        $this->db->query("INSERT INTO watch_categories (id, type, genre_id) VALUES (1, 1, 28);");
        $this->db->query("INSERT INTO watch_categories (id, type, genre_id) VALUES (2, 2, 28);");

        // A 'genretv_28' key must not be picked up by the 'genre' prefix pass
        // (prefix comparison is exact-match on explode('_', $key)[0]).
        WatchService::applyGenreCategoryUpdates(array('genretv_28' => 9), 'genre', 'bouquet', 1);

        $this->db->query('SELECT `category_id` FROM `watch_categories` WHERE `id` = 1;');
        $this->assertNull($this->db->get_row()['category_id'] ?? null);
    }

    public function testEditWatchSettingsAppliesBothMovieAndTvGenrePasses(): void {
        $this->db->query("INSERT INTO watch_categories (id, type, genre_id) VALUES (1, 1, 28);");
        $this->db->query("INSERT INTO watch_categories (id, type, genre_id) VALUES (2, 2, 28);");
        $this->db->exec('CREATE TABLE settings (percentage_match INTEGER, scan_seconds INTEGER, thread_count INTEGER, max_genres INTEGER, max_items INTEGER, alternative_titles INTEGER, fallback_parser INTEGER);');
        $this->db->query('INSERT INTO settings (percentage_match) VALUES (0);');

        $rResult = WatchService::editWatchSettings(array(
            'genre_28' => 5,
            'bouquet_28' => array(1),
            'genretv_28' => 7,
            'bouquettv_28' => array(2),
            'percentage_match' => 80,
            'scan_seconds' => 60,
            'thread_count' => 2,
            'max_genres' => 3,
            'max_items' => 0,
        ));

        $this->assertSame(STATUS_SUCCESS, $rResult['status']);
        $this->db->query('SELECT `type`, `category_id` FROM `watch_categories` ORDER BY `id` ASC;');
        $rRows = $this->db->get_rows();
        $this->assertSame('5', (string) $rRows[0]['category_id']);
        $this->assertSame('7', (string) $rRows[1]['category_id']);
    }

    // --- a couple of the untouched-but-still-DB-critical methods -----------

    public function testMarkImportedLinksTheLogRowToTheCreatedStream(): void {
        $this->db->query("INSERT INTO watch_logs (type, server_id, filename, status, stream_id) VALUES (1, 1, 'movie.mkv', 4, 0);");

        WatchService::markImported(42, 'movie.mkv', 1);

        $this->db->query('SELECT `status`, `stream_id` FROM `watch_logs` WHERE `filename` = ?;', 'movie.mkv');
        $rRow = $this->db->get_row();
        $this->assertSame('1', (string) $rRow['status']);
        $this->assertSame('42', (string) $rRow['stream_id']);
    }

    public function testMarkImportedIsANoOpForAnEmptyPath(): void {
        $this->db->query("INSERT INTO watch_logs (type, server_id, filename, status, stream_id) VALUES (1, 1, 'movie.mkv', 4, 0);");

        WatchService::markImported(42, '', 1);

        $this->db->query('SELECT `status` FROM `watch_logs` WHERE `filename` = ?;', 'movie.mkv');
        $this->assertSame('4', (string) $this->db->get_col());
    }

    public function testHandleStreamsDeletedRemovesRefreshAndLogRows(): void {
        $this->db->query('INSERT INTO watch_refresh (id, stream_id) VALUES (1, 42);');
        $this->db->query("INSERT INTO watch_logs (type, server_id, filename, status, stream_id) VALUES (1, 1, 'x.mkv', 1, 42);");

        WatchService::handleStreamsDeleted(array(42));

        $this->db->query('SELECT COUNT(*) AS `count` FROM `watch_refresh`;');
        $this->assertSame(0, (int) $this->db->get_col());
        $this->db->query('SELECT COUNT(*) AS `count` FROM `watch_logs`;');
        $this->assertSame(0, (int) $this->db->get_col());
    }
}
