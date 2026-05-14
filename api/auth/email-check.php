<?php
// M/2026/05/14/75 — email-check : determine si email existe deja en DB.
// POST { email } -> { ok: true, exists: bool, has_password: bool }
// Rate-limit 30/min/IP pour eviter enum.

declare(strict_types=1);
header('Content-Type: application/json');

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../lib/password_auth.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'method']);
    exit;
}

$input = json_decode(file_get_contents('php://input') ?: '{}', true);
$email = strtolower(trim((string)($input['email'] ?? '')));
$ip = explode(',', $_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['REMOTE_ADDR'] ?? '?')[0];

if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Email invalide']);
    exit;
}

$pdo = new PDO('mysql:host=' . DB_HOST . ';dbname=ocre_meta;charset=utf8mb4', DB_USER, DB_PASS, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
password_auth_rate_limit_init($pdo);

// Rate-limit 30/min/IP
$st = $pdo->prepare("SELECT COUNT(*) FROM auth_attempts WHERE scope='email_check' AND ip=? AND ts > NOW() - INTERVAL 1 MINUTE");
$st->execute([$ip]);
if ((int)$st->fetchColumn() >= 30) {
    http_response_code(429);
    echo json_encode(['ok' => false, 'error' => 'Trop de verifications, attends 1 minute']);
    exit;
}

$st = $pdo->prepare("SELECT id, password_hash, archived_at FROM users WHERE email=? LIMIT 1");
$st->execute([$email]);
$user = $st->fetch(PDO::FETCH_ASSOC);

password_auth_rate_log($pdo, 'email_check', $email, $ip, true, $_SERVER['HTTP_USER_AGENT'] ?? null);

$exists = $user && empty($user['archived_at']);
$hasPwd = $exists && !empty($user['password_hash']);

echo json_encode(['ok' => true, 'exists' => $exists, 'has_password' => $hasPwd]);
