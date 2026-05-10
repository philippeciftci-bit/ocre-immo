<?php
// M_OCRE_V19_COLLAB — Lib collab : ensure schema 4 tables + helpers tenant_slug + emit event Server-Sent + notif PWA hook
// Schema lazy creation au premier appel collab_ensure_schema().
// Tables : dossier_comments / dossier_versions / dossier_presence / dossier_followers.

require_once __DIR__ . '/../db.php';

function collab_ensure_schema(): void {
    static $done = false;
    if ($done) return;
    try {
        db()->exec("CREATE TABLE IF NOT EXISTS dossier_comments (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            tenant_slug VARCHAR(64) NOT NULL,
            dossier_id INT UNSIGNED NOT NULL,
            user_id INT UNSIGNED NOT NULL,
            parent_comment_id BIGINT UNSIGNED NULL,
            field_path VARCHAR(128) NULL,
            content TEXT NOT NULL,
            resolved_at DATETIME NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_dossier (tenant_slug, dossier_id),
            INDEX idx_parent (parent_comment_id),
            INDEX idx_field (field_path)
        ) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
        db()->exec("CREATE TABLE IF NOT EXISTS dossier_versions (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            tenant_slug VARCHAR(64) NOT NULL,
            dossier_id INT UNSIGNED NOT NULL,
            user_id INT UNSIGNED NOT NULL,
            field_path VARCHAR(128) NOT NULL,
            old_value TEXT,
            new_value TEXT,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_dossier_created (tenant_slug, dossier_id, created_at),
            INDEX idx_user (user_id)
        ) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
        db()->exec("CREATE TABLE IF NOT EXISTS dossier_presence (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            tenant_slug VARCHAR(64) NOT NULL,
            dossier_id INT UNSIGNED NOT NULL,
            user_id INT UNSIGNED NOT NULL,
            last_ping_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uniq_user_dossier (user_id, dossier_id),
            INDEX idx_dossier_ping (tenant_slug, dossier_id, last_ping_at)
        ) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
        db()->exec("CREATE TABLE IF NOT EXISTS dossier_followers (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            tenant_slug VARCHAR(64) NOT NULL,
            dossier_id INT UNSIGNED NOT NULL,
            user_id INT UNSIGNED NOT NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uniq_user_dossier (user_id, dossier_id),
            INDEX idx_dossier (tenant_slug, dossier_id)
        ) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
        // Bus events SSE : table polling 2s côté client (alternative Redis non installé)
        db()->exec("CREATE TABLE IF NOT EXISTS realtime_events (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            tenant_slug VARCHAR(64) NOT NULL,
            topic VARCHAR(128) NOT NULL,
            payload JSON NOT NULL,
            created_at DATETIME(3) DEFAULT CURRENT_TIMESTAMP(3),
            INDEX idx_topic_created (tenant_slug, topic, created_at),
            INDEX idx_created (created_at)
        ) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
    } catch (Throwable $e) { /* swallow schema errors */ }
    $done = true;
}

function collab_tenant_slug(array $user): string {
    return (string)($user['slug'] ?? ($_SERVER['HTTP_X_TENANT_SLUG'] ?? (preg_match('/^([a-z0-9-]+)\.ocre\.immo$/', $_SERVER['HTTP_HOST'] ?? '', $m) ? $m[1] : '')));
}

function collab_dossier_belongs(int $dossierId, int $uid): bool {
    $st = db()->prepare("SELECT id FROM clients WHERE id=? AND user_id=? AND deleted_at IS NULL LIMIT 1");
    $st->execute([$dossierId, $uid]);
    return (bool) $st->fetchColumn();
}

function collab_emit(string $tenantSlug, string $topic, array $payload): void {
    collab_ensure_schema();
    try {
        $st = db()->prepare("INSERT INTO realtime_events (tenant_slug, topic, payload) VALUES (?, ?, ?)");
        $st->execute([$tenantSlug, $topic, json_encode($payload, JSON_UNESCAPED_UNICODE)]);
    } catch (Throwable $e) { /* swallow */ }
}

function collab_log_version(string $tenantSlug, int $dossierId, int $uid, string $field, $oldVal, $newVal): void {
    collab_ensure_schema();
    try {
        $st = db()->prepare("INSERT INTO dossier_versions (tenant_slug, dossier_id, user_id, field_path, old_value, new_value) VALUES (?, ?, ?, ?, ?, ?)");
        $st->execute([$tenantSlug, $dossierId, $uid, $field, is_scalar($oldVal) ? (string)$oldVal : json_encode($oldVal), is_scalar($newVal) ? (string)$newVal : json_encode($newVal)]);
        collab_emit($tenantSlug, "versions:dossier:$dossierId", ['type'=>'version', 'dossier_id'=>$dossierId, 'user_id'=>$uid, 'field'=>$field]);
        // Notify followers (sauf modifier)
        collab_notify_followers($tenantSlug, $dossierId, $uid, "Champ $field modifié");
    } catch (Throwable $e) { /* swallow */ }
}

function collab_notify_followers(string $tenantSlug, int $dossierId, int $excludeUid, string $msg): void {
    try {
        $st = db()->prepare("SELECT user_id FROM dossier_followers WHERE tenant_slug=? AND dossier_id=? AND user_id != ?");
        $st->execute([$tenantSlug, $dossierId, $excludeUid]);
        $followers = $st->fetchAll(PDO::FETCH_COLUMN);
        if (!$followers) return;
        @require_once __DIR__ . '/push_notify.php';
        if (!function_exists('ocre_push_notify')) return;
        foreach ($followers as $fuid) {
            try { @ocre_push_notify((int)$fuid, 'collab', '🔔 Activité dossier #' . $dossierId, $msg, '/?dossier=' . $dossierId); } catch (Throwable $e) {}
        }
    } catch (Throwable $e) { /* swallow */ }
}
