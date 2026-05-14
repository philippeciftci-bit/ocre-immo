<?php
// M/2026/05/14/10 — Endpoint GET /confirm sur auth.ocre.immo.
// Recupere ?token=, valide via lib partagee, pose cookie session, repond HTTP 302 direct.
// AUCUN HTML rendu : pure PHP -> redirect navigateur transparente.

declare(strict_types=1);

require_once '/opt/ocre-app/api/auth/_confirm_lib.php';

$token = isset($_GET['token']) ? (string)$_GET['token'] : '';
$ip = explode(',', $_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['REMOTE_ADDR'] ?? '?')[0];
$ua = $_SERVER['HTTP_USER_AGENT'] ?? '?';

if ($token === '') {
    header('Location: /?step=email&error=missing_token', true, 302);
    exit;
}

$result = confirm_user_by_token($token);

if (!$result['ok']) {
    $err = (string)($result['error'] ?? 'token_invalid');
    // Map "workspace_not_ready" -> message specifique avec retry.
    $qs = 'step=email&error=' . urlencode($err);
    header('Location: /?' . $qs, true, 302);
    exit;
}

$uid = (int)$result['uid'];
$slug = (string)$result['slug'];

$sessToken = createSession($uid, $ua, $ip);
setSessionCookie($sessToken);

$redirect = $slug !== '' ? "https://{$slug}.ocre.immo/" : '/';
header('Location: ' . $redirect, true, 302);
exit;
