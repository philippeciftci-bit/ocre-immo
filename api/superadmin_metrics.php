<?php
// M/2026/05/09/47 — M93 : KPIs business superadmin (MRR / ARR / churn / conversion + top tenants).
// Auth super_admin obligatoire (cookie ocre_session role=super_admin).
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/_session.php';
setCorsHeaders();

$user = getCurrentUserFromCookie();
if (!$user || ($user['role'] ?? '') !== 'super_admin') {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'super_admin_required']);
    exit;
}

$dsn = 'mysql:host=' . DB_HOST . ';dbname=ocre_meta;charset=utf8mb4';
$pdo = new PDO($dsn, DB_USER, DB_PASS, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);

// MRR : nombre de tenants actifs (slug != null + non archivés) × hypothèse 49 € PRO
// (À remplacer par lecture stripe_customers/subscriptions quand intégration paiement live.)
$st = $pdo->query("SELECT COUNT(*) AS n FROM users WHERE slug IS NOT NULL AND archived_at IS NULL AND role = 'agent'");
$activeTenants = (int) ($st->fetch()['n'] ?? 0);
$mrr = $activeTenants * 49;
$arr = $mrr * 12;

// Churn : tenants archivés sur 30 derniers jours / total
$st = $pdo->query("SELECT COUNT(*) AS n FROM users WHERE slug IS NOT NULL AND archived_at IS NOT NULL AND archived_at > DATE_SUB(NOW(), INTERVAL 30 DAY)");
$churn30 = (int) ($st->fetch()['n'] ?? 0);
$totalEverTenants = max(1, $activeTenants + $churn30);
$churnPct = round($churn30 / $totalEverTenants * 1000) / 10;

// Conversion trial→paid : difficile sans table billing → stub 0 % en attendant
$conversionPct = 0;

// Top tenants par activité : pour chaque tenant, COUNT clients
$st = $pdo->query("SELECT id, slug FROM users WHERE slug IS NOT NULL AND archived_at IS NULL ORDER BY id ASC");
$tenants = $st->fetchAll();
$topTenants = [];
foreach (array_slice($tenants, 0, 20) as $t) {
    $slug = $t['slug'];
    if (!preg_match('/^[a-z0-9-]+$/', $slug)) continue;
    $dbName = 'ocre_wsp_' . $slug;
    $dossiers = 0; $matchings = 0;
    try {
        $tpdo = new PDO("mysql:host=" . DB_HOST . ";dbname=" . $dbName . ";charset=utf8mb4", DB_USER, DB_PASS, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $r = $tpdo->query("SELECT COUNT(*) FROM clients WHERE deleted_at IS NULL")->fetchColumn();
        $dossiers = (int) $r;
        try {
            $r2 = $tpdo->query("SELECT COUNT(*) FROM matches")->fetchColumn();
            $matchings = (int) $r2;
        } catch (Throwable $_) {}
    } catch (Throwable $_) {}
    $topTenants[] = ['slug' => $slug, 'dossiers' => $dossiers, 'matchings' => $matchings];
}
usort($topTenants, function ($a, $b) { return ($b['dossiers'] + $b['matchings']) - ($a['dossiers'] + $a['matchings']); });
$topTenants = array_slice($topTenants, 0, 10);

echo json_encode([
    'ok' => true,
    'kpis' => [
        'mrr_eur' => $mrr,
        'arr_eur' => $arr,
        'churn_pct_30d' => $churnPct,
        'conversion_trial_paid_pct' => $conversionPct,
        'active_tenants' => $activeTenants,
    ],
    'top_tenants' => $topTenants,
    'note' => 'MRR/conversion : hypothèse 49 €/PRO. À remplacer par stripe_customers quand billing live.',
]);
