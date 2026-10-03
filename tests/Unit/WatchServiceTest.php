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
        $this->db->query('INSERT INTO settings (percentage_match, scan_seconds, thread_count, max_items) VALUES (80, 0, 4, 0);');

        $rResult = WatchService::editWatchSettings(array('scan_seconds' => '60', 'thread_count' => '2', 'max_items' => '10', 'percentage_match' => '10'));

        $this->assertSame(STATUS_SUCCESS, $rResult['status']);
        $this->db->query('SELECT * FROM settings;');
        $this->assertSame(array(80, 60, 4, 10), array_map('intval', array_values($this->db->get_row())));
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

    // --- Admin API actions (registered from WatchModule::boot()) -------------

    public function testBootRegistersTheFolderActionsOfTheAdminApi(): void {
        \XcVm\Core\Module\AdminApiRegistry::reset();
        (new \XcVm\Module\Watch\WatchModule())->boot(\XcVm\Core\Container\ServiceContainer::getInstance());

        foreach (array('get_watch_folders', 'get_watch_folder', 'create_watch_folder', 'edit_watch_folder', 'delete_watch_folder', 'reload_watch_folder') as $rAction) {
            $this->assertNotNull(\XcVm\Core\Module\AdminApiRegistry::get($rAction), $rAction);
        }
        \XcVm\Core\Module\AdminApiRegistry::reset();
    }

    public function testApiGetAndDeleteFolder(): void {
        $this->db->query('INSERT INTO watch_folders (id, bouquets, fb_bouquets) VALUES (3, ?, ?);', '[]', '[]');

        $this->assertSame(3, (int) WatchService::apiGetFolder(3)['data']['id']);
        $this->assertSame(array('status' => STATUS_FAILURE), WatchService::apiGetFolder(4));
        $this->assertSame(array('status' => STATUS_FAILURE), WatchService::apiSaveFolder(array(), 4), 'editing a missing folder');
        $this->assertSame(array('status' => STATUS_SUCCESS), WatchService::apiDeleteFolder(3));
        $this->assertSame(array('status' => STATUS_FAILURE), WatchService::apiDeleteFolder(3));
    }
}
