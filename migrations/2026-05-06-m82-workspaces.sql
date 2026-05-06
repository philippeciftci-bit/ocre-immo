-- M/2026/05/06/82.1 — Architecture WSC : tables workspaces + client_workspaces.
-- Adapte au schema reel JSON-in-clients (FK clients(id) au lieu de dossiers(id)).
-- Idempotent.

CREATE TABLE IF NOT EXISTS workspaces (
    id INT AUTO_INCREMENT PRIMARY KEY,
    nom VARCHAR(120) NOT NULL UNIQUE,
    couleur VARCHAR(7) DEFAULT '#6B4F8F',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS client_workspaces (
    client_id INT(10) UNSIGNED NOT NULL,
    workspace_id INT NOT NULL,
    added_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (client_id, workspace_id),
    FOREIGN KEY (client_id) REFERENCES clients(id) ON DELETE CASCADE,
    FOREIGN KEY (workspace_id) REFERENCES workspaces(id) ON DELETE CASCADE,
    INDEX idx_workspace (workspace_id),
    INDEX idx_client (client_id)
);
