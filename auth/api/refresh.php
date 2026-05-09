<?php
// M97 — POST /api/refresh.php
// Lit cookie ocre_refresh, vérifie auth_sessions, génère nouveau JWT.

require_once __DIR__ . '/../lib/auth_db.php';
require_once __DIR__ . '/../lib/jwt.php';

auth_cors_allow();

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    auth_send_json(['ok' => false, 'error' => 'method'], 405);
}

$refresh = $_COOKIE['ocre_refresh'] ?? '';
if (!preg_match('/^[a-f0-9]{64}$/', $refresh)) {
    auth_send_json(['ok' => false, 'error' => 'no_refresh'], 401);
}

$db = auth_db();
$st = $db->prepare(
    "SELECT id, user_id FROM auth_sessions
     WHERE refresh_token = ? AND expires_at > NOW() AND revoked_at IS NULL
     LIMIT 1"
);
$st->execute([$refresh]);
$sess = $st->fetch();
if (!$sess) {
    auth_send_json(['ok' => false, 'error' => 'invalid_refresh'], 401);
}

$jwt = jwt_encode((int) $sess['user_id']);
$db->prepare("UPDATE auth_sessions SET jti = ? WHERE id = ?")
   ->execute([$jwt['jti'], $sess['id']]);

auth_set_cookies($jwt['token'], $refresh);
auth_send_json(['ok' => true, 'exp' => $jwt['exp']]);
