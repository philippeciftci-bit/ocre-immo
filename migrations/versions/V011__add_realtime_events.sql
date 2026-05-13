-- M/2026/05/14/1 — V011 ajout table `realtime_events`.
-- Idempotent: CREATE TABLE IF NOT EXISTS.

SET FOREIGN_KEY_CHECKS=0;

CREATE TABLE IF NOT EXISTS `realtime_events` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `tenant_slug` varchar(64) NOT NULL,
  `topic` varchar(128) NOT NULL,
  `payload` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL CHECK (json_valid(`payload`)),
  `created_at` datetime(3) DEFAULT current_timestamp(3),
  PRIMARY KEY (`id`),
  KEY `idx_topic_created` (`tenant_slug`,`topic`,`created_at`),
  KEY `idx_created` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET FOREIGN_KEY_CHECKS=1;
