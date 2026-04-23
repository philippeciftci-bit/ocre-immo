<?php
// Ocre v18.3 — génération VAPID keys (idempotente). IP-whitelist VPS atelier.
// curl https://app.ocre.immo/api/vapid_setup.php
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/vendor/autoload.php';
header('Content-Type: application/json; charset=utf-8');

$allowed = ['46.225.215.148'];
$remote = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? ($_SERVER['REMOTE_ADDR'] ?? '');
$remote_ip = trim(explode(',', $remote)[0]);
if (!in_array($remote_ip, $allowed, true)) {
    http_response_code(403);
    exit(json_encode(['ok' => false, 'error' => 'Forbidden']));
}

// Si déjà existant → retourne sans regen.
$existing = getSetting('vapid_public', '');
if ($existing && getSetting('vapid_private', '')) {
    echo json_encode(['ok' => true, 'reused' => true, 'public' => $existing]);
    exit;
}

$keys = \Minishlink\WebPush\VAPID::createVapidKeys();
setSetting('vapid_public', $keys['publicKey']);
setSetting('vapid_private', $keys['privateKey']);
setSetting('vapid_subject', 'mailto:contact@ocre.immo');

echo json_encode(['ok' => true, 'created' => true, 'public' => $keys['publicKey']]);
