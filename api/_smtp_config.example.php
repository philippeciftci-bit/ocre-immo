<?php
// M/2026/05/08/31 — Template config SMTP OVH (committable, gitté).
// Le fichier réel _smtp_config.php est gitignored et stocké en perms 600 root:www-data.
// Décision Philippe 2026-05-08 : OVH SMTP authentifié exclusif (pas de service tiers).

// Le password peut être lu depuis un secret file (recommandé) :
$_secretFile = '/root/.secrets/ovh-noreply-ocre.pwd';
$_pass = is_readable($_secretFile) ? trim((string)@file_get_contents($_secretFile)) : '<REPLACE_WITH_OVH_PASSWORD>';

return [
    'host'       => 'ssl0.ovh.net',
    'port'       => 465,                          // 465 SSL/TLS direct, ou 587 STARTTLS si bascule.
    'username'   => 'noreply@ocre.immo',
    'password'   => $_pass,
    'encryption' => 'ssl',                        // 'ssl' (port 465) ou 'tls' (port 587).
    'from_email' => 'noreply@ocre.immo',
    'from_name'  => 'Oi Agent',
    'reply_to'   => 'support@ocre.immo',
];
