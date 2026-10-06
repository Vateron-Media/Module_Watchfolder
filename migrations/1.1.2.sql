-- watch module — version delta 1.1.2 (forward-only)
-- Adds watch_logs.title: an M3U import's entry name, so the log shows what a
-- URL-only row is. Fresh installs get it from database.sql. Idempotent.
ALTER TABLE `watch_logs`
	ADD COLUMN IF NOT EXISTS `title` varchar(1024) COLLATE utf8_unicode_ci DEFAULT NULL AFTER `filename`;
