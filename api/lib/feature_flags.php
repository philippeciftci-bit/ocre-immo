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

// M/2026/05/04/19 — wrapper email -> user_id (mapping whitelist email sur overrides existants).
function ff_enabled_for_email(string $flagKey, string $email): bool {
    if (!$email) return ff_enabled($flagKey);
    $pdo = ff_pdo();
    $st = $pdo->prepare("SELECT id FROM users WHERE email = ? AND archived_at IS NULL LIMIT 1");
    $st->execute([strtolower(trim($email))]);
    $uid = $st->fetchColumn();
    return ff_enabled($flagKey, $uid ? (int)$uid : null);
}

// Snapshot effectif tous flags pour un user (pour /api/flags_for_user.php).
function ff_snapshot_for_user(?int $userId): array {
    ff_ensure_schema();
    $rows = ff_pdo()->query("SELECT flag_key FROM feature_flags")->fetchAll();
    $out = [];
    foreach ($rows as $f) $out[$f['flag_key']] = ff_enabled($f['flag_key'], $userId);
    return $out;
}

// Synchronise les overrides user_id depuis CSV emails (pour panel admin).
function ff_sync_email_whitelist(string $flagKey, array $emails, int $actorUid): array {
    ff_ensure_schema();
    $pdo = ff_pdo();
    $emailsLc = array_values(array_filter(array_map(fn($e) => strtolower(trim((string)$e)), $emails)));
    $userIds = []; $unknown = [];
    foreach ($emailsLc as $em) {
        if ($em === '') continue;
        $st = $pdo->prepare("SELECT id FROM users WHERE email = ? AND archived_at IS NULL LIMIT 1");
        $st->execute([$em]);
        $uid = $st->fetchColumn();
        if ($uid) $userIds[] = (int)$uid; else $unknown[] = $em;
    }
    $userIds = array_values(array_unique($userIds));
    $exStmt = $pdo->prepare("SELECT user_id FROM feature_flags_overrides WHERE flag_key = ? AND user_id IS NOT NULL");
    $exStmt->execute([$flagKey]);
    $existing = array_map('intval', array_column($exStmt->fetchAll(), 'user_id'));
    $toAdd = array_diff($userIds, $existing);
    $toRemove = array_diff($existing, $userIds);
    $insert = $pdo->prepare("INSERT INTO feature_flags_overrides (flag_key, user_id, enabled, created_by) VALUES (?, ?, 1, ?)
                             ON DUPLICATE KEY UPDATE enabled = 1, created_by = VALUES(created_by)");
    foreach ($toAdd as $uid) $insert->execute([$flagKey, $uid, $actorUid]);
    if ($toRemove) {
        $in = implode(',', array_map('intval', $toRemove));
        $pdo->exec("DELETE FROM feature_flags_overrides WHERE flag_key = " . $pdo->quote($flagKey) . " AND user_id IN ($in)");
    }
    return ['added' => count($toAdd), 'removed' => count($toRemove), 'unknown_emails' => $unknown, 'total_active' => count($userIds)];
}

// Liste emails active pour un flag (UI panel admin).
function ff_emails_for_flag(string $flagKey): array {
    ff_ensure_schema();
    $st = ff_pdo()->prepare(
        "SELECT u.email FROM feature_flags_overrides o
         JOIN users u ON u.id = o.user_id
         WHERE o.flag_key = ? AND o.user_id IS NOT NULL AND o.enabled = 1
         ORDER BY u.email"
    );
    $st->execute([$flagKey]);
    return array_column($st->fetchAll(), 'email');
}

}
