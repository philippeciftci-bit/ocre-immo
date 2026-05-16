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

// M/2026/05/16/4 — Token exchange cross-subdomain : 302 vers
// <slug>.ocre.immo/?st=<exchange_token> one-time-use (TTL 60s). Le cookie de
// session definitif est pose FIRST-PARTY par exchange.php cote tenant
// (survit Safari Private Mode + ITP). cf. /opt/ocre-app/api/auth/exchange.php.
if ($slug !== '') {
    $exToken = bin2hex(random_bytes(32));
    _session_pdo()->prepare(
        "INSERT INTO auth_exchange_tokens (token, user_id, slug, expires_at)
         VALUES (?, ?, ?, DATE_ADD(NOW(), INTERVAL 60 SECOND))"
    )->execute([$exToken, $uid, $slug]);
    header('Location: ' . "https://{$slug}.ocre.immo/?st={$exToken}", true, 302);
} else {
    header('Location: /', true, 302);
}
exit;
