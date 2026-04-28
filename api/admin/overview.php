<?php
// M/2026/04/28/52 — Dashboard super-admin : stats agrégées.
require_once __DIR__ . '/../db.php';
setCorsHeaders();

$user = requireAuth();
$isSuper = ($user['role'] ?? '') === 'super_admin' || ($user['_origin_role'] ?? '') === 'super_admin';
if (!$isSuper) jsonError('Accès refusé (super_admin requis)', 403);

$dsn = 'mysql:host=' . DB_HOST . ';dbname=ocre_meta;charset=utf8mb4';
$meta = new PDO($dsn, DB_USER, DB_PASS, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
]);

// M/2026/04/29/4 — ALTER idempotent is_suspended (smoke test caught missing column).
try {
    $cols = $meta->query("SHOW COLUMNS FROM users")->fetchAll(PDO::FETCH_COLUMN);
    if (!in_array('is_suspended', $cols, true)) {
        $meta->exec("ALTER TABLE users ADD COLUMN is_suspended TINYINT(1) NOT NULL DEFAULT 0");
    }
} catch (Throwable $e) {}

$stats = [];
$stats['users_total'] = (int) $meta->query("SELECT COUNT(*) FROM users WHERE archived_at IS NULL")->fetchColumn();
$stats['users_suspended'] = (int) $meta->query("SELECT COUNT(*) FROM users WHERE COALESCE(is_suspended, 0) = 1")->fetchColumn();
$stats['users_super_admin'] = (int) $meta->query("SELECT COUNT(*) FROM users WHERE role = 'super_admin'")->fetchColumn();
$stats['workspaces_total'] = (int) $meta->query("SELECT COUNT(*) FROM workspaces WHERE archived_at IS NULL")->fetchColumn();

// Itérer toutes les DBs ocre_wsp_* pour stats transverses (best-effort).
$dossiersTotal = 0;
$dossiersDeleted = 0;
$matchesTotal = 0;
$dossiers7d = 0;
$matches7d = 0;
$byProfil = ['Acheteur'=>0,'Vendeur'=>0,'Bailleur'=>0,'Locataire'=>0,'Investisseur'=>0];
$tenants = [];
try {
    $sysDsn = 'mysql:host=' . DB_HOST . ';charset=utf8mb4';
    $sys = new PDO($sysDsn, DB_USER, DB_PASS, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $dbs = $sys->query("SHOW DATABASES LIKE 'ocre_wsp_%'")->fetchAll(PDO::FETCH_COLUMN);
    foreach ($dbs as $dbName) {
        try {
            $td = new PDO('mysql:host=' . DB_HOST . ';dbname=' . $dbName . ';charset=utf8mb4', DB_USER, DB_PASS, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            ]);
            $cTotal = (int) $td->query("SELECT COUNT(*) FROM clients WHERE deleted_at IS NULL")->fetchColumn();
            $cDel = (int) $td->query("SELECT COUNT(*) FROM clients WHERE deleted_at IS NOT NULL")->fetchColumn();
            $c7 = (int) $td->query("SELECT COUNT(*) FROM clients WHERE deleted_at IS NULL AND created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)")->fetchColumn();
            $dossiersTotal += $cTotal;
            $dossiersDeleted += $cDel;
            $dossiers7d += $c7;
            $byP = $td->query("SELECT projet, COUNT(*) c FROM clients WHERE deleted_at IS NULL GROUP BY projet")->fetchAll();
            foreach ($byP as $r) {
                if (isset($byProfil[$r['projet']])) $byProfil[$r['projet']] += (int) $r['c'];
            }
            $mTotal = 0; $m7 = 0;
            try {
                $mTotal = (int) $td->query("SELECT COUNT(*) FROM matches")->fetchColumn();
                $m7 = (int) $td->query("SELECT COUNT(*) FROM matches WHERE created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)")->fetchColumn();
            } catch (Throwable $e) {}
            $matchesTotal += $mTotal;
            $matches7d += $m7;
            $tenants[] = ['db' => $dbName, 'dossiers' => $cTotal, 'matches' => $mTotal];
        } catch (Throwable $e) { /* skip tenant illisible */ }
    }
} catch (Throwable $e) {}

$stats['dossiers_total'] = $dossiersTotal;
$stats['dossiers_deleted'] = $dossiersDeleted;
$stats['dossiers_7d'] = $dossiers7d;
$stats['by_profil'] = $byProfil;
$stats['matches_total'] = $matchesTotal;
$stats['matches_7d'] = $matches7d;
$stats['tenants'] = $tenants;

// Backup status (dernier fichier dans /var/backups/ocre/).
$backup = ['last_at' => null, 'last_db_count' => 0];
try {
    $files = @glob('/var/backups/ocre/db/*.sql.gz.enc') ?: [];
    if ($files) {
        usort($files, fn($a, $b) => filemtime($b) - filemtime($a));
        $backup['last_at'] = date('c', filemtime($files[0]));
        $backup['last_db_count'] = count(array_filter($files, fn($f) => filemtime($f) > strtotime('-7 hours'))); // ~dernier run
    }
} catch (Throwable $e) {}
$stats['backup'] = $backup;

// Disque utilisé
try {
    $diskUploads = trim((string) @shell_exec('du -sb /opt/ocre-app/uploads 2>/dev/null | awk "{print \$1}"'));
    $stats['uploads_bytes'] = (int) $diskUploads;
} catch (Throwable $e) { $stats['uploads_bytes'] = 0; }

jsonOk(['stats' => $stats]);
