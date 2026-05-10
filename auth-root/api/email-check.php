<?php
// M_OCRE_PARCOURS_V4 — POST /api/email-check.php
// Body : {email, app}
// Si email existant : pose JWT cookie 1 an + retourne {existing:true, redirect_url, has_module}
// Si absent : retourne {existing:false}
require_once __DIR__ . '/../lib/auth_db.php';
require_once __DIR__ . '/../lib/jwt.php';
require_once __DIR__ . '/../lib/user_modules.php';

// CORS pour ocre.immo (popup signup) + auth.ocre.immo
$origin = $_SERVER['HTTP_ORIGIN'] ?? '';
$allowed = ['https://ocre.immo', 'https://www.ocre.immo', 'https://auth.ocre.immo'];
if (in_array($origin, $allowed, true)) {
    header('Access-Control-Allow-Origin: ' . $origin);
    header('Access-Control-Allow-Credentials: true');
    header('Access-Control-Allow-Methods: POST, OPTIONS');
    header('Access-Control-Allow-Headers: Content-Type');
}
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') { http_response_code(204); exit; }
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') auth_send_json(['ok'=>false,'error'=>'method'], 405);

auth_ensure_schema();
um_ensure_schema();

$ip = auth_client_ip();
if (!auth_rate_limit_check($ip, 'email_check', 30, 3600)) auth_send_json(['ok'=>false,'error'=>'rate_limit'], 429);
auth_rate_limit_record($ip, 'email_check');

$body = json_decode(file_get_contents('php://input'), true) ?: [];
$email = strtolower(trim((string)($body['email'] ?? '')));
$app = preg_replace('/[^a-z]/', '', strtolower((string)($body['app'] ?? 'agent')));
if (!filter_var($email, FILTER_VALIDATE_EMAIL)) auth_send_json(['ok'=>false,'error'=>'email_invalid'], 400);

$st = auth_db()->prepare('SELECT id, email, first_name, last_name FROM auth_users WHERE email = ? LIMIT 1');
$st->execute([$email]);
$user = $st->fetch(PDO::FETCH_ASSOC);

if (!$user) {
    auth_send_json(['ok' => true, 'existing' => false, 'email' => $email]);
}

// User existe : phase actuelle = entrée directe sans magic link (V4 indéfini)
$userId = (int) $user['id'];
um_activate($userId, $app); // active le module emprunté

// JWT 1 an (quasi indéfini cette phase)
$jwt = jwt_encode($userId, 365 * 86400);
$refresh = bin2hex(random_bytes(32));
try {
    auth_db()->prepare("INSERT INTO auth_refresh_tokens (user_id, token, expires_at) VALUES (?, ?, DATE_ADD(NOW(), INTERVAL 1 YEAR))")->execute([$userId, hash('sha256', $refresh)]);
} catch (Throwable $e) { /* swallow */ }

// Cookies cross-subdomain via auth_set_cookies (livré M_OCRE_AGENT_SIGNUP_V1 30j → bumper à 1an pour V4)
$opts = ['expires' => time() + 365 * 86400, 'path' => '/', 'domain' => '.ocre.immo', 'secure' => true, 'httponly' => true, 'samesite' => 'Lax'];
setcookie('ocre_jwt', $jwt['token'], $opts);
setcookie('ocre_refresh', $refresh, $opts);

$redirectUrl = "https://app.ocre.immo/oi-{$app}";
auth_send_json([
    'ok' => true,
    'existing' => true,
    'redirect_url' => $redirectUrl,
    'has_module' => um_has($userId, $app),
    'first_name' => $user['first_name'],
]);
