-- M/2026/05/14/5 — V012 ajout table `matches`.
-- Resoud le 500 systematique sur /api/matching.php?action=find_all_matches
-- (cf request_id ff217480 capture par M/80 correlation ID).
-- Idempotent: CREATE TABLE IF NOT EXISTS.

SET FOREIGN_KEY_CHECKS=0;

CREATE TABLE IF NOT EXISTS `matches` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `dossier_a_id` int(11) NOT NULL,
  `dossier_b_id` int(11) NOT NULL,
  `score_pct` tinyint(3) unsigned NOT NULL,
  `source` enum('interne','archive','externe') NOT NULL DEFAULT 'interne',
  `status` enum('non_vu','vu','pertinent','surveiller','ecarte') NOT NULL DEFAULT 'non_vu',
  `owner_user_ids` text NOT NULL,
  `criteres_matched` text DEFAULT NULL,
  `source_externe_url` text DEFAULT NULL,
  `source_externe_site` varchar(100) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `seen_at` datetime DEFAULT NULL,
  `classified_at` datetime DEFAULT NULL,
  `classified_by_user_id` int(11) DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_pair` (`dossier_a_id`,`dossier_b_id`),
  KEY `idx_status` (`status`),
  KEY `idx_created` (`created_at`),
  KEY `idx_owners` (`owner_user_ids`(255))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `match_preferences` (
  `user_id` int(11) NOT NULL,
  `seuil_min_pct` tinyint(3) unsigned NOT NULL DEFAULT 70,
  `tolerance_budget_pct` int(10) unsigned NOT NULL DEFAULT 10,
  `tolerance_surface_pct` int(10) unsigned NOT NULL DEFAULT 25,
  `tolerance_terrain_pct` int(10) unsigned NOT NULL DEFAULT 50,
  `tolerance_chambres` tinyint(3) unsigned NOT NULL DEFAULT 1,
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET FOREIGN_KEY_CHECKS=1;
