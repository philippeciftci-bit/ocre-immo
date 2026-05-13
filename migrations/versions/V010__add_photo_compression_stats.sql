-- M/2026/05/14/1 — V010 ajout table `photo_compression_stats`.
-- Idempotent: CREATE TABLE IF NOT EXISTS.

SET FOREIGN_KEY_CHECKS=0;

CREATE TABLE IF NOT EXISTS `photo_compression_stats` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `client_id` int(10) unsigned DEFAULT NULL,
  `original_size` int(10) unsigned NOT NULL,
  `compressed_size` int(10) unsigned DEFAULT NULL,
  `thumb_size` int(10) unsigned DEFAULT NULL,
  `ratio_pct` decimal(5,2) DEFAULT NULL,
  `duration_ms` int(10) unsigned DEFAULT NULL,
  `engine` varchar(20) DEFAULT NULL,
  `success` tinyint(1) NOT NULL DEFAULT 0,
  `error_message` varchar(255) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_created` (`created_at`),
  KEY `idx_success` (`success`,`created_at`)
) ENGINE=InnoDB AUTO_INCREMENT=31 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

SET FOREIGN_KEY_CHECKS=1;
