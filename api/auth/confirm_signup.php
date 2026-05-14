<?php
// M/2026/05/15/3 — confirm signup public (user a deja saisi son MDP via popup signup, MDP hashe en DB).
// POST { token } -> valide activation_token + expires + set status='active' + clear token + cookie 30j + redirect URL.
// NE PAS confondre avec setup-password.php (invitation admin user n'a pas encore MDP).

declare(strict_types=1);
header('Content-Type: application/json');

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../_session.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'method']);
    exit;
}

$input = json_decode(file_get_contents('php://input') ?: '{}', true);
$token = trim((string)($input['token'] ?? ''));
$ip = explode(',', $_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['REMOTE_ADDR'] ?? '?')[0];
$ua = $_SERVER['HTTP_USER_AGENT'] ?? '?';

if ($token === '') {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Token manquant']);
    exit;
}

$pdo = new PDO('mysql:host=' . DB_HOST . ';dbname=ocre_meta;charset=utf8mb4', DB_USER, DB_PASS, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);

// Valide token (stocke en clair selon schema existant M/14/75 signup.php)
$st = $pdo->prepare("SELECT id, email, slug, status FROM users WHERE activation_token = ? AND archived_at IS NULL AND activation_token_expires_at > NOW() LIMIT 1");
$st->execute([$token]);
$user = $st->fetch(PDO::FETCH_ASSOC);

if (!$user) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Lien invalide ou expire. Connecte-toi sur ton workspace.']);
    exit;
}

// Activate + consomme token (idempotent : meme si deja active, retourne ok pour eviter friction)
$pdo->prepare("UPDATE users SET status='active', activation_token=NULL, activation_token_expires_at=NULL, last_login=NOW(), failed_login_count=0, locked_until=NULL WHERE id=?")
    ->execute([$user['id']]);

// Cookie session 30j (reuse _session.php M/14/66)
$sessToken = createSession((int)$user['id'], $ua, $ip);
setSessionCookie($sessToken);

$slug = (string)($user['slug'] ?? '');
$redirect = $slug !== '' ? "https://{$slug}.ocre.immo/" : '/';

echo json_encode([
    'ok' => true,
    'redirect' => $redirect,
    'session_token' => $sessToken,
]);
