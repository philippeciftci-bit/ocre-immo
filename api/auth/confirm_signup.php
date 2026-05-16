<?php
// M/2026/05/14/10 — JSON API endpoint (compat backward).
// La logique metier vit dans _confirm_lib.php (mutualisee avec /opt/ocre-auth/confirm_redirect.php).

declare(strict_types=1);
header('Content-Type: application/json');

require_once __DIR__ . '/_confirm_lib.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'method']);
    exit;
}

$input = json_decode(file_get_contents('php://input') ?: '{}', true);
$token = trim((string)($input['token'] ?? ''));
$ip = explode(',', $_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['REMOTE_ADDR'] ?? '?')[0];
$ua = $_SERVER['HTTP_USER_AGENT'] ?? '?';

$result = confirm_user_by_token($token);

if (!$result['ok']) {
    http_response_code((int)($result['http_code'] ?? 400));
    $payload = ['ok' => false, 'error' => (string)$result['error'], 'message' => (string)($result['message'] ?? '')];
    if ($result['error'] === 'workspace_not_ready') { $payload['code'] = 'WSP_INIT_42'; }
    echo json_encode($payload);
    exit;
}

$uid = (int)$result['uid'];
$slug = (string)$result['slug'];

$sessToken = createSession($uid, $ua, $ip);
setSessionCookie($sessToken);

// M/2026/05/16/4 — Token exchange cross-subdomain : redirect tenant avec
// ?st=<exchange_token> one-time-use (TTL 60s). Cookie session definitif pose
// FIRST-PARTY par <slug>.ocre.immo/api/auth/exchange.php (cf. exchange.php).
$redirect = '/';
if ($slug !== '') {
    $exToken = bin2hex(random_bytes(32));
    _session_pdo()->prepare(
        "INSERT INTO auth_exchange_tokens (token, user_id, slug, expires_at)
         VALUES (?, ?, ?, DATE_ADD(NOW(), INTERVAL 60 SECOND))"
    )->execute([$exToken, $uid, $slug]);
    $redirect = "https://{$slug}.ocre.immo/?st={$exToken}";
}
echo json_encode([
    'ok' => true,
    'redirect' => $redirect,
    'session_token' => $sessToken,
]);
