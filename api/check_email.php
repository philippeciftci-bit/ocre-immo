<?php
// M/2026/05/06/86 — Endpoint validation reactive email cote wizard signup.
// POST JSON {email} -> 200 {available: true|false, reason: 'ok'|'invalid'|'already_used'|'rate_limited'}
// Rate limit basique 1 req/sec par IP (fenetre glissante 60s, max 60 req).

require_once __DIR__ . '/db.php';
setCorsHeaders();

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    http_response_code(405);
    echo json_encode(['available' => false, 'reason' => 'method_not_allowed']);
    exit;
}

$ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
$rateFile = '/var/log/ocre/check-email-rate.log';
@touch($rateFile);
@chmod($rateFile, 0664);

// Rate limit : compter les requetes de cette IP dans les 60 dernieres secondes.
$now = time();
$lines = @file($rateFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
$cutoff = $now - 60;
$kept = [];
$ipCount = 0;
foreach ($lines as $line) {
    [$ts, $logIp] = explode(' ', $line . ' ', 2);
    $ts = (int)$ts;
    if ($ts < $cutoff) continue;
    $kept[] = $line;
    if (trim($logIp) === $ip) $ipCount++;
}
if ($ipCount >= 60) {
    http_response_code(429);
    echo json_encode(['available' => false, 'reason' => 'rate_limited']);
    exit;
}
$kept[] = $now . ' ' . $ip;
@file_put_contents($rateFile, implode("\n", array_slice($kept, -2000)) . "\n", LOCK_EX);

$input = getInput();
$email = strtolower(trim((string)($input['email'] ?? '')));
if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    echo json_encode(['available' => false, 'reason' => 'invalid']);
    exit;
}

try {
    $dsn = 'mysql:host=' . DB_HOST . ';dbname=ocre_meta;charset=utf8mb4';
    $meta = new PDO($dsn, DB_USER, DB_PASS, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
    $st = $meta->prepare("SELECT id, status FROM users WHERE email = ? AND archived_at IS NULL LIMIT 1");
    $st->execute([$email]);
    $existing = $st->fetch();
    if ($existing && $existing['status'] === 'active') {
        echo json_encode(['available' => false, 'reason' => 'already_used']);
        exit;
    }
    // pending_activation : on autorise reprise (cf agents_register idempotence)
    echo json_encode(['available' => true, 'reason' => 'ok']);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['available' => true, 'reason' => 'error']);
}
