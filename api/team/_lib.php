<?php
// M114 — Helper team multi-utilisateur par tenant.
require_once __DIR__ . '/../db.php';

const TEAM_ROLES = ['owner', 'manager', 'collaborator', 'viewer'];

function team_meta_pdo(): PDO {
    static $pdo = null;
    if ($pdo === null) {
        $pdo = new PDO('mysql:host=' . DB_HOST . ';dbname=ocre_meta;charset=utf8mb4', DB_USER, DB_PASS,
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
    }
    return $pdo;
}

function team_ensure_schema(): void {
    static $done = false;
    if ($done) return;
    team_meta_pdo()->exec("CREATE TABLE IF NOT EXISTS auth_team_members (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        user_id INT UNSIGNED NULL,
        email VARCHAR(255) NOT NULL,
        tenant_slug VARCHAR(64) NOT NULL,
        role ENUM('owner','manager','collaborator','viewer') NOT NULL DEFAULT 'collaborator',
        invited_by_user_id INT UNSIGNED NULL,
        invitation_token VARCHAR(64) NULL,
        invitation_expires_at DATETIME NULL,
        joined_at DATETIME NULL,
        removed_at DATETIME NULL,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uniq_tenant_email (tenant_slug, email),
        INDEX idx_token (invitation_token),
        INDEX idx_user_tenant (user_id, tenant_slug)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $done = true;
}

function team_get_role(string $tenant, int $userId, ?string $email = null): string {
    team_ensure_schema();
    $sql = "SELECT role FROM auth_team_members WHERE tenant_slug=? AND removed_at IS NULL AND (user_id=?";
    $args = [$tenant, $userId];
    if ($email) { $sql .= " OR email=?"; $args[] = $email; }
    $sql .= ") LIMIT 1";
    $st = team_meta_pdo()->prepare($sql);
    $st->execute($args);
    $r = $st->fetch();
    return $r ? $r['role'] : 'owner'; // fallback owner si pas d'entree (compat tenant historique)
}

function team_require_role(string $tenant, int $userId, ?string $email, array $allowed): void {
    $role = team_get_role($tenant, $userId, $email);
    if (!in_array($role, $allowed, true)) {
        http_response_code(403);
        header('Content-Type: application/json');
        echo json_encode(['ok' => false, 'error' => 'forbidden', 'role' => $role, 'required' => $allowed]);
        exit;
    }
}
