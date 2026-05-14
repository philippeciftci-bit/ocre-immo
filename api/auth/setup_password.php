<?php
// M/2026/05/14/71 — Phase B AUTH-PERENNE : setup password apres magic-link invitation.
// POST { token, password, confirmation } -> valide token + hash + INSERT password + cookie 30j.
// Le magic-link invitation (24h) reste valide jusqu'au setup. Apres setup, token consomme.

declare(strict_types=1);
header('Content-Type: application/json');

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../_session.php';
require_once __DIR__ . '/../lib/password_auth.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'method']);
    exit;
}

$input = json_decode(file_get_contents('php://input') ?: '{}', true);
$token = trim((string)($input['token'] ?? ''));
$password = (string)($input['password'] ?? '');
$confirmation = (string)($input['confirmation'] ?? '');
$ip = explode(',', $_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['REMOTE_ADDR'] ?? '?')[0];
$ua = $_SERVER['HTTP_USER_AGENT'] ?? '?';

if ($token === '' || $password === '') {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Champs requis manquants']);
    exit;
}
if ($password !== $confirmation) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Les mots de passe ne correspondent pas']);
    exit;
}
$strengthErr = password_auth_validate_strength($password);
if ($strengthErr !== null) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => $strengthErr]);
    exit;
}

$pdo = new PDO('mysql:host=' . DB_HOST . ';dbname=ocre_meta;charset=utf8mb4', DB_USER, DB_PASS, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);

// Valider token activation (table users.activation_token, expires_at) OU magic-link (table magic_links)
// Pattern utilise activation_token (col existante) pour invitation initiale.
$tokenHash = $token; // activation_token est stocke en clair selon le schema existant (compat M/14/62 signup.php)
$st = $pdo->prepare("SELECT id, email FROM users WHERE activation_token = ? AND archived_at IS NULL AND activation_token_expires_at > NOW() LIMIT 1");
$st->execute([$tokenHash]);
$user = $st->fetch(PDO::FETCH_ASSOC);

if (!$user) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Lien invalide ou expire. Demande un nouveau lien.']);
    exit;
}

// Hash + UPDATE + clear activation_token (consomme)
$hash = password_auth_hash($password);
$pdo->prepare("UPDATE users SET password_hash = ?, password_set_at = NOW(),
    activation_token = NULL, activation_token_expires_at = NULL,
    status = 'active', failed_login_count = 0, locked_until = NULL,
    must_change_password = 0, last_login = NOW()
    WHERE id = ?")->execute([$hash, $user['id']]);

// Cookie session 30j
$token = createSession((int)$user['id'], $ua, $ip);
setSessionCookie($token);

password_auth_rate_log($pdo, 'setup_password', (string)$user['email'], $ip, true, $ua);

echo json_encode([
    'ok' => true,
    'redirect' => '/',
    'session_token' => $token,
]);
