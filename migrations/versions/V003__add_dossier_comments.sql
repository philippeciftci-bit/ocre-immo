-- M/2026/05/14/1 — V003 ajout table `dossier_comments`.
-- Idempotent: CREATE TABLE IF NOT EXISTS.

SET FOREIGN_KEY_CHECKS=0;

CREATE TABLE IF NOT EXISTS `dossier_comments` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `tenant_slug` varchar(64) NOT NULL,
  `dossier_id` int(10) unsigned NOT NULL,
  `user_id` int(10) unsigned NOT NULL,
  `parent_comment_id` bigint(20) unsigned DEFAULT NULL,
  `field_path` varchar(128) DEFAULT NULL,
  `content` text NOT NULL,
  `resolved_at` datetime DEFAULT NULL,
  `created_at` datetime DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_dossier` (`tenant_slug`,`dossier_id`),
  KEY `idx_parent` (`parent_comment_id`),
  KEY `idx_field` (`field_path`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET FOREIGN_KEY_CHECKS=1;
