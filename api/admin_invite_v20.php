<?php
// V20 M/2026/04/27/24 — endpoint admin codes invitation partner_free.
require_once __DIR__ . '/lib/router.php';
header('Content-Type: application/json; charset=utf-8');

function jout(array $d, int $code = 200) {
    http_response_code($code);
    echo json_encode($d, JSON_UNESCAPED_UNICODE);
    exit;
}

$u = current_user_or_401();
if (($u['role'] ?? '') !== 'super_admin') jout(['ok' => false, 'error' => 'super_admin only'], 403);

$action = $_GET['action'] ?? 'list';
$input = json_decode(file_get_contents('php://input'), true) ?: [];

switch ($action) {
case 'list': {
    $rows = pdo_meta()->prepare(
        "SELECT i.*, u.email AS used_by_email
         FROM invitation_codes i LEFT JOIN users u ON u.id = i.used_by_user_id
         ORDER BY i.created_at DESC LIMIT 200"
    );
    $rows->execute();
    jout(['ok' => true, 'codes' => $rows->fetchAll()]);
}

case 'create': {
    $target = $input['target_status'] ?? 'partner_free';
    if (!in_array($target, ['partner_free', 'trial_extended'], true)) {
        jout(['ok' => false, 'error' => 'target_status invalide'], 400);
    }
    $note = (string)($input['note'] ?? '');
    $expires_days = (int)($input['expires_days'] ?? 30);
    $code = strtoupper(bin2hex(random_bytes(6))); // 12 hex
    pdo_meta()->prepare(
        "INSERT INTO invitation_codes (code, target_status, created_by_user_id, expires_at, note)
         VALUES (?, ?, ?, DATE_ADD(NOW(), INTERVAL ? DAY), ?)"
    )->execute([$code, $target, $u['id'], $expires_days, $note]);
    jout(['ok' => true, 'code' => $code, 'target_status' => $target, 'expires_in_days' => $expires_days]);
}

case 'revoke': {
    $code = (string)($input['code'] ?? '');
    if (!$code) jout(['ok' => false, 'error' => 'code requis'], 400);
    pdo_meta()->prepare("UPDATE invitation_codes SET status='revoked' WHERE code = ? AND status='active'")
        ->execute([$code]);
    jout(['ok' => true]);
}

default:
    jout(['ok' => false, 'error' => 'action inconnue (list|create|revoke)'], 400);
}
