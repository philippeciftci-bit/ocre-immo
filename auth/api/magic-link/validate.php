<?php
// M97 — GET /api/magic-link/validate.php?token=XXX
// Vérifie + crée JWT + pose cookies cross-subdomain + redirect app.ocre.immo.

require_once __DIR__ . '/../../lib/auth_db.php';
require_once __DIR__ . '/../../lib/jwt.php';

auth_ensure_schema();

$token = $_GET['token'] ?? '';
if (!preg_match('/^[a-f0-9]{64}$/', $token)) {
    header('Location: /error.html?reason=token_invalid');
    exit;
}

$db = auth_db();
$st = $db->prepare(
    "SELECT id, user_id FROM auth_magic_tokens
     WHERE token = ? AND expires_at > NOW() AND used_at IS NULL
     LIMIT 1"
);
$st->execute([$token]);
$row = $st->fetch();
if (!$row) {
    header('Location: /error.html?reason=token_invalid');
    exit;
}

$userId = (int) $row['user_id'];

$db->beginTransaction();
try {
    $up = $db->prepare("UPDATE auth_magic_tokens SET used_at = NOW() WHERE id = ? AND used_at IS NULL");
    $up->execute([$row['id']]);
    if ($up->rowCount() !== 1) {
        $db->rollBack();
        header('Location: /error.html?reason=token_invalid');
        exit;
    }

    $jwt = jwt_encode($userId);
    $refresh = bin2hex(random_bytes(32));
    $ua = substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 256);
    $ip = auth_client_ip();

    $ins = $db->prepare(
        "INSERT INTO auth_sessions (user_id, jti, refresh_token, expires_at, user_agent, ip)
         VALUES (?, ?, ?, DATE_ADD(NOW(), INTERVAL 30 DAY), ?, ?)"
    );
    $ins->execute([$userId, $jwt['jti'], $refresh, $ua, $ip]);

    $db->prepare("UPDATE auth_users SET last_login_at = NOW() WHERE id = ?")->execute([$userId]);

    $db->commit();
} catch (Exception $e) {
    $db->rollBack();
    error_log('magic_validate: ' . $e->getMessage());
    header('Location: /error.html?reason=server');
    exit;
}

auth_set_cookies($jwt['token'], $refresh);
header('Location: https://app.ocre.immo/');
exit;
