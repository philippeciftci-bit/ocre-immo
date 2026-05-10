<?php
// M_OCRE_PARCOURS_V4 — GET/POST /api/user/flags.php
// PWA install flags : pwa_installed (TINYINT) + pwa_install_refused_at (DATETIME)
// GET : retourne les flags. POST : update (body JSON {pwa_installed?, pwa_install_refused_at?})
require_once __DIR__ . '/../../lib/auth_db.php';
require_once __DIR__ . '/../../lib/jwt.php';
require_once __DIR__ . '/../../lib/user_modules.php';

auth_cors_allow();
um_ensure_schema(); // ensure pwa_* columns

$token = $_COOKIE['ocre_jwt'] ?? '';
if (!$token) auth_send_json(['ok'=>false,'error'=>'no_jwt'], 401);
$r = jwt_decode($token, true);
if (!$r['ok']) auth_send_json(['ok'=>false,'error'=>$r['error']], 401);
$userId = (int) $r['claims']['sub'];

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

if ($method === 'GET') {
    try {
        $st = auth_db()->prepare("SELECT pwa_installed, pwa_install_refused_at FROM auth_users WHERE id = ? LIMIT 1");
        $st->execute([$userId]);
        $row = $st->fetch(PDO::FETCH_ASSOC) ?: [];
        auth_send_json([
            'ok' => true,
            'pwa_installed' => (bool)($row['pwa_installed'] ?? false),
            'pwa_install_refused_at' => $row['pwa_install_refused_at'] ?? null,
        ]);
    } catch (Throwable $e) { auth_send_json(['ok'=>false,'error'=>'db'], 500); }
}

if ($method === 'POST') {
    $body = json_decode(file_get_contents('php://input'), true) ?: [];
    $updates = []; $args = [];
    if (isset($body['pwa_installed'])) {
        $updates[] = "pwa_installed = ?";
        $args[] = $body['pwa_installed'] ? 1 : 0;
    }
    if (array_key_exists('pwa_install_refused_at', $body)) {
        $updates[] = "pwa_install_refused_at = " . ($body['pwa_install_refused_at'] === null ? "NULL" : "NOW()");
    }
    if (!$updates) auth_send_json(['ok'=>false,'error'=>'no_flag'], 400);
    $args[] = $userId;
    try {
        auth_db()->prepare("UPDATE auth_users SET " . implode(', ', $updates) . " WHERE id = ?")->execute($args);
        auth_send_json(['ok' => true]);
    } catch (Throwable $e) { auth_send_json(['ok'=>false,'error'=>'db','detail'=>$e->getMessage()], 500); }
}

auth_send_json(['ok'=>false,'error'=>'method'], 405);
