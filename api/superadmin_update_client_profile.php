<?php
// M/2026/05/09/9 (M61) — Update users.profile_label depuis console superadmin.
// POST {user_id, profile_label} (cookie ocre_session role=super_admin requis).
// Valeurs admises : super_admin, admin, diamant, standard, restreint.

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
$label = trim((string)($input['profile_label'] ?? ''));
$allowed = ['super_admin', 'admin', 'diamant', 'standard', 'restreint'];
if ($uid <= 0) { http_response_code(400); echo json_encode(['ok'=>false,'error'=>'INVALID_USER_ID']); exit; }
if (!in_array($label, $allowed, true)) { http_response_code(400); echo json_encode(['ok'=>false,'error'=>'INVALID_LABEL']); exit; }

// Garde-fou : super_admin id=2 INTOUCHABLE (ne peut pas etre demote)
if ($uid === 2 && $label !== 'super_admin') {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'SUPER_ADMIN_PROTECTED']);
    exit;
}

try {
    $pdo = new PDO(
        'mysql:host=' . DB_HOST . ';dbname=ocre_meta;charset=utf8mb4',
        DB_USER, DB_PASS,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
    );
    $upd = $pdo->prepare("UPDATE users SET profile_label = ? WHERE id = ?");
    $upd->execute([$label, $uid]);
    @error_log('[superadmin_update_client_profile] admin=' . (int)$admin['user_id'] . ' user=' . $uid . ' new_label=' . $label);
    http_response_code(200);
    echo json_encode(['ok' => true, 'user_id' => $uid, 'profile_label' => $label]);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'SERVER_ERROR', 'detail' => $e->getMessage()]);
}
