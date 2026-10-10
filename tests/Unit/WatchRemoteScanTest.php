<?php

use PHPUnit\Framework\TestCase;
use XcVm\Core\Cluster\NodeFlows;
use XcVm\Core\Cluster\NodeRole;
use XcVm\Core\Cluster\NodeRpc;
use XcVm\Domain\Cluster\NodeRegistry;
use XcVm\Module\Watch\WatchCron;
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
        $this->assertSame(array(0, 0), array($rItems[0]['ffprobe_input'], $rItems[0]['extract_metadata']), 'no ffprobe here, where the file is not');
        $this->assertSame(2, $rItems[0]['subtitles']['location']);
        $this->assertSame(array(), $rItems[1]['subtitles']);
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
