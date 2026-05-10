<?php
// M_SUPERADMIN_RESET — Helper audit_logs (table dediee actions sensibles maintenance/reset/restore)
// Distincte de admin_actions (V18.40 admin classique) et super_admin_events (legacy).

require_once __DIR__ . '/../db.php';

function audit_logs_ensure_schema(): void {
    static $done = false;
    if ($done) return;
    try {
        db()->exec("CREATE TABLE IF NOT EXISTS audit_logs (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            user_id INT UNSIGNED NOT NULL,
            action VARCHAR(64) NOT NULL,
            payload JSON,
            ip_address VARCHAR(45),
            user_agent VARCHAR(256),
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_user (user_id),
            INDEX idx_action (action),
            INDEX idx_created (created_at)
        ) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
    } catch (Throwable $e) { /* swallow */ }
    $done = true;
}

function audit_log_insert(int $userId, string $action, array $payload = [], string $ip = '', string $ua = ''): void {
    audit_logs_ensure_schema();
    try {
        $st = db()->prepare("INSERT INTO audit_logs (user_id, action, payload, ip_address, user_agent) VALUES (?, ?, ?, ?, ?)");
        $st->execute([$userId, $action, json_encode($payload, JSON_UNESCAPED_UNICODE), $ip ?: ($_SERVER['REMOTE_ADDR'] ?? ''), $ua ?: ($_SERVER['HTTP_USER_AGENT'] ?? '')]);
    } catch (Throwable $e) { /* swallow */ }
}

function audit_log_list(int $limit = 50, ?string $actionPrefix = null): array {
    audit_logs_ensure_schema();
    try {
        if ($actionPrefix) {
            $st = db()->prepare("SELECT id, user_id, action, payload, ip_address, created_at FROM audit_logs WHERE action LIKE ? ORDER BY id DESC LIMIT ?");
            $st->bindValue(1, $actionPrefix . '%');
            $st->bindValue(2, max(1, min(500, $limit)), PDO::PARAM_INT);
        } else {
            $st = db()->prepare("SELECT id, user_id, action, payload, ip_address, created_at FROM audit_logs ORDER BY id DESC LIMIT ?");
            $st->bindValue(1, max(1, min(500, $limit)), PDO::PARAM_INT);
        }
        $st->execute();
        return $st->fetchAll();
    } catch (Throwable $e) { return []; }
}
