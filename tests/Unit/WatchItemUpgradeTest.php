<?php

use XcVm\Module\Watch\WatchItem;
use XcVm\Module\Watch\WatchItemHalt;
use PHPUnit\Framework\TestCase;

/**
 * WatchItem::applyUpgrade() — always ends by throwing WatchItemHalt (the
 * exit()-replacement introduced for testability), so every scenario is
 * asserted with expectException() around the outcome it produces first.
 */
final class WatchItemUpgradeTest extends TestCase {

    private TestDb $db;
    private array $tmpFiles = array();

    protected function setUp(): void {
        $this->db = new TestDb();
        $this->db->exec('CREATE TABLE streams (id INTEGER PRIMARY KEY AUTOINCREMENT, stream_source TEXT, target_container TEXT);');
        $this->db->exec('CREATE TABLE streams_servers (stream_id INTEGER, server_id INTEGER, bitrate INTEGER, current_source TEXT, to_analyze INTEGER, pid INTEGER, stream_started INTEGER, stream_info TEXT, compatible INTEGER, video_codec TEXT, audio_codec TEXT, resolution TEXT, stream_status INTEGER);');
        $this->db->exec('CREATE TABLE watch_logs (id INTEGER PRIMARY KEY AUTOINCREMENT, type INTEGER, server_id INTEGER, filename TEXT, status INTEGER, stream_id INTEGER);');
        WatchItem::setDb($this->db);
    }

    protected function tearDown(): void {
        foreach ($this->tmpFiles as $rPath) {
            @unlink($rPath);
        }
    }

    private function tmpFile(int $rSizeBytes): string {
        $rPath = WATCH_TMP_PATH . 'upgrade_test_' . uniqid() . '.mkv';
        file_put_contents($rPath, str_repeat('a', $rSizeBytes));
        $this->tmpFiles[] = $rPath;
        return $rPath;
    }

    private function threadData(array $rOverrides = array()): array {
        return array_merge(array(
            'auto_upgrade' => true,
            'auto_encode' => false,
        ), $rOverrides);
    }

    public function testDoesNothingWhenAutoUpgradeIsDisabled(): void {
        $rWriteCacheCalled = false;
        try {
            WatchItem::applyUpgrade(
                array('id' => 10, 'source' => 's:1:/old.mkv'),
                $this->threadData(array('auto_upgrade' => false)),
                '/new.mkv', array('stream_source' => 'x', 'target_container' => 'mkv'), 1, 'movie',
                function () use (&$rWriteCacheCalled) { $rWriteCacheCalled = true; }
            );
            $this->fail('Expected WatchItemHalt');
        } catch (WatchItemHalt) {
        }

        $this->assertFalse($rWriteCacheCalled);
        $this->db->query('SELECT COUNT(*) AS `count` FROM `streams`;');
        $this->assertSame(0, (int) $this->db->get_col());
    }

    public function testDoesNothingWhenTheOldSourceIsFromADifferentServer(): void {
        // source prefix is "s:2:..." but SERVER_ID (in bootstrap.php) is 1.
        $this->expectException(WatchItemHalt::class);

        WatchItem::applyUpgrade(
            array('id' => 10, 'source' => 's:2:/old.mkv'),
            $this->threadData(), '/new.mkv', array('stream_source' => 'x', 'target_container' => 'mkv'), 1, 'movie',
            function () { $this->fail('cache writer must not run'); }
        );
    }

    public function testDoesNothingWhenTheExistingFileIsAlreadyAsLargeOrLarger(): void {
        $rOldFile = $this->tmpFile(2000);
        $rNewFile = $this->tmpFile(1000);

        try {
            WatchItem::applyUpgrade(
                array('id' => 10, 'source' => 's:1:' . $rOldFile),
                $this->threadData(), $rNewFile, array('stream_source' => 'x', 'target_container' => 'mkv'), 1, 'movie',
                function () { $this->fail('cache writer must not run'); }
            );
            $this->fail('Expected WatchItemHalt');
        } catch (WatchItemHalt) {
        }

        $this->db->query('SELECT COUNT(*) AS `count` FROM `streams`;');
        $this->assertSame(0, (int) $this->db->get_col());
    }

    public function testUpgradesWhenTheNewFileIsLargerAndWritesTheCache(): void {
        $this->db->query("INSERT INTO streams (id, stream_source, target_container) VALUES (10, 'old', 'avi');");
        $this->db->query('INSERT INTO streams_servers (stream_id, server_id) VALUES (10, 1);');
        $rOldFile = $this->tmpFile(1000);
        $rNewFile = $this->tmpFile(5000);
        $rCacheWriteReceived = null;

        try {
            WatchItem::applyUpgrade(
                array('id' => 10, 'source' => 's:1:' . $rOldFile),
                $this->threadData(), $rNewFile, array('stream_source' => 'new-source', 'target_container' => 'mkv'), 1, 'movie',
                function ($rUpgradeData) use (&$rCacheWriteReceived) { $rCacheWriteReceived = $rUpgradeData; }
            );
            $this->fail('Expected WatchItemHalt');
        } catch (WatchItemHalt) {
        }

        $this->assertSame(array('id' => 10, 'source' => 's:1:' . $rOldFile), $rCacheWriteReceived);
        $this->db->query('SELECT `stream_source`, `target_container` FROM `streams` WHERE `id` = 10;');
        $rRow = $this->db->get_row();
        $this->assertSame('new-source', $rRow['stream_source']);
        $this->assertSame('mkv', $rRow['target_container']);
        $this->db->query('SELECT `bitrate`, `stream_status` FROM `streams_servers` WHERE `stream_id` = 10;');
        $rServerRow = $this->db->get_row();
        $this->assertNull($rServerRow['bitrate']);
        $this->assertSame('0', (string) $rServerRow['stream_status']);
    }

    public function testUpgradesWhenNoOldFileExistsAtAll(): void {
        // !file_exists($rActualPath) unconditionally counts as "better source".
        $this->db->query("INSERT INTO streams (id, stream_source, target_container) VALUES (10, 'old', 'avi');");
        $this->db->query('INSERT INTO streams_servers (stream_id, server_id) VALUES (10, 1);');
        $rNewFile = $this->tmpFile(10);

        $this->expectException(WatchItemHalt::class);
        WatchItem::applyUpgrade(
            array('id' => 10, 'source' => 's:1:' . WATCH_TMP_PATH . 'does_not_exist.mkv'),
            $this->threadData(), $rNewFile, array('stream_source' => 'new-source', 'target_container' => 'mkv'), 1, 'movie',
            function () {}
        );
    }
}
