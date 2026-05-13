-- M/2026/05/14/1 — V004 ajout table `dossier_edits`.
-- Idempotent: CREATE TABLE IF NOT EXISTS.

SET FOREIGN_KEY_CHECKS=0;

CREATE TABLE IF NOT EXISTS `dossier_edits` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `client_id` int(10) unsigned NOT NULL,
  `workspace_id` int(10) unsigned DEFAULT NULL,
  `author_user_id` int(10) unsigned NOT NULL,
  `status` enum('pending','approved','rejected','superseded') NOT NULL DEFAULT 'pending',
  `changes` longtext NOT NULL,
  `parent_edit_id` bigint(20) unsigned DEFAULT NULL,
  `decided_at` datetime DEFAULT NULL,
  `decided_by_user_id` int(10) unsigned DEFAULT NULL,
  `decision_type` enum('approve','modify','reject') DEFAULT NULL,
  `decision_comment` text DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `tenant_slug` varchar(50) NOT NULL DEFAULT '',
  PRIMARY KEY (`id`),
  KEY `idx_client_status` (`client_id`,`status`),
  KEY `idx_workspace_pending` (`workspace_id`,`status`),
  KEY `idx_author` (`author_user_id`,`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET FOREIGN_KEY_CHECKS=1;
