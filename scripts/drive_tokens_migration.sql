-- M/2026/04/29/33 — drive_tokens table per workspace.
CREATE TABLE IF NOT EXISTS drive_tokens (
    id INT PRIMARY KEY AUTO_INCREMENT,
    user_id INT NOT NULL,
    workspace_slug VARCHAR(64) NOT NULL,
    access_token TEXT NOT NULL,
    refresh_token TEXT NOT NULL,
    expires_at DATETIME NOT NULL,
    drive_email VARCHAR(255) NULL,
    drive_folder_id VARCHAR(255) NULL,
    last_sync_at DATETIME NULL,
    last_sync_status VARCHAR(32) NULL,
    last_sync_error TEXT NULL,
    last_sync_file_id VARCHAR(255) NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uk_user_workspace (user_id, workspace_slug),
    KEY idx_workspace (workspace_slug)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
