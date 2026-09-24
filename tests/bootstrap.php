<?php

/**
 * Test bootstrap for the standalone Module_Watchfolder repository.
 *
 * The module ships namespaced under XcVm\Module\Watch but is only installed
 * (physically copied under XC_VM/src/Modules/<hash>/) at deploy time — in this
 * dev checkout it lives as a sibling of XC_VM. We reuse XC_VM's own test
 * bootstrap (composer autoload, MAIN_HOME, the TestDb SQLite double) from the
 * sibling checkout instead of duplicating it, then load this module's classes
 * directly since they are not present under XC_VM/src/Modules here.
 */

$xcVmTestsDir = dirname(__DIR__, 2) . '/XC_VM/tests';

if (!file_exists($xcVmTestsDir . '/bootstrap.php')) {
    throw new RuntimeException('Expected a sibling XC_VM checkout at ' . dirname($xcVmTestsDir) . ' with its test bootstrap — Module_Watchfolder tests reuse XC_VM\'s composer autoload and TestDb double rather than duplicating them.');
}

require_once $xcVmTestsDir . '/bootstrap.php';

if (!defined('SERVER_ID')) {
    define('SERVER_ID', 1);
}

\XcVm\Core\Config\ConstantsInitializer::initStatus();

$watchTmpRoot = sys_get_temp_dir() . '/watch_module_tests';
if (!defined('WATCH_TMP_PATH')) {
    define('WATCH_TMP_PATH', $watchTmpRoot . '/tmp/');
}
if (!defined('CONFIG_PATH')) {
    define('CONFIG_PATH', $watchTmpRoot . '/config/');
}
if (!defined('CACHE_TMP_PATH')) {
    define('CACHE_TMP_PATH', $watchTmpRoot . '/cache/');
}
foreach (array(WATCH_TMP_PATH, CONFIG_PATH, CACHE_TMP_PATH) as $rDir) {
    if (!is_dir($rDir)) {
        mkdir($rDir, 0775, true);
    }
}

// Defines the global TMDB client class plus its Movie/TVShow/Season/... entity
// wrappers (plain array wrappers, no network access from merely loading the file).
require_once MAIN_HOME . 'Infrastructure/Tmdb/lib/TmdbClient.php';

require_once __DIR__ . '/Support/FakeTmdbClient.php';

require_once dirname(__DIR__) . '/WatchItemHalt.php';
require_once dirname(__DIR__) . '/WatchItem.php';
require_once dirname(__DIR__) . '/WatchCron.php';
require_once dirname(__DIR__) . '/WatchService.php';
