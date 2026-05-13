-- M/2026/05/14/1 — V002 ajout table `documents`.
-- Idempotent: CREATE TABLE IF NOT EXISTS.

SET FOREIGN_KEY_CHECKS=0;

CREATE TABLE IF NOT EXISTS `documents` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `client_id` int(10) unsigned NOT NULL,
  `owner_user_id` int(10) unsigned NOT NULL,
  `category` varchar(50) NOT NULL DEFAULT 'autre',
  `file_name` varchar(255) NOT NULL,
  `file_path` varchar(500) NOT NULL,
  `mime_type` varchar(100) DEFAULT NULL,
  `size_bytes` bigint(20) unsigned DEFAULT NULL,
  `uploaded_by` int(10) unsigned DEFAULT NULL,
  `uploaded_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_client_uploaded` (`client_id`,`uploaded_at`),
  KEY `idx_owner` (`owner_user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET FOREIGN_KEY_CHECKS=1;
