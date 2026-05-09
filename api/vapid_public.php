<?php
// Ocre v18.3 — retourne la clé VAPID publique pour le subscribe côté front.
// M/2026/05/09/42 — M88 : fallback sur /root/.secrets/ocre_vapid_pub.b64 si system_settings vide.
require_once __DIR__ . '/db.php';
setCorsHeaders();
$pub = '';
try { $pub = getSetting('vapid_public', ''); } catch (Throwable $e) {}
if ($pub === '') {
    $f = '/root/.secrets/ocre_vapid_pub.b64';
    if (is_readable($f)) $pub = trim(file_get_contents($f));
}
jsonOk(['public_key' => $pub ?: null, 'configured' => (bool)$pub]);
