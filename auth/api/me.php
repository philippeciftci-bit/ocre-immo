<?php
// M97 — GET /api/me.php
// Retourne user_id + claims du JWT actuel (validation complète).

require_once __DIR__ . '/../lib/auth_db.php';
require_once __DIR__ . '/../lib/jwt.php';

$token = $_COOKIE['ocre_jwt'] ?? '';
if (!$token) {
    auth_send_json(['ok' => false, 'error' => 'no_jwt'], 401);
}

$r = jwt_decode($token, true);
if (!$r['ok']) {
    auth_send_json(['ok' => false, 'error' => $r['error']], 401);
}

$jti = $r['claims']['jti'];
$st = auth_db()->prepare(
    "SELECT 1 FROM auth_sessions WHERE jti = ? AND revoked_at IS NULL LIMIT 1"
);
$st->execute([$jti]);
if (!$st->fetch()) {
    auth_send_json(['ok' => false, 'error' => 'session_revoked'], 401);
}

$st2 = auth_db()->prepare("SELECT id, email, status FROM auth_users WHERE id = ? LIMIT 1");
$st2->execute([$r['claims']['sub']]);
$user = $st2->fetch();
if (!$user || $user['status'] !== 'active') {
    auth_send_json(['ok' => false, 'error' => 'user_inactive'], 401);
}

auth_send_json([
    'ok' => true,
    'user' => ['id' => (int) $user['id'], 'email' => $user['email']],
    'claims' => $r['claims'],
]);
