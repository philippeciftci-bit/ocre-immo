<?php
// M/2026/05/09/71 — Endpoint logout_all : revoque toutes les sessions du user courant.
// POST → identifie user via cookie, revokeAllSessions, supprime cookie courant.

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/_session.php';
setCorsHeaders();

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'METHOD_NOT_ALLOWED']);
    exit;
}

$user = getCurrentUserFromCookie();
if (!$user) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'UNAUTHENTICATED']);
    exit;
}

$count = revokeAllSessions((int)$user['user_id']);
clearSessionCookie();

http_response_code(200);
echo json_encode(['ok' => true, 'revoked_count' => $count]);
