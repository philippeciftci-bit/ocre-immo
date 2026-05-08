<?php
// M/2026/05/08/28 — Endpoint set-password post-activation.
// POST JSON {token, password}
//
// Sequence :
//   1. Valider token (pending_activation, non expire)
//   2. Hash password (BCRYPT cost 12, cohere agents_register)
//   3. UPDATE users : password_hash, status='active', activation_token=NULL, activation_token_expires_at=NULL
//   4. Creer session (table sessions, token aleatoire 32 bytes -> 64 hex)
//   5. Retourner {ok:true, token, redirect}
//
// Reponses :
//   200 {ok:true, token, redirect}
//   400 {ok:false, error: TOKEN_INVALID|WEAK_PASSWORD}
//   404 {ok:false, error: TOKEN_NOT_FOUND}
//   410 {ok:false, error: TOKEN_EXPIRED}
//   500 {ok:false, error: SERVER_ERROR, detail:...}

require_once __DIR__ . '/db.php';
setCorsHeaders();

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'METHOD_NOT_ALLOWED']);
    exit;
}

$input = getInput();
$token = trim((string)($input['token'] ?? ''));
$pwd = (string)($input['password'] ?? '');

if ($token === '' || !preg_match('/^[a-f0-9]{32,128}$/', $token)) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'TOKEN_INVALID']);
    exit;
}

if (strlen($pwd) < 8 || !preg_match('/[A-Z]/', $pwd) || !preg_match('/[0-9]/', $pwd)) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'WEAK_PASSWORD']);
    exit;
}

try {
    $pdo = new PDO(
        'mysql:host=' . DB_HOST . ';dbname=ocre_meta;charset=utf8mb4',
        DB_USER, DB_PASS,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
    );
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'SERVER_ERROR', 'detail' => 'db connect']);
    exit;
}

$st = $pdo->prepare("SELECT id, email, prenom, slug, status, activation_token_expires_at FROM users WHERE activation_token = ? AND archived_at IS NULL LIMIT 1");
$st->execute([$token]);
$user = $st->fetch();

if (!$user) {
    http_response_code(404);
    echo json_encode(['ok' => false, 'error' => 'TOKEN_NOT_FOUND']);
    exit;
}

$expiresAt = (string)($user['activation_token_expires_at'] ?? '');
if ($expiresAt && strtotime($expiresAt) < time()) {
    http_response_code(410);
    echo json_encode(['ok' => false, 'error' => 'TOKEN_EXPIRED']);
    exit;
}

$hash = password_hash($pwd, PASSWORD_BCRYPT, ['cost' => 12]);

try {
    $upd = $pdo->prepare("UPDATE users SET password_hash = ?, status = 'active', activation_token = NULL, activation_token_expires_at = NULL, last_login = NOW() WHERE id = ?");
    $upd->execute([$hash, (int)$user['id']]);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'SERVER_ERROR', 'detail' => 'update user']);
    exit;
}

// Cree session (auto-login)
$sessionToken = bin2hex(random_bytes(32));
try {
    $ins = $pdo->prepare(
        "INSERT INTO sessions (token, user_id, expires_at, ip, user_agent)
         VALUES (?, ?, DATE_ADD(NOW(), INTERVAL 30 DAY), ?, ?)"
    );
    $ins->execute([
        $sessionToken,
        (int)$user['id'],
        $_SERVER['REMOTE_ADDR'] ?? null,
        substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 255),
    ]);
} catch (Throwable $e) {
    // Session insertion echouee : le user est active mais devra se reconnecter manuellement.
    @error_log('[agents_set_password] session_insert_failed user_id=' . $user['id'] . ' err=' . $e->getMessage());
    http_response_code(200);
    echo json_encode([
        'ok' => true,
        'token' => null,
        'redirect' => '/login/?activated=1',
        'note' => 'Session creation failed, redirect to login',
    ]);
    exit;
}

http_response_code(200);
echo json_encode([
    'ok' => true,
    'token' => $sessionToken,
    'user' => [
        'id' => (int)$user['id'],
        'email' => $user['email'],
        'prenom' => $user['prenom'],
        'slug' => $user['slug'],
    ],
    'redirect' => '/',
]);
