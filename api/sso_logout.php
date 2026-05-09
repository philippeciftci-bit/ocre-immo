<?php
// M99 — POST /api/sso_logout.php
// Logout SSO depuis Oi Agent : revoke jti dans auth_sessions + clear cookies SSO + redirect auth.
// Garde aussi la session legacy intacte (l'user peut rester connecte legacy s'il le souhaite).

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/sso_bridge.php';

setCorsHeaders();

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'method']);
    exit;
}

$token = $_COOKIE['ocre_jwt'] ?? '';
$claims = $token ? _sso_decode_jwt($token) : null;

if ($claims && isset($claims['jti'])) {
    try {
        _sso_meta_pdo()->prepare(
            "UPDATE auth_sessions SET revoked_at = NOW() WHERE jti = ? AND revoked_at IS NULL"
        )->execute([$claims['jti']]);
    } catch (Throwable $e) {
        @error_log('[sso_logout] revoke err: ' . $e->getMessage());
    }
}

// Clear cookies SSO (ocre_jwt + ocre_refresh) Domain=.ocre.immo
foreach (['ocre_jwt', 'ocre_refresh'] as $name) {
    setcookie($name, '', [
        'expires' => 1, 'path' => '/', 'domain' => '.ocre.immo',
        'secure' => true, 'httponly' => true, 'samesite' => 'Lax',
    ]);
}

http_response_code(200);
echo json_encode(['ok' => true, 'redirect' => 'https://auth.ocre.immo/']);
