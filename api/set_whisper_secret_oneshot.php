<?php
// Ocre v18.6 — One-shot endpoint pour stocker le shared secret Whisper VPS dans settings.
// IP-whitelist VPS atelier (46.225.215.148). Idempotent.
require_once __DIR__ . '/db.php';
header('Content-Type: application/json; charset=utf-8');

$allowed = ['46.225.215.148'];
$remote = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? ($_SERVER['REMOTE_ADDR'] ?? '');
$remote_ip = trim(explode(',', $remote)[0]);
if (!in_array($remote_ip, $allowed, true)) {
    http_response_code(403);
    exit(json_encode(['ok' => false, 'error' => 'Forbidden']));
}

$input = getInput();
$secret = trim((string)($input['secret'] ?? ($_GET['secret'] ?? '')));
if (!$secret || strlen($secret) < 16) {
    exit(json_encode(['ok' => false, 'error' => 'secret invalide']));
}

setSetting('whisper_shared_secret', $secret);
setSetting('whisper_endpoint', 'https://46-225-215-148.sslip.io/whisper/transcribe');

echo json_encode(['ok' => true, 'stored' => true, 'len' => strlen($secret)]);
