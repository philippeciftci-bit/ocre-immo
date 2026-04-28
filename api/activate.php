<?php
// M/2026/04/29/1 — Activation compte : valide token + définit mot de passe + auto-login.
require_once __DIR__ . '/db.php';
header('Content-Type: application/json; charset=utf-8');

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$body = json_decode(file_get_contents('php://input'), true) ?: $_POST;

$meta = new PDO('mysql:host=' . DB_HOST . ';dbname=ocre_meta;charset=utf8mb4', DB_USER, DB_PASS, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
]);

$action = $_GET['action'] ?? ($body['action'] ?? 'check');
$token = $_GET['activation_token'] ?? ($body['activation_token'] ?? '');

if (!$token || !preg_match('/^[a-f0-9]{64}$/', $token)) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'activation_token invalide']);
    exit;
}

$st = $meta->prepare("SELECT id, email, display_name, prenom, slug, status, activation_token_expires_at FROM users WHERE activation_token = ? LIMIT 1");
$st->execute([$token]);
$user = $st->fetch();
if (!$user) { http_response_code(404); echo json_encode(['ok' => false, 'error' => 'token introuvable']); exit; }
if ($user['status'] !== 'pending_activation') { http_response_code(409); echo json_encode(['ok' => false, 'error' => 'compte déjà actif']); exit; }
if (strtotime($user['activation_token_expires_at']) < time()) { http_response_code(410); echo json_encode(['ok' => false, 'error' => 'token expiré']); exit; }

if ($action === 'check') {
    echo json_encode([
        'ok' => true,
        'email' => $user['email'],
        'display_name' => $user['display_name'],
        'slug' => $user['slug'],
    ]);
    exit;
}

if ($action === 'set_password' && $method === 'POST') {
    $password = $body['password'] ?? '';
    if (strlen($password) < 10 || !preg_match('/[A-Za-z]/', $password) || !preg_match('/\d/', $password)) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'mot de passe : min 10 chars, lettres + chiffres']);
        exit;
    }
    $hash = password_hash($password, PASSWORD_BCRYPT);
    $meta->prepare("UPDATE users SET password_hash = ?, status = 'active', activation_token = NULL, activation_token_expires_at = NULL WHERE id = ?")
        ->execute([$hash, $user['id']]);

    // Création session token long-lived (24h).
    $sessionToken = bin2hex(random_bytes(32));
    try {
        $meta->prepare("INSERT INTO sessions (token, user_id, expires_at, created_at) VALUES (?, ?, DATE_ADD(NOW(), INTERVAL 24 HOUR), NOW())")
            ->execute([$sessionToken, $user['id']]);
    } catch (Throwable $e) {}

    echo json_encode([
        'ok' => true,
        'session_token' => $sessionToken,
        'redirect' => 'https://' . $user['slug'] . '.ocre.immo/',
    ]);
    exit;
}

http_response_code(400);
echo json_encode(['ok' => false, 'error' => 'action invalide (check | set_password)']);
