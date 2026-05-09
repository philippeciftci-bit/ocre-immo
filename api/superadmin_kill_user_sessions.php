<?php
// M/2026/05/09/9 (M61) — Stoppe toutes les sessions actives d'un user.
// POST {user_id} (cookie ocre_session role=super_admin requis).

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/_session.php';
setCorsHeaders();

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'METHOD_NOT_ALLOWED']);
    exit;
}

$admin = getCurrentUserFromCookie();
if (!$admin || ($admin['role'] ?? '') !== 'super_admin') {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'UNAUTHORIZED']);
    exit;
}

$input = getInput();
$uid = (int)($input['user_id'] ?? 0);
if ($uid <= 0) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'INVALID_USER_ID']);
    exit;
}

// Garde-fou : super_admin id=2 INTOUCHABLE
if ($uid === 2) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'SUPER_ADMIN_PROTECTED']);
    exit;
}

try {
    $count = revokeAllSessions($uid);
    @error_log('[superadmin_kill_user_sessions] admin=' . (int)$admin['user_id'] . ' killed user=' . $uid . ' sessions=' . $count);
    http_response_code(200);
    echo json_encode(['ok' => true, 'user_id' => $uid, 'revoked_count' => $count]);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'SERVER_ERROR', 'detail' => $e->getMessage()]);
}
