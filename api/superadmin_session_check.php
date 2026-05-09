<?php
// M/2026/05/09/75 — Endpoint check session super-admin.
// Lit cookie ocre_session, valide via _session.php, verifie role=super_admin.
// Retour : 200 {ok, user} ou {ok:false}.

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/_session.php';
setCorsHeaders();

$user = getCurrentUserFromCookie();
if (!$user) {
    http_response_code(200);
    echo json_encode(['ok' => false]);
    exit;
}
if (($user['role'] ?? '') !== 'super_admin') {
    http_response_code(200);
    echo json_encode(['ok' => false, 'reason' => 'NOT_SUPER_ADMIN']);
    exit;
}

$cookieToken = $_COOKIE[OCRE_SESSION_COOKIE_NAME] ?? '';
if ($cookieToken !== '') setSessionCookie($cookieToken);

http_response_code(200);
echo json_encode([
    'ok' => true,
    'user' => [
        'id' => $user['user_id'],
        'email' => $user['email'],
        'prenom' => $user['prenom'],
        'nom' => $user['nom'],
        'role' => $user['role'],
    ],
]);
