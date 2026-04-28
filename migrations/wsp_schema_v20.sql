-- V20 phase 1 — Schema standard ajoute aux DBs workspace (par dessus existant).
-- Tables clients/biens/etapes/documents/notes existent deja (heritage experghocreimmo
-- pour ocre_wsp_ozkan, vide pour autres WSp).
SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS settings_branding (
  k VARCHAR(64) NOT NULL,
  v TEXT NULL,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (k)
) ENGINE=InnoDB CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO settings_branding (k, v) VALUES
  ('primary_color', '#8B5E3C'),
  ('logo_path', ''),
  ('display_name', '');

CREATE TABLE IF NOT EXISTS custom_fields_enabled (
  field_key VARCHAR(64) NOT NULL,
  enabled TINYINT NOT NULL DEFAULT 0,
  label_override VARCHAR(120) NULL,
  PRIMARY KEY (field_key)
) ENGINE=InnoDB CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS published_to_vitrine (
  bien_id INT UNSIGNED NOT NULL,
  published_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  public_slug VARCHAR(120) NOT NULL,
  PRIMARY KEY (bien_id),
  UNIQUE KEY uniq_public_slug (public_slug)
) ENGINE=InnoDB CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS dossier_origin (
  dossier_id INT UNSIGNED NOT NULL,
  original_workspace_slug VARCHAR(64) NOT NULL,
  shared_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  archived_at DATETIME NULL,
  archived_reason VARCHAR(255) NULL,
  PRIMARY KEY (dossier_id),
  KEY idx_origin (original_workspace_slug),
  KEY idx_archived (archived_at)
) ENGINE=InnoDB CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS audit_log_local (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  action VARCHAR(64) NOT NULL,
  user_id INT UNSIGNED NULL,
  target_type VARCHAR(64) NULL,
  target_id BIGINT UNSIGNED NULL,
  payload_json JSON NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_action_date (action, created_at)
) ENGINE=InnoDB CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS presence (
  workspace_user_id INT UNSIGNED NOT NULL,
  dossier_id INT UNSIGNED NOT NULL,
  user_id INT UNSIGNED NOT NULL,
  user_display VARCHAR(120) NULL,
  last_seen DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (dossier_id, user_id),
  KEY idx_last_seen (last_seen)
) ENGINE=InnoDB CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
