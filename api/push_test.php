<?php
// M/2026/05/09/42 — M88 : envoie un push test à l'agent connecté pour valider la chaîne (subscribe → SW → notification).
require_once __DIR__ . '/db.php';
setCorsHeaders();
$user = requireAuth();
$uid = (int) ($user['_origin_user_id'] ?? $user['id']);

// Forge un input pour push_send.php avec type=test (bypass filtre types_enabled).
$body = [
    'user_id' => $uid,
    'type' => 'test',
    'title' => '🔔 Oi Agent — test',
    'body' => 'Notification test reçue. Ta chaîne PWA push fonctionne ✓',
    'url' => '/',
    'tag' => 'ocre-test',
];

// Appel direct à push_send.php avec le contexte CLI-bypass via header interne.
$tokenFile = '/root/.secrets/ocre_push_internal.token';
if (is_readable($tokenFile)) $body['internal_token'] = trim(file_get_contents($tokenFile));

// On simule un appel POST direct au handler push_send.
$_POST = $body;
$_SERVER['REQUEST_METHOD'] = 'POST';
// getInput() lit php://input prioritairement — fallback $_POST si vide.
// Pour fiabilité, on appelle directement le helper via une nouvelle req cURL interne loopback.
$internalUrl = 'http://127.0.0.1/api/push_send.php';
$ch = curl_init($internalUrl);
curl_setopt_array($ch, [
    CURLOPT_POST => true,
    CURLOPT_POSTFIELDS => json_encode($body),
    CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Host: ' . ($_SERVER['HTTP_HOST'] ?? 'localhost')],
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT => 15,
]);
$resp = curl_exec($ch);
$err = curl_error($ch);
$http = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

if ($err) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'internal_curl_failed', 'detail' => $err]);
    exit;
}
http_response_code($http);
echo $resp;
