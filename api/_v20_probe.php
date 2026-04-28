<?php
// V20 — probe IP-whitelist : verif permissions DB pour multi-tenant.
require_once __DIR__ . '/db.php';
$allowed = ['46.225.215.148','127.0.0.1','::1'];
$remote = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? ($_SERVER['REMOTE_ADDR'] ?? '');
$ip = trim(explode(',', $remote)[0]);
if (!in_array($ip, $allowed, true)) { http_response_code(403); exit(json_encode(['ok'=>false,'error'=>'forbidden','seen_ip'=>$ip])); }
header('Content-Type: application/json; charset=utf-8');

$out = ['probes' => []];
try {
    $pdo = db();
    // 1. SHOW DATABASES
    try {
        $dbs = $pdo->query("SHOW DATABASES")->fetchAll(PDO::FETCH_COLUMN);
        $out['probes'][] = ['op' => 'SHOW DATABASES', 'ok' => true, 'count' => count($dbs), 'dbs' => $dbs];
    } catch (Throwable $e) {
        $out['probes'][] = ['op' => 'SHOW DATABASES', 'ok' => false, 'err' => $e->getMessage()];
    }
    // 2. SHOW GRANTS
    try {
        $grants = $pdo->query("SHOW GRANTS FOR CURRENT_USER")->fetchAll(PDO::FETCH_COLUMN);
        $out['probes'][] = ['op' => 'SHOW GRANTS', 'ok' => true, 'grants' => $grants];
    } catch (Throwable $e) {
        $out['probes'][] = ['op' => 'SHOW GRANTS', 'ok' => false, 'err' => $e->getMessage()];
    }
    // 3. CREATE DATABASE probe (test)
    try {
        $pdo->exec("CREATE DATABASE IF NOT EXISTS `ocre_v20_probe` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
        $out['probes'][] = ['op' => 'CREATE DATABASE ocre_v20_probe', 'ok' => true];
        // Cleanup
        try { $pdo->exec("DROP DATABASE `ocre_v20_probe`"); } catch (Throwable $e2) {}
    } catch (Throwable $e) {
        $out['probes'][] = ['op' => 'CREATE DATABASE', 'ok' => false, 'err' => $e->getMessage()];
    }
    // 4. Vérifier MySQL version
    try {
        $v = $pdo->query("SELECT VERSION()")->fetchColumn();
        $out['probes'][] = ['op' => 'VERSION', 'ok' => true, 'v' => $v];
    } catch (Throwable $e) {
        $out['probes'][] = ['op' => 'VERSION', 'ok' => false, 'err' => $e->getMessage()];
    }
    // 5. Tables existantes
    try {
        $tables = $pdo->query("SHOW TABLES")->fetchAll(PDO::FETCH_COLUMN);
        $out['probes'][] = ['op' => 'SHOW TABLES (current db)', 'ok' => true, 'count' => count($tables)];
    } catch (Throwable $e) {
        $out['probes'][] = ['op' => 'SHOW TABLES', 'ok' => false, 'err' => $e->getMessage()];
    }

    $out['ok'] = true;
} catch (Throwable $e) {
    $out['ok'] = false;
    $out['error'] = $e->getMessage();
}
echo json_encode($out, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
