<?php
// M/2026/05/09/71 — Endpoint logout : revoque la session courante + supprime cookie.
// POST → revokeSession(cookie) + clearSessionCookie + 200 ok.

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/_session.php';
setCorsHeaders();

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'METHOD_NOT_ALLOWED']);
    exit;
}

$cookieToken = $_COOKIE[OCRE_SESSION_COOKIE_NAME] ?? '';
if ($cookieToken !== '') {
    revokeSession($cookieToken);
}
clearSessionCookie();

http_response_code(200);
echo json_encode(['ok' => true]);
