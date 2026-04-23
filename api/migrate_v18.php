<?php
// Ocre v18.0 — migration : 4 nouvelles tables (suivi_todos, suivi_events, suivi_journal, push_subscriptions).
// IP-whitelist VPS atelier (46.225.215.148). Idempotent : CREATE TABLE IF NOT EXISTS.
// Appel : curl https://app.ocre.immo/api/migrate_v18.php
require_once __DIR__ . '/db.php';
header('Content-Type: application/json; charset=utf-8');

$allowed = ['46.225.215.148'];
$remote = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? ($_SERVER['REMOTE_ADDR'] ?? '');
$remote_ip = trim(explode(',', $remote)[0]);
if (!in_array($remote_ip, $allowed, true)) {
    http_response_code(403);
    exit(json_encode(['ok' => false, 'error' => 'Forbidden', 'ip' => $remote_ip]));
}

$pdo = db();
$results = [];

$tables = [
    'suivi_todos' => "CREATE TABLE IF NOT EXISTS suivi_todos (
        id INT AUTO_INCREMENT PRIMARY KEY,
        client_id INT NULL,
        user_id INT NOT NULL,
        title VARCHAR(255) NOT NULL,
        due_at DATETIME NULL,
        done TINYINT NOT NULL DEFAULT 0,
        priority ENUM('low','medium','high') NOT NULL DEFAULT 'medium',
        notified TINYINT NOT NULL DEFAULT 0,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        INDEX idx_user_due (user_id, due_at),
        INDEX idx_client (client_id),
        INDEX idx_pending (done, due_at)
    ) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci",

    'suivi_events' => "CREATE TABLE IF NOT EXISTS suivi_events (
        id INT AUTO_INCREMENT PRIMARY KEY,
        client_id INT NULL,
        user_id INT NOT NULL,
        type ENUM('rdv','appel','visite','email','autre') NOT NULL DEFAULT 'rdv',
        title VARCHAR(255) NOT NULL,
        when_at DATETIME NOT NULL,
        duration_min INT NOT NULL DEFAULT 60,
        location VARCHAR(255) NULL,
        notes TEXT NULL,
        status ENUM('planned','done','cancelled') NOT NULL DEFAULT 'planned',
        reminder_min_before INT NOT NULL DEFAULT 60,
        notified TINYINT NOT NULL DEFAULT 0,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        INDEX idx_user_when (user_id, when_at),
        INDEX idx_client (client_id),
        INDEX idx_pending (status, when_at, notified)
    ) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci",

    'suivi_journal' => "CREATE TABLE IF NOT EXISTS suivi_journal (
        id INT AUTO_INCREMENT PRIMARY KEY,
        client_id INT NOT NULL,
        user_id INT NOT NULL,
        ts DATETIME NOT NULL,
        kind ENUM('note','appel_entrant','appel_sortant','email_envoye','email_recu','visite','sms') NOT NULL DEFAULT 'note',
        content TEXT NOT NULL,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_client_ts (client_id, ts),
        INDEX idx_user (user_id)
    ) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci",

    'push_subscriptions' => "CREATE TABLE IF NOT EXISTS push_subscriptions (
        id INT AUTO_INCREMENT PRIMARY KEY,
        user_id INT NOT NULL,
        endpoint VARCHAR(500) NOT NULL,
        p256dh VARCHAR(255) NOT NULL,
        auth VARCHAR(120) NOT NULL,
        ua VARCHAR(255) NULL,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        last_used_at DATETIME NULL,
        UNIQUE KEY uniq_endpoint (endpoint),
        INDEX idx_user (user_id)
    ) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci",
];

foreach ($tables as $name => $sql) {
    try {
        $pdo->exec($sql);
        $exists = $pdo->query("SHOW TABLES LIKE " . $pdo->quote($name))->fetch();
        $count = $pdo->query("SELECT COUNT(*) AS c FROM `$name`")->fetch();
        $results[$name] = ['ok' => true, 'exists' => (bool)$exists, 'rows' => (int)($count['c'] ?? 0)];
    } catch (Throwable $e) {
        $results[$name] = ['ok' => false, 'error' => $e->getMessage()];
    }
}

echo json_encode([
    'ok' => true,
    'version' => 'v18.0',
    'tables' => $results,
    'ts' => date('c'),
], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
