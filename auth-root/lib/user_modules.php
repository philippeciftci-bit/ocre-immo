<?php
// M_OCRE_PARCOURS_V4 — Helper user_modules + ensure schema lazy + flags PWA
// Table auth_user_modules : module debloque par user (premier login outil = activation auto)
// Colonnes auth_users etendues : pwa_installed + pwa_install_refused_at (deja first_name/last_name/societe via M_OCRE_AGENT_SIGNUP_V1)

require_once __DIR__ . '/auth_db.php';

function um_ensure_schema(): void {
    static $done = false;
    if ($done) return;
    try {
        auth_db()->exec("CREATE TABLE IF NOT EXISTS auth_user_modules (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            user_id INT UNSIGNED NOT NULL,
            module_slug VARCHAR(32) NOT NULL,
            active TINYINT(1) DEFAULT 1,
            activated_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uniq_user_module (user_id, module_slug),
            INDEX idx_user (user_id),
            INDEX idx_module (module_slug)
        ) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
        // Extension auth_users pour PWA flags (idempotent via try/catch)
        foreach ([
            "ALTER TABLE auth_users ADD COLUMN pwa_installed TINYINT(1) DEFAULT 0",
            "ALTER TABLE auth_users ADD COLUMN pwa_install_refused_at DATETIME NULL",
        ] as $sql) {
            try { auth_db()->exec($sql); } catch (Throwable $e) { /* duplicate column */ }
        }
    } catch (Throwable $e) { /* swallow */ }
    $done = true;
}

function um_activate(int $userId, string $moduleSlug): void {
    um_ensure_schema();
    $moduleSlug = preg_replace('/[^a-z]/', '', strtolower($moduleSlug));
    if (!$moduleSlug) return;
    try {
        auth_db()->prepare("INSERT INTO auth_user_modules (user_id, module_slug, active) VALUES (?, ?, 1) ON DUPLICATE KEY UPDATE active=1, activated_at=NOW()")->execute([$userId, $moduleSlug]);
    } catch (Throwable $e) { /* swallow */ }
}

function um_list(int $userId): array {
    um_ensure_schema();
    try {
        $st = auth_db()->prepare("SELECT module_slug, active, activated_at FROM auth_user_modules WHERE user_id = ? AND active = 1");
        $st->execute([$userId]);
        return $st->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) { return []; }
}

function um_has(int $userId, string $moduleSlug): bool {
    um_ensure_schema();
    $moduleSlug = preg_replace('/[^a-z]/', '', strtolower($moduleSlug));
    try {
        $st = auth_db()->prepare("SELECT 1 FROM auth_user_modules WHERE user_id = ? AND module_slug = ? AND active = 1 LIMIT 1");
        $st->execute([$userId, $moduleSlug]);
        return (bool) $st->fetchColumn();
    } catch (Throwable $e) { return false; }
}
