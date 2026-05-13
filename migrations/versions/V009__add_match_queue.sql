-- M/2026/05/14/1 — V009 ajout table `match_queue`.
-- Idempotent: CREATE TABLE IF NOT EXISTS.

SET FOREIGN_KEY_CHECKS=0;

CREATE TABLE IF NOT EXISTS `match_queue` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `client_id` bigint(20) unsigned NOT NULL,
  `requested_at` datetime NOT NULL DEFAULT current_timestamp(),
  `processed_at` datetime DEFAULT NULL,
  `status` enum('pending','processing','done','failed') NOT NULL DEFAULT 'pending',
  `error_message` text DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_status_requested` (`status`,`requested_at`),
  KEY `idx_client` (`client_id`)
) ENGINE=InnoDB AUTO_INCREMENT=49 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET FOREIGN_KEY_CHECKS=1;
