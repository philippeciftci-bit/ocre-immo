<?php
// M/2026/05/13/18 — SSO logout : purge cookie + revoke session DB.
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/sso_lib.php';
setCorsHeaders();
$data = sso_get_cookie();
if ($data && !empty($data['session_token'])) {
    try {
        $meta = new PDO('mysql:host=' . DB_HOST . ';dbname=ocre_meta;charset=utf8mb4', DB_USER, DB_PASS, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $meta->prepare("UPDATE sso_sessions SET revoked_at = NOW() WHERE session_token = ?")->execute([$data['session_token']]);
    } catch (Throwable $e) {}
}
sso_clear_cookie();
header('Content-Type: application/json');
echo json_encode(['ok' => true]);
