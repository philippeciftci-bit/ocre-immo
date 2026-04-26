-- V20 phase 1 — DB centrale ocre_meta : registre users + workspaces + audit + ruptures.
SET NAMES utf8mb4;
SET CHARACTER SET utf8mb4;

CREATE TABLE IF NOT EXISTS users (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  email VARCHAR(255) NOT NULL,
  password_hash VARCHAR(255) NOT NULL,
  display_name VARCHAR(120) NULL,
  role ENUM('agent','super_admin') NOT NULL DEFAULT 'agent',
  country_code CHAR(2) NULL,
  pro_card_number VARCHAR(64) NULL,
  must_change_password TINYINT NOT NULL DEFAULT 0,
  last_login DATETIME NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  archived_at DATETIME NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uniq_email (email)
) ENGINE=InnoDB CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS workspaces (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  slug VARCHAR(64) NOT NULL,
  type ENUM('wsp','wsc') NOT NULL,
  display_name VARCHAR(120) NOT NULL,
  country_code CHAR(2) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  archived_at DATETIME NULL,
  archived_reason VARCHAR(255) NULL,
  archived_pending TINYINT NOT NULL DEFAULT 0,
  PRIMARY KEY (id),
  UNIQUE KEY uniq_slug (slug),
  KEY idx_type (type)
) ENGINE=InnoDB CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS workspace_members (
  workspace_id INT UNSIGNED NOT NULL,
  user_id INT UNSIGNED NOT NULL,
  role ENUM('owner','member') NOT NULL DEFAULT 'member',
  joined_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  left_at DATETIME NULL,
  PRIMARY KEY (workspace_id, user_id),
  KEY idx_user (user_id),
  KEY idx_left (left_at)
) ENGINE=InnoDB CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS pact_signatures (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  wsc_id INT UNSIGNED NOT NULL,
  user_id INT UNSIGNED NOT NULL,
  doc_version VARCHAR(16) NOT NULL DEFAULT 'v1',
  sha256 CHAR(64) NULL,
  signed_at DATETIME NULL,
  ip_address VARCHAR(45) NULL,
  pdf_path VARCHAR(255) NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uniq_wsc_user (wsc_id, user_id),
  KEY idx_signed (signed_at)
) ENGINE=InnoDB CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS notifications (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id INT UNSIGNED NOT NULL,
  type VARCHAR(64) NOT NULL,
  title VARCHAR(255) NOT NULL,
  body TEXT NULL,
  payload_json JSON NULL,
  read_at DATETIME NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_user_unread (user_id, read_at),
  KEY idx_created (created_at)
) ENGINE=InnoDB CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS audit_log (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  actor_user_id INT UNSIGNED NULL,
  workspace_id INT UNSIGNED NULL,
  action VARCHAR(64) NOT NULL,
  target_type VARCHAR(64) NULL,
  target_id BIGINT UNSIGNED NULL,
  payload_json JSON NULL,
  ip_address VARCHAR(45) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_actor_date (actor_user_id, created_at),
  KEY idx_workspace_date (workspace_id, created_at),
  KEY idx_action (action)
) ENGINE=InnoDB CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS rupture_requests (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  wsc_id INT UNSIGNED NOT NULL,
  requester_user_id INT UNSIGNED NOT NULL,
  requested_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  scheduled_for DATETIME NOT NULL,
  cancelled_at DATETIME NULL,
  executed_at DATETIME NULL,
  snapshot_path VARCHAR(255) NULL,
  reminder_24h_sent_at DATETIME NULL,
  PRIMARY KEY (id),
  KEY idx_wsc (wsc_id),
  KEY idx_pending (executed_at, cancelled_at, scheduled_for)
) ENGINE=InnoDB CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS super_admin_events (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  super_admin_user_id INT UNSIGNED NOT NULL,
  action VARCHAR(64) NOT NULL,
  target_workspace_id INT UNSIGNED NULL,
  payload_json JSON NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_actor_date (super_admin_user_id, created_at)
) ENGINE=InnoDB CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS sessions (
  token CHAR(64) NOT NULL,
  user_id INT UNSIGNED NOT NULL,
  expires_at DATETIME NOT NULL,
  ip VARCHAR(45) NULL,
  user_agent VARCHAR(255) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (token),
  KEY idx_user (user_id),
  KEY idx_expires (expires_at)
) ENGINE=InnoDB CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS _migrations_log (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  script_name VARCHAR(255) NOT NULL,
  ran_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  summary VARCHAR(512) NULL,
  PRIMARY KEY (id)
) ENGINE=InnoDB CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
