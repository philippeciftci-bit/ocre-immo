<?php
// GET /api/me.php → renvoie user courant si super_admin, sinon 401/403.
require_once __DIR__ . '/_lib.php';
sa_cors();
$u = sa_require_super_admin();
sa_send_json([
    'ok' => true,
    'user' => [
        'id' => (int) $u['id'],
        'email' => $u['email'],
        'first_name' => $u['first_name'],
        'last_name' => $u['last_name'],
        'is_super_admin' => (int) $u['is_super_admin'],
    ],
]);
