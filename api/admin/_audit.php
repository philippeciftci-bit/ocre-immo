<?php
// M/2026/04/28/52 — audit_log helper (table en ocre_meta).
if (!function_exists('audit_log')) {

function audit_log_ensure_schema(): void {
    static $done = false;
    if ($done) return;
    try {
        $dsn = 'mysql:host=' . DB_HOST . ';dbname=ocre_meta;charset=utf8mb4';
        $pdo = new PDO($dsn, DB_USER, DB_PASS, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $pdo->exec("CREATE TABLE IF NOT EXISTS audit_log (
            id BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
            user_id INT UNSIGNED NOT NULL,
            action VARCHAR(64) NOT NULL,
            target_type VARCHAR(64) NULL,
            target_id BIGINT UNSIGNED NULL,
            payload LONGTEXT NULL,
            ip VARCHAR(64) NULL,
            user_agent VARCHAR(255) NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_user_created (user_id, created_at),
            INDEX idx_action (action, created_at),
            INDEX idx_target (target_type, target_id)
        ) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
    } catch (Throwable $e) {}
    $done = true;
}

function audit_log(int $uid, string $action, ?string $targetType = null, ?int $targetId = null, ?array $payload = null): void {
    audit_log_ensure_schema();
    try {
        $dsn = 'mysql:host=' . DB_HOST . ';dbname=ocre_meta;charset=utf8mb4';
        $pdo = new PDO($dsn, DB_USER, DB_PASS, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $st = $pdo->prepare(
            "INSERT INTO audit_log (user_id, action, target_type, target_id, payload, ip, user_agent)
             VALUES (?, ?, ?, ?, ?, ?, ?)"
        );
        $st->execute([
            $uid,
            $action,
            $targetType,
            $targetId,
            $payload ? json_encode($payload, JSON_UNESCAPED_UNICODE) : null,
            $_SERVER['REMOTE_ADDR'] ?? null,
            substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 250),
        ]);
    } catch (Throwable $e) {}
}

function audit_log_list(int $limit = 50, ?int $userId = null): array {
    audit_log_ensure_schema();
    try {
        $dsn = 'mysql:host=' . DB_HOST . ';dbname=ocre_meta;charset=utf8mb4';
        $pdo = new PDO($dsn, DB_USER, DB_PASS, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
        if ($userId) {
            $st = $pdo->prepare("SELECT * FROM audit_log WHERE user_id = ? ORDER BY created_at DESC LIMIT $limit");
            $st->execute([$userId]);
        } else {
            $st = $pdo->query("SELECT * FROM audit_log ORDER BY created_at DESC LIMIT $limit");
        }
        return $st->fetchAll();
    } catch (Throwable $e) { return []; }
}

}
