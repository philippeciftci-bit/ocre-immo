<?php
// M/2026/05/11/24 — superadmin.ocre.immo : visu live des auth_users (DB V4 magic-link)
//   GET  ?action=list                        → liste tous les auth_users + counts globaux
//   POST ?action=delete  body {user_id}      → DELETE un user (refus si super-admin Philippe)
require_once __DIR__ . '/lib/router.php';
require_once __DIR__ . '/lib/audit_logs.php';
header('Content-Type: application/json; charset=utf-8');

function aj(array $d, int $code = 200) { http_response_code($code); echo json_encode($d, JSON_UNESCAPED_UNICODE); exit; }

$user = current_user_or_401();
if (($user['role'] ?? '') !== 'super_admin') aj(['ok' => false, 'error' => 'super_admin only'], 403);

$action = $_GET['action'] ?? 'list';
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

require_once __DIR__ . '/db.php';
$meta = pdo_meta();

if ($action === 'list') {
    $users = $meta->query(
        "SELECT id, email, first_name, last_name, status, is_super_admin, created_at, last_login_at,
                (SELECT COUNT(*) FROM auth_sessions s WHERE s.user_id=u.id AND s.revoked_at IS NULL AND s.expires_at > NOW()) AS active_sessions,
                (SELECT COUNT(*) FROM auth_magic_tokens m WHERE m.user_id=u.id AND m.used_at IS NULL AND m.expires_at > NOW()) AS pending_magic
         FROM auth_users u
         ORDER BY id"
    )->fetchAll(PDO::FETCH_ASSOC);
    $counts = [
        'auth_users' => (int) $meta->query("SELECT COUNT(*) FROM auth_users")->fetchColumn(),
        'auth_magic_tokens' => (int) $meta->query("SELECT COUNT(*) FROM auth_magic_tokens")->fetchColumn(),
        'auth_sessions_active' => (int) $meta->query("SELECT COUNT(*) FROM auth_sessions WHERE revoked_at IS NULL AND expires_at > NOW()")->fetchColumn(),
        'auth_user_modules' => (int) $meta->query("SELECT COUNT(*) FROM auth_user_modules")->fetchColumn(),
    ];
    aj(['ok' => true, 'users' => $users, 'counts' => $counts, 'generated_at' => date('c')]);
}

if ($action === 'delete' && $method === 'POST') {
    $raw = file_get_contents('php://input');
    $input = json_decode($raw, true) ?: [];
    $userId = (int) ($input['user_id'] ?? 0);
    if (!$userId) aj(['ok' => false, 'error' => 'missing user_id'], 400);
    $row = $meta->prepare("SELECT id, email, is_super_admin FROM auth_users WHERE id = ?");
    $row->execute([$userId]);
    $target = $row->fetch(PDO::FETCH_ASSOC);
    if (!$target) aj(['ok' => false, 'error' => 'user_not_found'], 404);
    if ($target['email'] === 'philippe.ciftci@gmail.com' || (int) $target['is_super_admin'] === 1) {
        aj(['ok' => false, 'error' => 'cannot_delete_super_admin'], 403);
    }
    try {
        // Cascade : magic_tokens + sessions + modules avant DELETE user.
        $meta->prepare("DELETE FROM auth_magic_tokens WHERE user_id = ?")->execute([$userId]);
        $meta->prepare("DELETE FROM auth_sessions WHERE user_id = ?")->execute([$userId]);
        $meta->prepare("DELETE FROM auth_user_modules WHERE user_id = ?")->execute([$userId]);
        try { $meta->prepare("DELETE FROM auth_refresh_tokens WHERE user_id = ?")->execute([$userId]); } catch (Throwable $e) { /* table may not exist */ }
        $del = $meta->prepare("DELETE FROM auth_users WHERE id = ?");
        $del->execute([$userId]);
        audit_log_insert((int) $user['id'], 'auth_users.delete', ['user_id' => $userId, 'email' => $target['email']]);
        aj(['ok' => true, 'deleted_id' => $userId, 'deleted_email' => $target['email']]);
    } catch (Throwable $e) {
        aj(['ok' => false, 'error' => 'delete_failed: ' . $e->getMessage()], 500);
    }
}

aj(['ok' => false, 'error' => 'unknown action: ' . $action], 400);
