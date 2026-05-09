<?php
// M100 — Audit usage legacy : compte users tenants legacy-only (ocre_session sans auth_users mappe).
// Decision cutover : si > 5% legacy-only -> reporter. Sinon cutover possible.
// Log les non-mappes dans /var/log/ocre-sso-legacy-only.log avec email + tenant_slug.

if (php_sapi_name() !== 'cli') {
    http_response_code(403); echo 'CLI only'; exit;
}

require_once __DIR__ . '/../api/db.php';

$pdo = new PDO(
    'mysql:host=' . DB_HOST . ';dbname=ocre_meta;charset=utf8mb4',
    DB_USER, DB_PASS,
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
);

// Total users actifs
$total = (int) $pdo->query("SELECT COUNT(*) FROM users WHERE archived_at IS NULL AND email IS NOT NULL AND email != ''")->fetchColumn();

// Users mappés SSO (ont une entrée auth_users)
$mapped = (int) $pdo->query("
    SELECT COUNT(*) FROM users u
    JOIN auth_users a ON LOWER(a.email) = LOWER(u.email)
    WHERE u.archived_at IS NULL AND u.email IS NOT NULL
")->fetchColumn();

// Users legacy-only (pas d'entrée auth_users)
$legacyOnlyRows = $pdo->query("
    SELECT u.id, u.email, u.slug, u.role, u.subscription_status,
           (SELECT MAX(s.expires_at) FROM user_sessions s WHERE s.user_id = u.id AND s.revoked_at IS NULL) AS last_session_expires
    FROM users u
    LEFT JOIN auth_users a ON LOWER(a.email) = LOWER(u.email)
    WHERE u.archived_at IS NULL AND u.email IS NOT NULL AND a.id IS NULL
    ORDER BY u.id
")->fetchAll();

// Sessions actives par source (last 7d)
$ssoSessions7d = (int) $pdo->query("SELECT COUNT(*) FROM auth_sessions WHERE revoked_at IS NULL AND created_at > DATE_SUB(NOW(), INTERVAL 7 DAY)")->fetchColumn();
$legacySessions7d = (int) $pdo->query("SELECT COUNT(*) FROM user_sessions WHERE revoked_at IS NULL AND created_at > DATE_SUB(NOW(), INTERVAL 7 DAY) AND expires_at > NOW()")->fetchColumn();

$pct = $total > 0 ? round(count($legacyOnlyRows) * 100.0 / $total, 1) : 0;
$decision = $pct > 5 ? 'REPORT_CUTOVER (legacy-only > 5%)' : 'CUTOVER_POSSIBLE (legacy-only <= 5%)';

// Log non-mappes
$logPath = '/var/log/ocre-sso-legacy-only.log';
$logHeader = '[' . date('c') . '] [sso_audit] start total=' . $total . ' mapped=' . $mapped . ' legacy_only=' . count($legacyOnlyRows) . ' pct=' . $pct . '%';
@file_put_contents($logPath, $logHeader . "\n", FILE_APPEND);
foreach ($legacyOnlyRows as $r) {
    $line = '[' . date('c') . '] [sso_audit] LEGACY_ONLY user_id=' . $r['id'] . ' email=' . $r['email']
          . ' slug=' . ($r['slug'] ?? 'NULL') . ' role=' . $r['role'] . ' sub=' . $r['subscription_status']
          . ' last_legacy_session_exp=' . ($r['last_session_expires'] ?? 'NONE');
    @file_put_contents($logPath, $line . "\n", FILE_APPEND);
}

echo json_encode([
    'date' => date('c'),
    'total_users_active' => $total,
    'sso_mapped' => $mapped,
    'sso_mapped_pct' => $total > 0 ? round($mapped * 100.0 / $total, 1) : 0,
    'legacy_only' => count($legacyOnlyRows),
    'legacy_only_pct' => $pct,
    'decision' => $decision,
    'sessions_active_7d' => [
        'sso' => $ssoSessions7d,
        'legacy' => $legacySessions7d,
    ],
    'legacy_only_emails' => array_map(fn($r) => $r['email'] . ' (slug=' . ($r['slug'] ?? 'NULL') . ')', $legacyOnlyRows),
    'log_path' => $logPath,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
