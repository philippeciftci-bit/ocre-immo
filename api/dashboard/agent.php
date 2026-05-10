<?php
// M112 — GET /api/dashboard/agent.php
// Retourne JSON KPIs + chart_data + lists pour le tenant courant.

require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../_session.php';

setCorsHeaders();
$user = getCurrentUserDualMode();
if (!$user || !empty($user['_no_tenant_user']) || !empty($user['_tenant_mismatch'])) jsonError('Non authentifie', 401);
$tenant = $user['slug'];
if (!$tenant) jsonError('Tenant requis', 400);

$tenantDb = 'ocre_wsp_' . $tenant;
try {
    $pdo = new PDO('mysql:host=' . DB_HOST . ';dbname=' . $tenantDb . ';charset=utf8mb4', DB_USER, DB_PASS,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
} catch (Throwable $e) { jsonError('DB tenant error', 500); }

$q = function(string $sql, array $args = []) use ($pdo) {
    try { $st = $pdo->prepare($sql); $st->execute($args); return $st->fetchAll(); }
    catch (Throwable $e) { return []; }
};
$qOne = function(string $sql, array $args = []) use ($pdo) {
    try { $st = $pdo->prepare($sql); $st->execute($args); $r = $st->fetch(); return $r ? array_values($r)[0] : null; }
    catch (Throwable $e) { return null; }
};

// 6 KPIs
$dossiersActifs = (int) $qOne("SELECT COUNT(*) FROM clients WHERE statut='enregistre' AND is_draft=0");
$dossiersDrafts = (int) $qOne("SELECT COUNT(*) FROM clients WHERE is_draft=1");
$dossiersTotal = (int) $qOne("SELECT COUNT(*) FROM clients");
$tauxConversion = $dossiersTotal > 0 ? round($dossiersActifs * 100.0 / max(1, $dossiersTotal), 1) : 0;

// Matchings 30j (depuis match_queue ou notifications)
$matchings30j = (int) $qOne("SELECT COUNT(*) FROM notifications WHERE created_at > DATE_SUB(NOW(), INTERVAL 30 DAY) AND (type LIKE '%match%' OR type LIKE '%pact%')");

// Photos uploadees 30j (heuristique sur photo_compression_stats si dispo)
$photos30j = (int) $qOne("SELECT COUNT(*) FROM photo_compression_stats WHERE created_at > DATE_SUB(NOW(), INTERVAL 30 DAY)");
if ($photos30j === 0) $photos30j = (int) $qOne("SELECT COUNT(*) FROM clients WHERE updated_at > DATE_SUB(NOW(), INTERVAL 30 DAY)");

// Dossiers vendus/loues 30j (heuristique : statut archive recent)
$vendusLoues30j = (int) $qOne("SELECT COUNT(*) FROM clients WHERE statut='archive' AND updated_at > DATE_SUB(NOW(), INTERVAL 30 DAY)");

// Pacts signes (channel_mappings unique tenants ? Non, pacts = future feature. Stub 0)
$pactsSignes = 0;

// Charts
// Activité 30j : courbe nb dossiers crees par jour
$activity30 = $q("SELECT DATE(created_at) AS d, COUNT(*) AS n FROM clients WHERE created_at > DATE_SUB(NOW(), INTERVAL 30 DAY) GROUP BY DATE(created_at) ORDER BY d");
$activityLabels = []; $activityData = [];
$startDate = strtotime('-29 days', strtotime(date('Y-m-d')));
for ($i = 0; $i < 30; $i++) {
    $day = date('Y-m-d', strtotime("+$i day", $startDate));
    $activityLabels[] = $day;
    $activityData[] = 0;
}
foreach ($activity30 as $r) {
    $idx = array_search($r['d'], $activityLabels);
    if ($idx !== false) $activityData[$idx] = (int) $r['n'];
}

// Top 5 villes
$topVilles = $q("SELECT ville, COUNT(*) AS n FROM clients WHERE ville IS NOT NULL AND ville != '' GROUP BY ville ORDER BY n DESC LIMIT 5");

// Repartition par profil
$profils = $q("SELECT projet, COUNT(*) AS n FROM clients WHERE projet IS NOT NULL AND projet != '' GROUP BY projet ORDER BY n DESC");

// Heatmap : usage par heure (day-of-week × hour basique). Pour V1 : juste hour (0-23)
$heatmapByHour = $q("SELECT HOUR(created_at) AS h, COUNT(*) AS n FROM clients WHERE created_at > DATE_SUB(NOW(), INTERVAL 90 DAY) GROUP BY HOUR(created_at)");
$heatmapData = array_fill(0, 24, 0);
foreach ($heatmapByHour as $r) $heatmapData[(int) $r['h']] = (int) $r['n'];

// Listes
$lastModified = $q("SELECT id, prenom, nom, projet, statut, updated_at FROM clients ORDER BY updated_at DESC LIMIT 5");
$lastNotifs = $q("SELECT id, type, title, body, created_at FROM notifications ORDER BY created_at DESC LIMIT 5");

jsonResponse([
    'ok' => true,
    'tenant' => $tenant,
    'kpis' => [
        'dossiers_actifs' => $dossiersActifs,
        'matchings_30j' => $matchings30j,
        'pacts_signes' => $pactsSignes,
        'taux_conversion_pct' => $tauxConversion,
        'photos_30j' => $photos30j,
        'vendus_loues_30j' => $vendusLoues30j,
    ],
    'charts' => [
        'activity_30d' => ['labels' => $activityLabels, 'data' => $activityData],
        'top_villes' => $topVilles,
        'profils' => $profils,
        'heatmap_by_hour' => $heatmapData,
    ],
    'lists' => [
        'last_modified' => $lastModified,
        'last_notifs' => $lastNotifs,
    ],
]);
