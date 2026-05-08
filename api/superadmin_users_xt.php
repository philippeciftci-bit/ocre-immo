<?php
// M/2026/05/08/37 — Super-admin liste users cross-workspace.
// GET ?action=list_all[&q=email&role=&status=]  → liste tous users meta
// POST {action:delete, user_id, confirm_active_delete?} → DELETE user (garde-fou super_admin self)

require_once __DIR__ . '/lib/router.php';
header('Content-Type: application/json; charset=utf-8');

function jout(array $d, int $code = 200) {
    http_response_code($code);
    echo json_encode($d, JSON_UNESCAPED_UNICODE);
    exit;
}

$user = current_user_or_401();
if (($user['role'] ?? '') !== 'super_admin') jout(['ok' => false, 'error' => 'super_admin only'], 403);

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$meta = pdo_meta();
$LOG = '/var/log/ocre-superadmin-actions.log';

if ($method === 'GET') {
    $action = $_GET['action'] ?? 'list_all';
    if ($action === 'list_all') {
        $q = trim((string)($_GET['q'] ?? ''));
        $role = trim((string)($_GET['role'] ?? ''));
        $status = trim((string)($_GET['status'] ?? ''));
        $where = ['archived_at IS NULL'];
        $params = [];
        if ($q !== '') { $where[] = '(email LIKE ? OR display_name LIKE ?)'; $params[] = '%' . $q . '%'; $params[] = '%' . $q . '%'; }
        if ($role !== '') { $where[] = 'role = ?'; $params[] = $role; }
        if ($status !== '') { $where[] = 'status = ?'; $params[] = $status; }
        $sql = "SELECT id, email, display_name, prenom, nom, role, status, slug, telephone, ville, last_login, created_at
                  FROM users WHERE " . implode(' AND ', $where) . "
                  ORDER BY created_at DESC LIMIT 500";
        $st = $meta->prepare($sql);
        $st->execute($params);
        $rows = $st->fetchAll();
        $list = array_map(fn($r) => [
            'id' => (int)$r['id'],
            'email' => (string)$r['email'],
            'display_name' => (string)$r['display_name'],
            'role' => (string)$r['role'],
            'status' => (string)$r['status'],
            'slug' => (string)($r['slug'] ?? '—'),
            'telephone' => (string)($r['telephone'] ?? ''),
            'ville' => (string)($r['ville'] ?? ''),
            'last_login' => (string)($r['last_login'] ?? ''),
            'created_at' => (string)$r['created_at'],
        ], $rows);
        jout(['ok' => true, 'count' => count($list), 'users' => $list]);
    }
    jout(['ok' => false, 'error' => 'unknown GET action'], 400);
}

if ($method !== 'POST') jout(['ok' => false, 'error' => 'method not allowed'], 405);

$raw = file_get_contents('php://input');
$input = is_array(($j = json_decode($raw, true))) ? $j : [];
$action = (string)($input['action'] ?? '');

if ($action === 'delete') {
    $uid = (int)($input['user_id'] ?? 0);
    if ($uid <= 0) jout(['ok' => false, 'error' => 'user_id required'], 400);
    if ($uid === (int)$user['id']) jout(['ok' => false, 'error' => 'cannot delete self'], 409);
    // Vérif role cible : pas de suppression d un autre super_admin sans confirmation explicite.
    $st = $meta->prepare("SELECT email, role, status FROM users WHERE id = ?");
    $st->execute([$uid]);
    $target = $st->fetch();
    if (!$target) jout(['ok' => false, 'error' => 'user not found'], 404);
    if ($target['role'] === 'super_admin' && empty($input['confirm_super_admin_delete'])) {
        jout(['ok' => false, 'error' => 'cannot delete super_admin without confirm_super_admin_delete=true'], 409);
    }
    $del = $meta->prepare("DELETE FROM users WHERE id = ?");
    $del->execute([$uid]);
    // Cleanup associé
    @$meta->prepare("DELETE FROM sessions WHERE user_id = ?")->execute([$uid]);
    @$meta->prepare("DELETE FROM workspace_members WHERE user_id = ?")->execute([$uid]);
    @file_put_contents($LOG, "[" . date('c') . "] sa#" . $user['id'] . " delete_user_xt id=$uid email=" . $target['email'] . "\n", FILE_APPEND);
    @shell_exec('/root/bin/notify --project ocre --priority high --phase warn --mission-id ' . escapeshellarg('SUPERADMIN-USERS-XT/' . time()) . ' --title ' . escapeshellarg('[OCRE] Delete user cross-workspace') . ' --body ' . escapeshellarg("user_id=$uid email=" . $target['email'] . " by sa=" . $user['email']) . ' >/dev/null 2>&1 &');
    jout(['ok' => true, 'deleted' => $del->rowCount()]);
}

jout(['ok' => false, 'error' => 'unknown action: ' . $action], 400);
