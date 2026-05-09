<?php
// M/2026/05/09/75 — Endpoint activation magic link super-admin.
// POST JSON {token} → valide token + role=super_admin → cree session via _session.php (M71) +
// pose cookie ocre_session 30j Domain=.ocre.immo. Marque token consomme.
// Retour : 200 {ok, redirect, user}.

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/_session.php';
setCorsHeaders();

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'METHOD_NOT_ALLOWED']);
    exit;
}

$input = getInput();
$token = trim((string)($input['token'] ?? ''));

if ($token === '' || !preg_match('/^[a-f0-9]{32,128}$/', $token)) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'TOKEN_INVALID']);
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

$st = $pdo->prepare("SELECT id, email, prenom, nom, role, activation_token_expires_at FROM users WHERE activation_token = ? AND archived_at IS NULL LIMIT 1");
$st->execute([$token]);
$user = $st->fetch();

if (!$user) {
    http_response_code(404);
    echo json_encode(['ok' => false, 'error' => 'TOKEN_NOT_FOUND']);
    exit;
}
if ($user['role'] !== 'super_admin') {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'NOT_SUPER_ADMIN']);
    exit;
}
$expiresAt = (string)($user['activation_token_expires_at'] ?? '');
if ($expiresAt && strtotime($expiresAt) < time()) {
    http_response_code(410);
    echo json_encode(['ok' => false, 'error' => 'TOKEN_EXPIRED']);
    exit;
}

$userId = (int)$user['id'];
try {
    // Consomme le token (anti-replay).
    $upd = $pdo->prepare("UPDATE users SET activation_token = NULL, activation_token_expires_at = NULL, last_login = NOW() WHERE id = ?");
    $upd->execute([$userId]);
} catch (Throwable $e) {
    @error_log('[superadmin_activate] consume_token_failed err=' . $e->getMessage());
}

// Cree session via _session.php (cookie ocre_session HttpOnly Secure SameSite=Lax Domain=.ocre.immo, sliding 30j).
try {
    $cookieToken = createSession($userId, $_SERVER['HTTP_USER_AGENT'] ?? '', $_SERVER['REMOTE_ADDR'] ?? '');
    setSessionCookie($cookieToken);
} catch (Throwable $e) {
    @error_log('[superadmin_activate] cookie_session_failed user_id=' . $userId . ' err=' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'SERVER_ERROR', 'detail' => 'session']);
    exit;
}

http_response_code(200);
echo json_encode([
    'ok' => true,
    'user' => [
        'id' => $userId,
        'email' => $user['email'],
        'prenom' => $user['prenom'],
        'nom' => $user['nom'],
        'role' => $user['role'],
    ],
    'redirect' => '/',
]);
