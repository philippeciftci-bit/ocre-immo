<?php
// M/2026/05/13/19 — Superadmin gestion utilisateurs : list + get + suspend + reactivate + force_logout + disable_2fa.
require_once __DIR__ . '/superadmin_lib.php';
$admin = superadmin_or_403();
header('Content-Type: application/json');

$meta = new PDO('mysql:host=' . DB_HOST . ';dbname=ocre_meta;charset=utf8mb4', DB_USER, DB_PASS, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);

$action = $_GET['action'] ?? 'list';

if ($action === 'list') {
    $q = trim((string)($_GET['q'] ?? ''));
    $limit = min(200, max(10, (int)($_GET['limit'] ?? 50)));
    $offset = max(0, (int)($_GET['offset'] ?? 0));
    if ($q) {
        $st = $meta->prepare("SELECT id, email, prenom, nom, role, is_suspended, totp_enabled, deletion_requested_at, anonymized_at, last_login_at, created_at FROM users WHERE email LIKE ? OR prenom LIKE ? OR nom LIKE ? ORDER BY id DESC LIMIT ? OFFSET ?");
        $like = '%' . $q . '%';
        $st->bindValue(1, $like); $st->bindValue(2, $like); $st->bindValue(3, $like);
        $st->bindValue(4, $limit, PDO::PARAM_INT); $st->bindValue(5, $offset, PDO::PARAM_INT);
    } else {
        $st = $meta->prepare("SELECT id, email, prenom, nom, role, is_suspended, totp_enabled, deletion_requested_at, anonymized_at, last_login_at, created_at FROM users ORDER BY id DESC LIMIT ? OFFSET ?");
        $st->bindValue(1, $limit, PDO::PARAM_INT); $st->bindValue(2, $offset, PDO::PARAM_INT);
    }
    $st->execute();
    echo json_encode(['ok' => true, 'users' => $st->fetchAll()]);
    exit;
}

if ($action === 'get') {
    $id = (int)($_GET['id'] ?? 0);
    if (!$id) { http_response_code(400); echo json_encode(['ok'=>false,'error'=>'id_required']); exit; }
    $st = $meta->prepare("SELECT * FROM users WHERE id = ?");
    $st->execute([$id]);
    $u = $st->fetch();
    if (!$u) { http_response_code(404); echo json_encode(['ok'=>false,'error'=>'not_found']); exit; }
    // Sessions actives.
    $ses = $meta->prepare("SELECT id, jti, user_agent, ip, created_at, last_activity_at FROM auth_sessions WHERE user_id = ? AND revoked_at IS NULL");
    $ses->execute([$id]);
    unset($u['password_hash']);
    echo json_encode(['ok' => true, 'user' => $u, 'sessions' => $ses->fetchAll()]);
    exit;
}

// Mutations : require POST.
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); echo json_encode(['ok'=>false,'error'=>'method_not_allowed']); exit; }
$input = json_decode(file_get_contents('php://input'), true) ?: [];
$uid = (int)($input['user_id'] ?? 0);
if (!$uid) { http_response_code(400); echo json_encode(['ok'=>false,'error'=>'user_id_required']); exit; }

if ($action === 'suspend') {
    $meta->prepare("UPDATE users SET is_suspended = 1 WHERE id = ?")->execute([$uid]);
    superadmin_log('user.suspend', 'user', (string)$uid);
    echo json_encode(['ok' => true]); exit;
}
if ($action === 'reactivate') {
    $meta->prepare("UPDATE users SET is_suspended = 0 WHERE id = ?")->execute([$uid]);
    superadmin_log('user.reactivate', 'user', (string)$uid);
    echo json_encode(['ok' => true]); exit;
}
if ($action === 'force_logout') {
    $st = $meta->prepare("UPDATE auth_sessions SET revoked_at = NOW() WHERE user_id = ? AND revoked_at IS NULL");
    $st->execute([$uid]);
    superadmin_log('user.force_logout', 'user', (string)$uid, ['count' => $st->rowCount()]);
    echo json_encode(['ok' => true, 'revoked' => $st->rowCount()]); exit;
}
if ($action === 'disable_2fa') {
    $meta->prepare("UPDATE users SET totp_enabled = 0, totp_secret = NULL, totp_backup_codes = NULL WHERE id = ?")->execute([$uid]);
    superadmin_log('user.disable_2fa', 'user', (string)$uid);
    echo json_encode(['ok' => true]); exit;
}
if ($action === 'cancel_deletion') {
    $meta->prepare("UPDATE users SET deletion_requested_at = NULL WHERE id = ?")->execute([$uid]);
    superadmin_log('user.cancel_deletion', 'user', (string)$uid);
    echo json_encode(['ok' => true]); exit;
}

http_response_code(404); echo json_encode(['ok'=>false,'error'=>'unknown_action']);
