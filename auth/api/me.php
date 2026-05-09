<?php
// M97 / M98 — GET /api/me.php → claims + user.
// POST /api/me.php → update first_name/last_name. CORS cross-subdomain (M98).

require_once __DIR__ . '/../lib/auth_db.php';
require_once __DIR__ . '/../lib/jwt.php';

auth_cors_allow();
auth_ensure_schema();

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

$userId = (int) $r['claims']['sub'];

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $raw = file_get_contents('php://input');
    $data = json_decode($raw, true);
    if (!is_array($data)) $data = $_POST;
    $first = isset($data['first_name']) ? trim((string) $data['first_name']) : null;
    $last = isset($data['last_name']) ? trim((string) $data['last_name']) : null;
    if ($first !== null && mb_strlen($first) > 64) auth_send_json(['ok' => false, 'error' => 'first_name_too_long'], 400);
    if ($last !== null && mb_strlen($last) > 64) auth_send_json(['ok' => false, 'error' => 'last_name_too_long'], 400);
    $sets = [];
    $args = [];
    if ($first !== null) { $sets[] = 'first_name = ?'; $args[] = $first === '' ? null : $first; }
    if ($last !== null) { $sets[] = 'last_name = ?'; $args[] = $last === '' ? null : $last; }
    if ($sets) {
        $args[] = $userId;
        $up = auth_db()->prepare("UPDATE auth_users SET " . implode(', ', $sets) . " WHERE id = ?");
        $up->execute($args);
    }
}

$st2 = auth_db()->prepare("SELECT id, email, first_name, last_name, status FROM auth_users WHERE id = ? LIMIT 1");
$st2->execute([$userId]);
$user = $st2->fetch();
if (!$user || $user['status'] !== 'active') {
    auth_send_json(['ok' => false, 'error' => 'user_inactive'], 401);
}

auth_send_json([
    'ok' => true,
    'user' => [
        'id' => (int) $user['id'],
        'email' => $user['email'],
        'first_name' => $user['first_name'],
        'last_name' => $user['last_name'],
    ],
    'claims' => $r['claims'],
]);
