<?php

use PHPUnit\Framework\TestCase;
use XcVm\Module\Watch\WatchController;
use XcVm\Module\Watch\WatchService;

/**
 * The watch log's table rows. A filename is stored HTML-escaped
 * (WatchService::logImportResult) and the view escapes every cell itself, so
 * the table hands it the name as it is: escaped twice, `&` showed as `&amp;`
 * and Manual Match asked for a path that does not exist.
 */
final class WatchOutputTableTest extends TestCase {
    private array $rGlobals;

    protected function setUp(): void {
        $this->rGlobals = array($GLOBALS['db'] ?? null, $GLOBALS['rUserInfo'] ?? null, $GLOBALS['rPermissions'] ?? null);
        $rDb = new TestDb();
        $rDb->exec('CREATE TABLE watch_logs (id INT PRIMARY KEY AUTO_INCREMENT, type INTEGER, server_id INTEGER, filename TEXT, title TEXT, status INTEGER, stream_id INTEGER, dateadded TIMESTAMP DEFAULT CURRENT_TIMESTAMP);');
        $rDb->exec('CREATE TABLE servers (id INT PRIMARY KEY, server_name TEXT);');
        $rDb->exec("INSERT INTO servers (id, server_name) VALUES (2, 'LB001');");
        WatchService::setDb($rDb);
        $GLOBALS['db'] = $rDb;
        $GLOBALS['rUserInfo'] = array('id' => 1, 'member_group_id' => 1);
        $GLOBALS['rPermissions'] = array('is_admin' => 1, 'advanced' => array(), 'all_reports' => array());
    }

    protected function tearDown(): void {
        list($GLOBALS['db'], $GLOBALS['rUserInfo'], $GLOBALS['rPermissions']) = $this->rGlobals;
    }

    public function testARowCarriesTheFilenameAsItIs(): void {
        WatchService::logImportResult(1, 1, 's:2:/mnt/films/Tom & Jerry\'s <cut>.mkv', 4);

        $rOut = WatchController::tableWatchOutput(array('recordsTotal' => 0, 'recordsFiltered' => 0, 'data' => array()), 0, 25, false);

        $this->assertCount(1, $rOut['data']);
        $this->assertSame('/mnt/films/Tom & Jerry\'s <cut>.mkv', $rOut['data'][0]['filename']);
        $this->assertSame(array(2, 'LB001'), array($rOut['data'][0]['server_id'], $rOut['data'][0]['server_name']));
    }
}
