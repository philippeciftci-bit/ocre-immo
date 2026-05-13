<?php
// M/2026/05/13/19 — Superadmin dashboard KPIs.
require_once __DIR__ . '/superadmin_lib.php';
superadmin_or_403();
header('Content-Type: application/json');

$meta = new PDO('mysql:host=' . DB_HOST . ';dbname=ocre_meta;charset=utf8mb4', DB_USER, DB_PASS, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);

function q($pdo, $sql, $params = []) { $s = $pdo->prepare($sql); $s->execute($params); return $s->fetch(); }

$kpis = [
    'total_users_active' => (int)(q($meta, "SELECT COUNT(*) c FROM users WHERE COALESCE(anonymized_at, '') = '' AND COALESCE(is_suspended, 0) = 0")['c'] ?? 0),
    'total_users_suspended' => (int)(q($meta, "SELECT COUNT(*) c FROM users WHERE is_suspended = 1")['c'] ?? 0),
    'total_users_pending_deletion' => (int)(q($meta, "SELECT COUNT(*) c FROM users WHERE deletion_requested_at IS NOT NULL AND anonymized_at IS NULL")['c'] ?? 0),
    'total_users_anonymized' => (int)(q($meta, "SELECT COUNT(*) c FROM users WHERE anonymized_at IS NOT NULL")['c'] ?? 0),
    'new_users_24h' => (int)(q($meta, "SELECT COUNT(*) c FROM users WHERE created_at > DATE_SUB(NOW(), INTERVAL 1 DAY)")['c'] ?? 0),
    'active_sessions_24h' => (int)(q($meta, "SELECT COUNT(DISTINCT user_id) c FROM auth_sessions WHERE created_at > DATE_SUB(NOW(), INTERVAL 1 DAY) AND revoked_at IS NULL")['c'] ?? 0),
    'total_2fa_enabled' => (int)(q($meta, "SELECT COUNT(*) c FROM users WHERE totp_enabled = 1")['c'] ?? 0),
];

// Tenants : compte DBs ocre_wsp_*.
$admin = new PDO('mysql:host=' . DB_HOST . ';charset=utf8mb4', DB_USER, DB_PASS, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$tenantDbs = $admin->query("SHOW DATABASES LIKE 'ocre_wsp_%'")->fetchAll(PDO::FETCH_COLUMN);
$kpis['total_tenants_active'] = count($tenantDbs);

// Dossiers 24h : somme sur tous tenants (best-effort, skip si tenant erreur).
$dossiers24h = 0;
foreach ($tenantDbs as $db) {
    try {
        $s = $admin->prepare("SELECT COUNT(*) c FROM `$db`.`clients` WHERE created_at > DATE_SUB(NOW(), INTERVAL 1 DAY)");
        $s->execute();
        $dossiers24h += (int)($s->fetch(PDO::FETCH_ASSOC)['c'] ?? 0);
    } catch (Throwable $e) {}
}
$kpis['dossiers_created_24h'] = $dossiers24h;

echo json_encode(['ok' => true, 'kpis' => $kpis, 'generated_at' => date('c')], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
