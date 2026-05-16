<?php
// M/2026/05/16/4 — Token exchange cross-subdomain (pattern Auth0/Clerk).
// auth.ocre.immo genere un exchange_token one-time-use (TTL 60s, table
// ocre_meta.auth_exchange_tokens) puis redirige vers <slug>.ocre.immo/?st=<token>.
// Ce endpoint, appele EN PREMIER par le bootstrap du tenant, consomme le token
// et pose le cookie de session ocre_session en FIRST-PARTY sur <slug>.ocre.immo
// (PAS de Domain=.ocre.immo) -> survit a Safari Private Mode + ITP.

declare(strict_types=1);
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-cache, no-store, must-revalidate');
header('Pragma: no-cache');

require_once __DIR__ . '/../_session.php'; // pull db.php -> config.php + helpers session

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'METHOD_NOT_ALLOWED']);
    exit;
}

$body = json_decode(file_get_contents('php://input') ?: '{}', true);
if (!is_array($body)) $body = [];
$token = (string)($body['token'] ?? '');

if (!preg_match('/^[a-f0-9]{64}$/', $token)) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'INVALID_TOKEN_FORMAT']);
    exit;
}

// Slug courant depuis Host header (<slug>.ocre.immo).
$host = $_SERVER['HTTP_HOST'] ?? '';
$slug = '';
if (preg_match('/^([a-z0-9][a-z0-9-]*)\.ocre\.immo$/', $host, $m)) {
    $slug = $m[1];
}
if ($slug === '') {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'INVALID_HOST']);
    exit;
}

$ip = explode(',', $_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['REMOTE_ADDR'] ?? '?')[0];
$ua = $_SERVER['HTTP_USER_AGENT'] ?? '?';

// Lookup + consume atomique (FOR UPDATE).
$pdo = _session_pdo(); // ocre_meta
$pdo->beginTransaction();
try {
    $stmt = $pdo->prepare(
        "SELECT id, user_id FROM auth_exchange_tokens
         WHERE token = ? AND consumed_at IS NULL AND expires_at > NOW() AND slug = ?
         LIMIT 1 FOR UPDATE"
    );
    $stmt->execute([$token, $slug]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$row) {
        $pdo->rollBack();
        http_response_code(401);
        echo json_encode(['ok' => false, 'error' => 'TOKEN_INVALID_OR_EXPIRED']);
        exit;
    }

    $upd = $pdo->prepare(
        "UPDATE auth_exchange_tokens SET consumed_at = NOW(), ip = ?, user_agent = ? WHERE id = ?"
    );
    $upd->execute([substr($ip, 0, 45), substr($ua, 0, 255), $row['id']]);

    $pdo->commit();
} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    @error_log('[auth_exchange] DB error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'DB_ERROR']);
    exit;
}

// Session locale (table user_sessions, helper existant) + cookie FIRST-PARTY.
$userId = (int)$row['user_id'];
$sessionToken = createSession($userId, $ua, $ip);

// Cookie FIRST-PARTY : PAS de 'domain' => host-only sur <slug>.ocre.immo.
// Safari Private Mode + ITP ne bloquent JAMAIS un cookie first-party.
setcookie(OCRE_SESSION_COOKIE_NAME, $sessionToken, [
    'expires'  => time() + OCRE_SESSION_TTL_SECONDS,
    'path'     => '/',
    'secure'   => true,
    'httponly' => true,
    'samesite' => 'Lax',
]);

echo json_encode(['ok' => true, 'user_id' => $userId, 'slug' => $slug]);
