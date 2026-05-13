<?php
// M/2026/05/13/20 — Metrics M98 : calcul quotidien KPIs investor-ready + upsert metrics_daily.
require_once __DIR__ . '/../api/db.php';
$LOG = '/var/log/ocre-metrics.log';
function mlog($msg, $log) { $line = '[' . date('Y-m-d H:i:s') . '] ' . $msg; echo $line . "\n"; @file_put_contents($log, $line . "\n", FILE_APPEND); }

mlog('=== metrics_daily START ===', $LOG);

$meta = new PDO('mysql:host=' . DB_HOST . ';dbname=ocre_meta;charset=utf8mb4', DB_USER, DB_PASS, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
$admin = new PDO('mysql:host=' . DB_HOST . ';charset=utf8mb4', DB_USER, DB_PASS, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$date = date('Y-m-d');

function q1($pdo, $sql, $params = []) { $s = $pdo->prepare($sql); $s->execute($params); $r = $s->fetch(PDO::FETCH_ASSOC); return $r ? array_values($r)[0] : null; }
function upsert($meta, $date, $key, $value, $log) {
    $st = $meta->prepare("INSERT INTO metrics_daily (metric_date, kpi_key, kpi_value) VALUES (?,?,?) ON DUPLICATE KEY UPDATE kpi_value = VALUES(kpi_value)");
    $st->execute([$date, $key, $value]);
    mlog("  $key = $value", $log);
}

// Users metrics
upsert($meta, $date, 'mau', (int)q1($meta, "SELECT COUNT(DISTINCT id) FROM users WHERE last_login_at > DATE_SUB(NOW(), INTERVAL 30 DAY)"), $LOG);
upsert($meta, $date, 'wau', (int)q1($meta, "SELECT COUNT(DISTINCT id) FROM users WHERE last_login_at > DATE_SUB(NOW(), INTERVAL 7 DAY)"), $LOG);
upsert($meta, $date, 'dau', (int)q1($meta, "SELECT COUNT(DISTINCT id) FROM users WHERE last_login_at > DATE_SUB(NOW(), INTERVAL 1 DAY)"), $LOG);
upsert($meta, $date, 'new_signups_24h', (int)q1($meta, "SELECT COUNT(*) FROM users WHERE created_at > DATE_SUB(NOW(), INTERVAL 1 DAY)"), $LOG);
upsert($meta, $date, 'total_users_active', (int)q1($meta, "SELECT COUNT(*) FROM users WHERE COALESCE(anonymized_at,'') = '' AND COALESCE(is_suspended,0) = 0"), $LOG);
upsert($meta, $date, 'total_users_pending_deletion', (int)q1($meta, "SELECT COUNT(*) FROM users WHERE deletion_requested_at IS NOT NULL AND anonymized_at IS NULL"), $LOG);
upsert($meta, $date, 'total_users_anonymized', (int)q1($meta, "SELECT COUNT(*) FROM users WHERE anonymized_at IS NOT NULL"), $LOG);

// Tenants
$dbs = $admin->query("SHOW DATABASES LIKE 'ocre_wsp_%'")->fetchAll(PDO::FETCH_COLUMN);
upsert($meta, $date, 'total_tenants_active', count($dbs), $LOG);

// Volume metrics
$totalDossiers = 0; $dossiers24h = 0; $totalUploads = 0;
foreach ($dbs as $db) {
    try {
        $totalDossiers += (int)q1($admin, "SELECT COUNT(*) FROM `$db`.`clients` WHERE deleted_at IS NULL");
        $dossiers24h += (int)q1($admin, "SELECT COUNT(*) FROM `$db`.`clients` WHERE created_at > DATE_SUB(NOW(), INTERVAL 1 DAY)");
    } catch (Throwable $e) {}
}
upsert($meta, $date, 'total_dossiers', $totalDossiers, $LOG);
upsert($meta, $date, 'dossiers_created_24h', $dossiers24h, $LOG);

// Revenue (best-effort, retourne 0 si pas de billing_invoices presente).
try {
    $mrr = (float)q1($meta, "SELECT COALESCE(SUM(amount_cents),0)/100 FROM billing_invoices WHERE status='paid' AND paid_at > DATE_SUB(NOW(), INTERVAL 30 DAY)");
} catch (Throwable $e) { $mrr = 0; }
upsert($meta, $date, 'mrr', $mrr, $LOG);
upsert($meta, $date, 'arr', $mrr * 12, $LOG);

mlog('=== END ===', $LOG);
