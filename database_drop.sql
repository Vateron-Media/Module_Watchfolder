-- watch module — teardown (single deletion file)
-- Drops every table the module owns (not watch_refresh: core's TMDb queue). Runs on uninstall. plex is uninstalled
-- first (it depends on watch), so its data is already gone by the time these run.
DROP TABLE IF EXISTS `watch_logs`;
DROP TABLE IF EXISTS `watch_folders`;
