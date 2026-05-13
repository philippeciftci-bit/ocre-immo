<?php
// M/2026/05/13/18 — SSO verify : retourne user data depuis cookie signe.
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/sso_lib.php';
setCorsHeaders();

$data = sso_get_cookie();
if (!$data) { http_response_code(401); echo json_encode(['ok' => false, 'error' => 'no_session']); exit; }

$meta = new PDO('mysql:host=' . DB_HOST . ';dbname=ocre_meta;charset=utf8mb4', DB_USER, DB_PASS, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
]);
$st = $meta->prepare("SELECT id, email, prenom, nom, role FROM users WHERE id = ? LIMIT 1");
$st->execute([(int)($data['user_id'] ?? 0)]);
$u = $st->fetch();
if (!$u) { sso_clear_cookie(); http_response_code(401); echo json_encode(['ok' => false, 'error' => 'user_not_found']); exit; }

$ut = $meta->prepare("SELECT tenant_slug, role FROM user_tenants WHERE user_id = ?");
$ut->execute([(int)$u['id']]);
$tenants = $ut->fetchAll();

// Renew rolling.
$data['user_id'] = (int)$u['id'];
$data['email'] = $u['email'];
$data['tenants'] = array_column($tenants, 'tenant_slug');
sso_set_cookie($data);

header('Content-Type: application/json');
echo json_encode([
    'ok' => true,
    'user' => $u,
    'tenants' => $tenants,
    'current_tenant' => $data['current_tenant'] ?? ($tenants[0]['tenant_slug'] ?? null),
]);
