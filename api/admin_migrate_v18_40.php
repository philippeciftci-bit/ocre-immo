<?php
// V18.40 — migration one-shot IP-whitelist pour le dashboard admin.
// ALTER users +3 cols, SET is_admin=1 philippe, CREATE admin_actions + impersonation_sessions.
require_once __DIR__ . '/db.php';
header('Content-Type: application/json; charset=utf-8');
$ip = $_SERVER['REMOTE_ADDR'] ?? '';
if (!in_array($ip, ['46.225.215.148'], true)) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'IP refusée (' . $ip . ')']);
    exit;
}

$pdo = db();
$results = [];

foreach ([
    "ALTER TABLE users ADD COLUMN is_admin TINYINT NOT NULL DEFAULT 0",
    "ALTER TABLE users ADD COLUMN is_suspended TINYINT NOT NULL DEFAULT 0",
    "ALTER TABLE users ADD COLUMN must_change_password TINYINT NOT NULL DEFAULT 0",
] as $sql) {
    try { $pdo->exec($sql); $results[] = ['sql' => $sql, 'ok' => true]; }
    catch (Exception $e) { $results[] = ['sql' => $sql, 'ok' => false, 'err' => $e->getMessage()]; }
}

// Tables admin_actions + impersonation_sessions (idempotent).
$pdo->exec("CREATE TABLE IF NOT EXISTS admin_actions (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    admin_user_id INT NOT NULL,
    action VARCHAR(64) NOT NULL,
    target_user_id INT NULL,
    meta JSON NULL,
    ip VARCHAR(45) NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_admin (admin_user_id),
    INDEX idx_target (target_user_id),
    INDEX idx_created (created_at)
) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");

$pdo->exec("CREATE TABLE IF NOT EXISTS impersonation_sessions (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    admin_user_id INT NOT NULL,
    target_user_id INT NOT NULL,
    token VARCHAR(128) NOT NULL UNIQUE,
    expires_at DATETIME NOT NULL,
    stopped_at DATETIME NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_admin (admin_user_id),
    INDEX idx_target (target_user_id),
    INDEX idx_active (stopped_at, expires_at)
) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");

// Philippe admin
$up = $pdo->prepare("UPDATE users SET is_admin = 1 WHERE email = ?");
$up->execute(['philippe.ciftci@gmail.com']);
$philippeSet = $up->rowCount();

echo json_encode(['ok' => true, 'alter' => $results, 'philippe_admin_rows' => $philippeSet], JSON_PRETTY_PRINT);
