-- M/2026/05/14/1 — V005 ajout table `dossier_followers`.
-- Idempotent: CREATE TABLE IF NOT EXISTS.

SET FOREIGN_KEY_CHECKS=0;

CREATE TABLE IF NOT EXISTS `dossier_followers` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `tenant_slug` varchar(64) NOT NULL,
  `dossier_id` int(10) unsigned NOT NULL,
  `user_id` int(10) unsigned NOT NULL,
  `created_at` datetime DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_user_dossier` (`user_id`,`dossier_id`),
  KEY `idx_dossier` (`tenant_slug`,`dossier_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET FOREIGN_KEY_CHECKS=1;
