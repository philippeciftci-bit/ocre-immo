<?php
// M/2026/05/07/96 — Reset password super_admin (confirm).
// POST {token, password} -> 200 ok / 400 token invalide / 410 expire / 422 password trop faible.
require_once __DIR__ . '/db.php';
header('Content-Type: application/json; charset=utf-8');

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'method_not_allowed']);
    exit;
}

$body = json_decode(file_get_contents('php://input'), true) ?: [];
$token = (string)($body['token'] ?? '');
$pwd = (string)($body['password'] ?? '');

if (!preg_match('/^[a-f0-9]{64}$/', $token)) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'TOKEN_INVALID']);
    exit;
}
if (strlen($pwd) < 10 || !preg_match('/[A-Z]/', $pwd) || !preg_match('/[0-9]/', $pwd)) {
    http_response_code(422);
    echo json_encode(['ok' => false, 'error' => 'Mot de passe trop faible (min 10 chars, 1 majuscule, 1 chiffre)']);
    exit;
}

try {
    $meta = new PDO('mysql:host=' . DB_HOST . ';dbname=ocre_meta;charset=utf8mb4', DB_USER, DB_PASS, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    ]);
    $st = $meta->prepare("SELECT id, role, password_reset_expires FROM users WHERE password_reset_token = ? AND archived_at IS NULL LIMIT 1");
    $st->execute([$token]);
    $user = $st->fetch(PDO::FETCH_ASSOC);
    if (!$user) {
        http_response_code(404);
        echo json_encode(['ok' => false, 'error' => 'TOKEN_NOT_FOUND']);
        exit;
    }
    if (($user['role'] ?? '') !== 'super_admin') {
        http_response_code(403);
        echo json_encode(['ok' => false, 'error' => 'NOT_SUPER_ADMIN']);
        exit;
    }
    if (strtotime($user['password_reset_expires']) < time()) {
        http_response_code(410);
        echo json_encode(['ok' => false, 'error' => 'TOKEN_EXPIRED']);
        exit;
    }
    $hash = password_hash($pwd, PASSWORD_BCRYPT, ['cost' => 12]);
    $meta->prepare("UPDATE users SET password_hash = ?, password_reset_token = NULL, password_reset_expires = NULL, must_change_password = 0 WHERE id = ?")
        ->execute([$hash, (int)$user['id']]);
    // Invalidate toutes les sessions super_admin de ce user (force re-login)
    $meta->prepare("DELETE FROM sessions WHERE user_id = ?")->execute([(int)$user['id']]);
    echo json_encode(['ok' => true]);
} catch (Throwable $e) {
    error_log('superadmin_password_reset_confirm: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'server_error']);
}
