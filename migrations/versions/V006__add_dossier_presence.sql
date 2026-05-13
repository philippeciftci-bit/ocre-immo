-- M/2026/05/14/1 — V006 ajout table `dossier_presence`.
-- Idempotent: CREATE TABLE IF NOT EXISTS.

SET FOREIGN_KEY_CHECKS=0;

CREATE TABLE IF NOT EXISTS `dossier_presence` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `tenant_slug` varchar(64) NOT NULL,
  `dossier_id` int(10) unsigned NOT NULL,
  `user_id` int(10) unsigned NOT NULL,
  `last_ping_at` datetime DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_user_dossier` (`user_id`,`dossier_id`),
  KEY `idx_dossier_ping` (`tenant_slug`,`dossier_id`,`last_ping_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET FOREIGN_KEY_CHECKS=1;
