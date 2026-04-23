<?php
// Ocre v18.3 — retourne la clé VAPID publique pour le subscribe côté front.
require_once __DIR__ . '/db.php';
setCorsHeaders();
$pub = getSetting('vapid_public', '');
jsonOk(['public_key' => $pub ?: null, 'configured' => (bool)$pub]);
