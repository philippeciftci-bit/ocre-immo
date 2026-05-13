<?php
// M/2026/05/13/19 — Superadmin gestion tenants : list + get (stats par tenant).
require_once __DIR__ . '/superadmin_lib.php';
superadmin_or_403();
header('Content-Type: application/json');

$action = $_GET['action'] ?? 'list';
$admin = new PDO('mysql:host=' . DB_HOST . ';charset=utf8mb4', DB_USER, DB_PASS, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);

if ($action === 'list') {
    $dbs = $admin->query("SHOW DATABASES LIKE 'ocre_wsp_%'")->fetchAll(PDO::FETCH_COLUMN);
    $tenants = [];
    foreach ($dbs as $db) {
        $slug = substr($db, strlen('ocre_wsp_'));
        $row = ['slug' => $slug, 'db' => $db];
        try {
            $r = $admin->query("SELECT COUNT(*) c, COALESCE(SUM(LENGTH(data)),0) bytes FROM `$db`.`clients` WHERE deleted_at IS NULL")->fetch();
            $row['clients_count'] = (int)$r['c'];
            $row['data_bytes'] = (int)$r['bytes'];
        } catch (Throwable $e) { $row['clients_count'] = null; $row['data_bytes'] = null; }
        $tenants[] = $row;
    }
    echo json_encode(['ok' => true, 'tenants' => $tenants]);
    exit;
}

if ($action === 'get') {
    $slug = (string)($_GET['slug'] ?? '');
    if (!preg_match('/^[a-z0-9-]{1,64}$/i', $slug)) { http_response_code(400); echo json_encode(['ok'=>false,'error'=>'invalid_slug']); exit; }
    $db = 'ocre_wsp_' . $slug;
    try {
        $r = $admin->query("SELECT COUNT(*) c, MIN(created_at) first, MAX(updated_at) last FROM `$db`.`clients`")->fetch();
        echo json_encode(['ok' => true, 'slug' => $slug, 'stats' => $r]);
    } catch (Throwable $e) { http_response_code(404); echo json_encode(['ok'=>false,'error'=>'tenant_not_found']); }
    exit;
}

http_response_code(404); echo json_encode(['ok'=>false,'error'=>'unknown_action']);
