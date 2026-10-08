-- watch module — master schema (current version)
-- Full CREATE + seed for a fresh install. Must always reflect the LATEST schema
-- (every migrations/<semver>.sql delta folded in), mirroring core's
-- bin/install/database.sql. Owns: watch_folders, watch_logs. watch_refresh, the
-- TMDb refresh queue, is core's (core migration 085): created here too, only for
-- a core from before it, and never dropped by this module.
-- plex depends on watch and reuses watch_folders. The genre mapping
-- (watch_categories) is core's since 1.1.0 (core migration 062).

CREATE TABLE IF NOT EXISTS `watch_folders` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `type` varchar(32) COLLATE utf8_unicode_ci DEFAULT NULL,
  `directory` varchar(2048) COLLATE utf8_unicode_ci DEFAULT NULL,
  `rclone_dir` varchar(2048) COLLATE utf8_unicode_ci DEFAULT NULL,
  `server_id` int(8) DEFAULT '0',
  `category_id` int(8) DEFAULT '0',
  `bouquets` varchar(4096) COLLATE utf8_unicode_ci DEFAULT '[]',
  `last_run` int(32) DEFAULT '0',
  `active` int(1) DEFAULT '1',
  `delete_missing` tinyint(1) DEFAULT '0',
  `disable_tmdb` int(1) DEFAULT '0',
  `ignore_no_match` int(1) DEFAULT '0',
  `auto_subtitles` int(1) DEFAULT '0',
  `fb_bouquets` varchar(4096) COLLATE utf8_unicode_ci DEFAULT '[]',
  `fb_category_id` int(8) DEFAULT '0',
  `allowed_extensions` varchar(4096) COLLATE utf8_unicode_ci DEFAULT '[]',
  `language` varchar(32) COLLATE utf8_unicode_ci DEFAULT NULL,
  `read_native` tinyint(4) DEFAULT '0',
  `movie_symlink` tinyint(4) DEFAULT '0',
  `auto_encode` tinyint(4) DEFAULT '1',
  `ffprobe_input` tinyint(4) DEFAULT '1',
  `transcode_profile_id` int(11) DEFAULT '0',
  `auto_upgrade` tinyint(4) DEFAULT '0',
  `fallback_title` tinyint(4) DEFAULT '0',
  `plex_ip` varchar(128) COLLATE utf8_unicode_ci DEFAULT NULL,
  `plex_port` int(5) DEFAULT '0',
  `plex_username` varchar(256) COLLATE utf8_unicode_ci DEFAULT NULL,
  `plex_password` varchar(256) COLLATE utf8_unicode_ci DEFAULT NULL,
  `plex_libraries` mediumtext COLLATE utf8_unicode_ci,
  `scan_missing` tinyint(4) DEFAULT '0',
  `extract_metadata` tinyint(4) DEFAULT '0',
  `store_categories` tinyint(1) DEFAULT '0',
  `duplicate_tmdb` tinyint(1) DEFAULT '0',
  `check_tmdb` tinyint(1) DEFAULT '1',
  `remove_subtitles` tinyint(1) DEFAULT '0',
  `target_container` varchar(64) COLLATE utf8_unicode_ci DEFAULT NULL,
  `server_add` varchar(512) COLLATE utf8_unicode_ci DEFAULT NULL,
  `direct_proxy` tinyint(1) DEFAULT '0',
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8 COLLATE=utf8_unicode_ci;

CREATE TABLE IF NOT EXISTS `watch_logs` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `type` int(1) DEFAULT '0',
  `server_id` int(8) DEFAULT '0',
  `filename` varchar(4096) COLLATE utf8_unicode_ci DEFAULT NULL,
  `title` varchar(1024) COLLATE utf8_unicode_ci DEFAULT NULL,
  `status` int(1) DEFAULT '0',
  `stream_id` int(8) DEFAULT '0',
  `dateadded` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8 COLLATE=utf8_unicode_ci;

CREATE TABLE IF NOT EXISTS `watch_refresh` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `type` int(1) DEFAULT '0',
  `stream_id` int(16) DEFAULT '0',
  `status` int(8) DEFAULT '0',
  `dateadded` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8 COLLATE=utf8_unicode_ci;

