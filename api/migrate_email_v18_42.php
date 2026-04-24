<?php
// V18.42 — one-shot IP-whitelist. Crée email_logs + users.email_notifications.
require_once __DIR__ . '/db.php';
$ip = $_SERVER['REMOTE_ADDR'] ?? '';
if ($ip !== '46.225.215.148') { http_response_code(403); exit('Forbidden'); }

$out = [];

try {
    db()->exec("CREATE TABLE IF NOT EXISTS email_logs (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        to_address VARCHAR(255) NOT NULL,
        template VARCHAR(64) NOT NULL,
        subject VARCHAR(255) NOT NULL,
        status ENUM('sent','failed','pending') NOT NULL DEFAULT 'pending',
        provider_id VARCHAR(128) DEFAULT NULL,
        error TEXT DEFAULT NULL,
        user_id INT UNSIGNED DEFAULT NULL,
        meta TEXT DEFAULT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_created (created_at),
        INDEX idx_to (to_address),
        INDEX idx_status (status)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $out['email_logs'] = 'ok';
} catch (Exception $e) { $out['email_logs'] = 'err: ' . $e->getMessage(); }

try {
    db()->exec("ALTER TABLE users ADD COLUMN email_notifications TINYINT(1) NOT NULL DEFAULT 1");
    $out['users.email_notifications'] = 'added';
} catch (Exception $e) { $out['users.email_notifications'] = 'exists or err: ' . $e->getMessage(); }

header('Content-Type: application/json; charset=utf-8');
echo json_encode(['ok' => true, 'result' => $out], JSON_UNESCAPED_UNICODE);
