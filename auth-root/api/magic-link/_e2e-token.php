<?php
// M/2026/05/12/7 — Endpoint LECTURE token magic-link mode test E2E.
// Protege par HMAC headers identiques a ceux de request.php.
// Si /etc/ocre/e2e-secret.env absent : retourne 404 (endpoint inexistant en prod sans config).
// Si HMAC invalide ou skew > 60s : retourne 404 (pas 403, ne pas reveler son existence).
// Si valide : retourne {ok:true, email, token, url, app, created_at} pour l email demande.

if (!file_exists('/etc/ocre/e2e-secret.env')) {
    http_response_code(404);
    exit;
}

$e2eHeader = $_SERVER['HTTP_X_E2E_TEST'] ?? '';
$e2eTs = (int)($_SERVER['HTTP_X_E2E_TIMESTAMP'] ?? 0);
$email = strtolower(trim((string)($_GET['email'] ?? '')));

if (!$e2eHeader || !$e2eTs || !$email) {
    http_response_code(404);
    exit;
}

$secret = trim((string)@file_get_contents('/etc/ocre/e2e-secret.env'));
if ($secret === '' || abs(time() - $e2eTs) > 60) {
    http_response_code(404);
    exit;
}

$expected = hash_hmac('sha256', (string)$e2eTs, $secret);
if (!hash_equals($expected, $e2eHeader)) {
    http_response_code(404);
    exit;
}

$tokensFile = '/tmp/e2e-magic-tokens.json';
if (!is_file($tokensFile)) {
    header('Content-Type: application/json');
    echo json_encode(['ok' => false, 'error' => 'no_tokens']);
    exit;
}

$store = json_decode((string)@file_get_contents($tokensFile), true);
if (!is_array($store) || !isset($store[$email])) {
    header('Content-Type: application/json');
    echo json_encode(['ok' => false, 'error' => 'no_token_for_email']);
    exit;
}

header('Content-Type: application/json');
echo json_encode(['ok' => true, 'email' => $email] + $store[$email]);
