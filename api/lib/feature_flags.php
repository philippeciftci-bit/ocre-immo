<?php
// M/2026/04/28/63 — Feature flags helper. Resolution : user override > workspace override > rollout pct > default.
if (!function_exists('ff_enabled')) {

function ff_ensure_schema(): void {
    static $done = false;
    if ($done) return;
    try {
        $dsn = 'mysql:host=' . DB_HOST . ';dbname=ocre_meta;charset=utf8mb4';
        $pdo = new PDO($dsn, DB_USER, DB_PASS, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $pdo->exec("CREATE TABLE IF NOT EXISTS feature_flags (
            flag_key VARCHAR(100) PRIMARY KEY,
            description TEXT,
            default_value TINYINT(1) DEFAULT 0,
            rollout_pct INT DEFAULT 0,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            updated_by INT UNSIGNED NULL
        ) CHARACTER SET utf8mb4");
        $pdo->exec("CREATE TABLE IF NOT EXISTS feature_flags_overrides (
            id BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
            flag_key VARCHAR(100) NOT NULL,
            user_id INT UNSIGNED NULL,
            workspace_id INT UNSIGNED NULL,
            enabled TINYINT(1) NOT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            created_by INT UNSIGNED NULL,
            UNIQUE KEY uniq_flag_user (flag_key, user_id),
            UNIQUE KEY uniq_flag_workspace (flag_key, workspace_id),
            INDEX idx_flag (flag_key)
        ) CHARACTER SET utf8mb4");
        // INSERT IGNORE des 10 flags initiaux.
        $initial = [
            ['scan_web_enabled', 'Activer le bouton Scan Web (sources externes)', 1],
            ['edit_consent_email_notifs', 'Envoyer notifs email pour edits pending', 0],
            ['telegram_inline_buttons', 'Boutons Valider/Refuser inline dans Telegram', 1],
            ['photos_compression_webp', 'Compression auto WebP des photos uploadées', 1],
            ['matching_auto_on_save', 'Recalcul matching auto à chaque save dossier', 1],
            ['export_pdf_dossier', 'Bouton export PDF d\'un dossier', 0],
            ['calendar_subscription_url', 'URL subscription .ics live pour calendar app', 1],
            ['admin_dashboard_v2', 'Nouveau dashboard super-admin (vs ancien)', 1],
            ['beta_ai_assistant', 'Assistant IA pour rédaction notes (beta)', 0],
            ['multi_currency_dossier', 'Possibilité d\'avoir plusieurs devises par dossier', 0],
        ];
        $st = $pdo->prepare("INSERT IGNORE INTO feature_flags (flag_key, description, default_value) VALUES (?, ?, ?)");
        foreach ($initial as $f) $st->execute($f);
    } catch (Throwable $e) {}
    $done = true;
}

function ff_pdo(): PDO {
    static $p = null;
    if ($p) return $p;
    $p = new PDO('mysql:host=' . DB_HOST . ';dbname=ocre_meta;charset=utf8mb4', DB_USER, DB_PASS, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
    return $p;
}

function ff_enabled(string $flagKey, ?int $userId = null): bool {
    static $cache = [];
    $cacheKey = $flagKey . ':' . ($userId ?? '');
    if (array_key_exists($cacheKey, $cache)) return $cache[$cacheKey];
    ff_ensure_schema();
    $pdo = ff_pdo();
    // 1. User override
    if ($userId) {
        $st = $pdo->prepare("SELECT enabled FROM feature_flags_overrides WHERE flag_key = ? AND user_id = ? LIMIT 1");
        $st->execute([$flagKey, $userId]);
        $r = $st->fetch();
        if ($r) return $cache[$cacheKey] = (bool) $r['enabled'];
    }
    // 2. Workspace override (best-effort : tous les workspaces du user)
    if ($userId) {
        $ws = $pdo->prepare("SELECT workspace_id FROM workspace_members WHERE user_id = ? AND left_at IS NULL");
        $ws->execute([$userId]);
        $wids = array_column($ws->fetchAll(), 'workspace_id');
        if ($wids) {
            $in = implode(',', array_map('intval', $wids));
            $r = $pdo->query("SELECT enabled FROM feature_flags_overrides WHERE flag_key = " . $pdo->quote($flagKey) . " AND workspace_id IN ($in) LIMIT 1")->fetch();
            if ($r) return $cache[$cacheKey] = (bool) $r['enabled'];
        }
    }
    // 3. Default + rollout
    $st = $pdo->prepare("SELECT default_value, rollout_pct FROM feature_flags WHERE flag_key = ?");
    $st->execute([$flagKey]);
    $flag = $st->fetch();
    if (!$flag) return $cache[$cacheKey] = false;
    if ((int) $flag['rollout_pct'] > 0 && $userId) {
        $hash = abs(crc32("{$flagKey}:{$userId}")) % 100;
        return $cache[$cacheKey] = $hash < (int) $flag['rollout_pct'];
    }
    return $cache[$cacheKey] = (bool) $flag['default_value'];
}

function ff_list(): array {
    ff_ensure_schema();
    return ff_pdo()->query("SELECT * FROM feature_flags ORDER BY flag_key")->fetchAll();
}

function ff_overrides(string $flagKey): array {
    ff_ensure_schema();
    $st = ff_pdo()->prepare("SELECT * FROM feature_flags_overrides WHERE flag_key = ? ORDER BY created_at DESC");
    $st->execute([$flagKey]);
    return $st->fetchAll();
}

}
