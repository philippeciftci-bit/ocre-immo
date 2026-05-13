-- M/2026/05/14/1 — V007 ajout table `dossier_versions`.
-- Idempotent: CREATE TABLE IF NOT EXISTS.

SET FOREIGN_KEY_CHECKS=0;

CREATE TABLE IF NOT EXISTS `dossier_versions` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `client_id` int(10) unsigned NOT NULL,
  `version_num` int(10) unsigned NOT NULL,
  `snapshot` longtext NOT NULL,
  `approved_by_edit_id` bigint(20) unsigned DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_client_version` (`client_id`,`version_num`),
  KEY `idx_client_latest` (`client_id`,`version_num`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET FOREIGN_KEY_CHECKS=1;
