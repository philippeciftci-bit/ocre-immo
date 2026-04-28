-- M/2026/04/28/15 — Backend matchs : tables + index. Idempotente.
-- À appliquer sur chaque base WSp (per-tenant). La fonction PHP
-- ensureMatchesSchema() dans api/matches.php exécute ces mêmes CREATE TABLE
-- IF NOT EXISTS au premier hit. Ce fichier sert à la traçabilité.

CREATE TABLE IF NOT EXISTS _migrations (
  name VARCHAR(120) NOT NULL PRIMARY KEY,
  applied_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS match_preferences (
  user_id INT NOT NULL PRIMARY KEY,
  seuil_min_pct TINYINT UNSIGNED NOT NULL DEFAULT 70,
  tolerance_budget_pct INT UNSIGNED NOT NULL DEFAULT 10,
  tolerance_surface_pct INT UNSIGNED NOT NULL DEFAULT 25,
  tolerance_terrain_pct INT UNSIGNED NOT NULL DEFAULT 50,
  tolerance_chambres TINYINT UNSIGNED NOT NULL DEFAULT 1,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS matches (
  id INT AUTO_INCREMENT PRIMARY KEY,
  dossier_a_id INT NOT NULL,
  dossier_b_id INT NOT NULL,
  score_pct TINYINT UNSIGNED NOT NULL,
  source ENUM('interne','archive','externe') NOT NULL DEFAULT 'interne',
  status ENUM('non_vu','vu','pertinent','surveiller','ecarte') NOT NULL DEFAULT 'non_vu',
  owner_user_ids TEXT NOT NULL,
  criteres_matched TEXT NULL,
  source_externe_url TEXT NULL,
  source_externe_site VARCHAR(100) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  seen_at DATETIME NULL,
  classified_at DATETIME NULL,
  classified_by_user_id INT NULL,
  UNIQUE KEY uniq_pair (dossier_a_id, dossier_b_id),
  KEY idx_status (status),
  KEY idx_created (created_at),
  KEY idx_owners (owner_user_ids(255))
) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

INSERT IGNORE INTO _migrations (name) VALUES ('2026-04-28-matches-tables');
