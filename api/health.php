<?php
// M/2026/04/29/12 — Endpoint health public léger pour monitoring uptime.
// Pas d'auth (volontaire — endpoint très restreint, pas de leak de données).
require_once __DIR__ . '/db.php';
header('Content-Type: application/json; charset=utf-8');

$checks = [
    'php' => true,
    'db' => false,
    'storage_writable' => false,
    'timestamp' => date('c'),
];

try {
    $row = db()->query("SELECT 1 AS ok")->fetch();
    $checks['db'] = !empty($row['ok']);
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

$allOk = $checks['php'] && $checks['db'] && $checks['storage_writable'];
http_response_code($allOk ? 200 : 503);
echo json_encode($checks);
