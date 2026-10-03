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

    public function testEditWatchSettingsSavesOnlyTheScanSettings(): void {
        $this->db->exec('CREATE TABLE settings (percentage_match INTEGER, scan_seconds INTEGER, thread_count INTEGER, max_items INTEGER);');
        $this->db->query('INSERT INTO settings (percentage_match, scan_seconds, thread_count, max_items) VALUES (80, 0, 0, 0);');

        $rResult = WatchService::editWatchSettings(array('scan_seconds' => '60', 'thread_count' => '2', 'max_items' => '10', 'percentage_match' => '10'));

        $this->assertSame(STATUS_SUCCESS, $rResult['status']);
        $this->db->query('SELECT * FROM settings;');
        $this->assertSame(array(80, 60, 2, 10), array_map('intval', array_values($this->db->get_row())));
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

    public function testLogImportResultReplacesTheFilesEarlierRow(): void {
        WatchService::logImportResult(2, 1, '/media/a&b.mkv', 4);
        WatchService::logImportResult(2, 1, '/media/a&b.mkv', 1, 42);
        WatchService::logImportResult(2, 1, '/media/other.mkv', 3);

        $this->db->query('SELECT `filename`, `status`, `stream_id` FROM `watch_logs` ORDER BY `id` ASC;');
        $rRows = $this->db->get_rows();
        $this->assertCount(2, $rRows);
        $this->assertSame(array('/media/a&amp;b.mkv', 1, 42), array($rRows[0]['filename'], (int) $rRows[0]['status'], (int) $rRows[0]['stream_id']));
    }

    public function testMarkImportedFindsARowLoggedWithSpecialCharacters(): void {
        WatchService::logImportResult(1, 1, "/media/Tom & Jerry's.mkv", 4);

        WatchService::markImported(42, "/media/Tom & Jerry's.mkv", 1);

        $this->db->query('SELECT `status`, `stream_id` FROM `watch_logs`;');
        $rRow = $this->db->get_row();
        $this->assertSame(array(1, 42), array((int) $rRow['status'], (int) $rRow['stream_id']));
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
