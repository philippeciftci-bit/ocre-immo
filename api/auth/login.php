<?php
// M/2026/05/14/71 — Phase B AUTH-PERENNE : login email + password (Argon2id verify).
// POST { email, password } -> cookie session 30j (createSession M/14/66) si OK.
// Rate-limit : 5/min/email + 20/h/IP. Locked_until apres 5 echecs/heure user.

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
$email = strtolower(trim((string)($input['email'] ?? '')));
$password = (string)($input['password'] ?? '');
$ip = explode(',', $_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['REMOTE_ADDR'] ?? '?')[0];
$ua = $_SERVER['HTTP_USER_AGENT'] ?? '?';

if (!filter_var($email, FILTER_VALIDATE_EMAIL) || $password === '') {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Email ou mot de passe manquant']);
    exit;
}

$pdo = new PDO('mysql:host=' . DB_HOST . ';dbname=ocre_meta;charset=utf8mb4', DB_USER, DB_PASS, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);

// Rate-limit checks
$rateErr = password_auth_rate_check_login($pdo, $email, $ip);
if ($rateErr !== null) {
    http_response_code(429);
    echo json_encode(['ok' => false, 'error' => $rateErr]);
    exit;
}

// Lookup user
$st = $pdo->prepare("SELECT id, email, password_hash, locked_until, failed_login_count, role, archived_at FROM users WHERE email = ? LIMIT 1");
$st->execute([$email]);
$user = $st->fetch(PDO::FETCH_ASSOC);

// Branche unique echec pour reduire fuite timing oracle
$ok = false;
if ($user && empty($user['archived_at']) && !empty($user['password_hash'])) {
    if (!empty($user['locked_until']) && strtotime($user['locked_until']) > time()) {
        $ok = false; // locked
    } else if (password_auth_verify($password, (string)$user['password_hash'])) {
        $ok = true;
    }
} else {
    // Dummy verify pour minimiser timing oracle (delay similaire si email inconnu).
    password_auth_verify($password, '$argon2id$v=19$m=65536,t=4,p=2$dummysaltdummysalt$dummyhashdummyhash');
}

password_auth_rate_log($pdo, 'login', $email, $ip, $ok, $ua);

if (!$ok) {
    // Increment failed_login_count + lock si seuil
    if ($user) {
        $pdo->prepare("UPDATE users SET failed_login_count = failed_login_count + 1,
            locked_until = CASE WHEN failed_login_count + 1 >= 10 THEN DATE_ADD(NOW(), INTERVAL 1 HOUR) ELSE locked_until END
            WHERE id = ?")->execute([$user['id']]);
    }
    usleep(500000); // 500ms anti-brute timing-equalisation
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'Email ou mot de passe invalide']);
    exit;
}

// Succes : reset failed_login_count + clear locked_until + last_login
$pdo->prepare("UPDATE users SET failed_login_count = 0, locked_until = NULL, last_login = NOW() WHERE id = ?")
    ->execute([$user['id']]);

// Rehash si params Argon2id ont change
if (password_auth_needs_rehash((string)$user['password_hash'])) {
    $newHash = password_auth_hash($password);
    $pdo->prepare("UPDATE users SET password_hash = ?, password_set_at = NOW() WHERE id = ?")->execute([$newHash, $user['id']]);
}

$token = createSession((int)$user['id'], $ua, $ip);
setSessionCookie($token);

echo json_encode([
    'ok' => true,
    'redirect' => '/',
    'session_token' => $token, // bridge legacy X-Session-Token compat helper api()
]);
