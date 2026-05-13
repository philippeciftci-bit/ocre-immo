<?php
// M/2026/04/29/12 — Endpoint health public léger pour monitoring uptime.
// M/2026/05/14/2 — Expose schema_version + wsp + clients_count.
// Pas d'auth (volontaire — endpoint très restreint, pas de leak de données).
// On require config (pas db.php) car db.php fait le check schema qui ferait
// boucler 503 sur SCHEMA_DRIFT alors qu'on veut juste reporter le statut.
require_once __DIR__ . '/config.php';
header('Content-Type: application/json; charset=utf-8');

$checks = [
    'php' => true,
    'db' => false,
    'storage_writable' => false,
    'timestamp' => date('c'),
    'wsp' => defined('OCRE_WSP_SLUG') ? OCRE_WSP_SLUG : '',
    'schema_version_required' => defined('SCHEMA_VERSION_REQUIRED') ? SCHEMA_VERSION_REQUIRED : '',
    'schema_version_current' => null,
    'schema_status' => 'unknown',
    // M/2026/05/14/7 — liste des versions presentes dans /opt/ocre-app/migrations/versions/
    // mais ABSENTES dans _schema_migrations du wsp courant. Vide si tout est applique.
    'migrations_pending' => [],
    'clients_count' => null,
];

try {
    $dsn = 'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=' . DB_CHARSET;
    $pdo = new PDO($dsn, DB_USER, DB_PASS, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
    $checks['db'] = (bool)$pdo->query("SELECT 1 AS ok")->fetch();
    try {
        $cur = $pdo->query("SELECT MAX(name) AS v FROM _schema_migrations")->fetch();
        $checks['schema_version_current'] = $cur['v'] ?? null;
        // Liste des versions appliquees (set).
        $appliedStmt = $pdo->query("SELECT name FROM _schema_migrations");
        $applied = [];
        foreach ($appliedStmt->fetchAll() as $r) { $applied[$r['name']] = true; }

        // Scan repertoire versions et collecte les non-appliquees.
        $versionsDir = '/opt/ocre-app/migrations/versions';
        $pending = [];
        if (is_dir($versionsDir)) {
            $files = scandir($versionsDir);
            sort($files);
            foreach ($files as $f) {
                if (!preg_match('/^(V[0-9]+__.*)\.sql$/', $f, $m)) continue;
                $vname = $m[1];
                if (!isset($applied[$vname])) $pending[] = $vname;
            }
        }
        $checks['migrations_pending'] = $pending;

        // Logique fine status:
        //   OK       : 0 pending et current >= required
        //   DRIFT    : 1-2 pending OU current < required en retard de 1-2
        //   CRITICAL : 3+ pending OU table _schema_migrations absente
        if (defined('SCHEMA_VERSION_REQUIRED') && $checks['schema_version_current']) {
            $nPending = count($pending);
            if ($nPending === 0 && strcmp($checks['schema_version_current'], SCHEMA_VERSION_REQUIRED) >= 0) {
                $checks['schema_status'] = 'OK';
            } elseif ($nPending >= 3) {
                $checks['schema_status'] = 'CRITICAL';
            } else {
                $checks['schema_status'] = 'DRIFT';
            }
        } else {
            $checks['schema_status'] = 'ABSENT';
        }
    } catch (Throwable $e) {
        $checks['schema_status'] = 'CRITICAL';
    }
    try {
        $cnt = $pdo->query("SELECT COUNT(*) AS c FROM clients")->fetch();
        $checks['clients_count'] = (int)($cnt['c'] ?? 0);
    } catch (Throwable $e) {}
} catch (Throwable $e) {
    $checks['db_error'] = substr($e->getMessage(), 0, 100);
}

$uploadsDir = '/opt/ocre-app/uploads';
if (is_dir($uploadsDir) && is_writable($uploadsDir)) {
    $checks['storage_writable'] = true;
}

// Disque libre (best-effort)
try {
    $free = @disk_free_space('/');
    $total = @disk_total_space('/');
    if ($free && $total) $checks['disk_free_pct'] = round(100 * $free / $total, 1);
} catch (Throwable $e) {}

$allOk = $checks['php'] && $checks['db'] && $checks['storage_writable']
         && ($checks['schema_status'] === 'OK');
http_response_code($allOk ? 200 : 503);
echo json_encode($checks);
