<?php
// M/2026/05/13/18 — SSO switch tenant : modifie current_tenant dans cookie signe.
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/sso_lib.php';
setCorsHeaders();
$data = sso_get_cookie();
if (!$data) { http_response_code(401); echo json_encode(['ok' => false, 'error' => 'no_session']); exit; }
$input = json_decode(file_get_contents('php://input'), true) ?: [];
$slug = (string)($input['tenant_slug'] ?? '');
if (!preg_match('/^[a-z0-9-]{1,64}$/i', $slug)) { http_response_code(400); echo json_encode(['ok' => false, 'error' => 'invalid_slug']); exit; }
$tenants = isset($data['tenants']) && is_array($data['tenants']) ? $data['tenants'] : [];
if (!in_array($slug, $tenants, true)) { http_response_code(403); echo json_encode(['ok' => false, 'error' => 'tenant_not_accessible']); exit; }
$data['current_tenant'] = $slug;
sso_set_cookie($data);
header('Content-Type: application/json');
echo json_encode(['ok' => true, 'current_tenant' => $slug]);
