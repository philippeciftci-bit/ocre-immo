<?php
// M97 — POST /api/logout.php
// Décode JWT (sig only, pas exp) pour extraire jti, revoke session, clear cookies.

require_once __DIR__ . '/../lib/auth_db.php';
require_once __DIR__ . '/../lib/jwt.php';

auth_cors_allow();

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    auth_send_json(['ok' => false, 'error' => 'method'], 405);
}

$token = $_COOKIE['ocre_jwt'] ?? '';
if ($token) {
    $r = jwt_decode($token, false);
    if ($r['ok'] && isset($r['claims']['jti'])) {
        try {
            auth_db()->prepare("UPDATE auth_sessions SET revoked_at = NOW() WHERE jti = ? AND revoked_at IS NULL")
                     ->execute([$r['claims']['jti']]);
        } catch (Exception $e) {
            error_log('logout: ' . $e->getMessage());
        }
    }
}

$refresh = $_COOKIE['ocre_refresh'] ?? '';
if (preg_match('/^[a-f0-9]{64}$/', $refresh)) {
    try {
        auth_db()->prepare("UPDATE auth_sessions SET revoked_at = NOW() WHERE refresh_token = ? AND revoked_at IS NULL")
                 ->execute([$refresh]);
    } catch (Exception $e) {}
}

auth_clear_cookies();
auth_send_json(['ok' => true]);
