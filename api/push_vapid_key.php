<?php
// M/2026/05/09/42 — M88 : expose la clé publique VAPID base64url pour applicationServerKey côté front.
// Pas d'auth requise (clé publique).
require_once __DIR__ . '/db.php';
setCorsHeaders();

$pubFile = '/root/.secrets/ocre_vapid_pub.b64';
if (!is_readable($pubFile)) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'vapid_pub_unavailable']);
    exit;
}
$pub = trim(file_get_contents($pubFile));
echo json_encode(['ok' => true, 'publicKey' => $pub]);
