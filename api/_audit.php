<?php
// V50 — Protection phase 1 : audit_log + helpers soft delete / restore.
// Inclus partout où une route writes/deletes. Idempotent.

function auditEnsureSchema(): void {
    static $done = false;
    if ($done) return;
    try {
        db()->exec("CREATE TABLE IF NOT EXISTS audit_log (
            id BIGINT AUTO_INCREMENT PRIMARY KEY,
            user_id INT NOT NULL,
            table_name VARCHAR(64) NOT NULL,
            record_id BIGINT NOT NULL,
            action ENUM('INSERT','UPDATE','DELETE','RESTORE') NOT NULL,
            before_state JSON NULL,
            after_state JSON NULL,
            ip VARCHAR(45) NULL,
            user_agent TEXT NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_record (table_name, record_id),
            INDEX idx_user_date (user_id, created_at),
            INDEX idx_table_date (table_name, created_at)
        ) ENGINE=InnoDB CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
    } catch (Exception $e) {}
    foreach ([
        "ALTER TABLE clients ADD COLUMN deleted_at DATETIME NULL DEFAULT NULL",
        "ALTER TABLE clients ADD INDEX idx_deleted (deleted_at)",
    ] as $sql) {
        try { db()->exec($sql); } catch (Exception $e) {}
    }
    $done = true;
}

function audit_log(int $user_id, string $table, int $record_id, string $action, $before = null, $after = null): void {
    auditEnsureSchema();
    try {
        $stmt = db()->prepare(
            "INSERT INTO audit_log (user_id, table_name, record_id, action, before_state, after_state, ip, user_agent)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)"
        );
        $stmt->execute([
            $user_id, $table, $record_id, $action,
            $before !== null ? json_encode($before, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : null,
            $after !== null ? json_encode($after, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : null,
            $_SERVER['REMOTE_ADDR'] ?? null,
            substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 1000) ?: null,
        ]);
    } catch (Exception $e) { error_log('[audit_log] ' . $e->getMessage()); }
}

// Soft-delete helper : marque deleted_at + audit. Retourne true si succès, false sinon.
function soft_delete(string $table, int $id, int $user_id, int $scope_user_id = 0): bool {
    auditEnsureSchema();
    $pdo = db();
    $sql = "SELECT * FROM `$table` WHERE id = ?";
    $params = [$id];
    if ($scope_user_id > 0) { $sql .= ' AND user_id = ?'; $params[] = $scope_user_id; }
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $before = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$before) return false;
    if (!empty($before['deleted_at'])) return false;
    try {
        $pdo->prepare("UPDATE `$table` SET deleted_at = NOW() WHERE id = ?")->execute([$id]);
    } catch (Exception $e) { error_log('[soft_delete] ' . $e->getMessage()); return false; }
    audit_log($user_id, $table, $id, 'DELETE', $before, null);
    return true;
}

function soft_restore(string $table, int $id, int $user_id, int $scope_user_id = 0): bool {
    auditEnsureSchema();
    $pdo = db();
    $sql = "SELECT * FROM `$table` WHERE id = ? AND deleted_at IS NOT NULL";
    $params = [$id];
    if ($scope_user_id > 0) { $sql .= ' AND user_id = ?'; $params[] = $scope_user_id; }
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $before = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$before) return false;
    try {
        $pdo->prepare("UPDATE `$table` SET deleted_at = NULL WHERE id = ?")->execute([$id]);
    } catch (Exception $e) { error_log('[soft_restore] ' . $e->getMessage()); return false; }
    audit_log($user_id, $table, $id, 'RESTORE', $before, null);
    return true;
}
