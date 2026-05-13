<?php
// M/2026/05/13/25 — SSO M118 : creation table login_attempts (rate-limit).
// Idempotent. Run cli : php cron/migrations/sso_m118_login_attempts.php
require_once __DIR__ . '/../../api/db.php';

$pdo = new PDO('mysql:host=' . DB_HOST . ';dbname=ocre_meta;charset=utf8mb4', DB_USER, DB_PASS, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
]);

$sql = <<<SQL
CREATE TABLE IF NOT EXISTS login_attempts (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    ip_address VARCHAR(45) NOT NULL,
    email VARCHAR(255) NULL,
    success TINYINT(1) NOT NULL DEFAULT 0,
    reason VARCHAR(64) NULL,
    attempted_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_ip_time (ip_address, attempted_at),
    KEY idx_email_time (email, attempted_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
SQL;

$pdo->exec($sql);
echo "[OK] login_attempts ready\n";
