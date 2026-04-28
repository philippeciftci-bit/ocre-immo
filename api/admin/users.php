<?php
// M/2026/04/28/52 — Dashboard super-admin : gestion users transverse.
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/_audit.php';
setCorsHeaders();

$user = requireAuth();
$isSuper = ($user['role'] ?? '') === 'super_admin' || ($user['_origin_role'] ?? '') === 'super_admin';
if (!$isSuper) jsonError('Accès refusé (super_admin requis)', 403);
$superUid = (int) ($user['_origin_user_id'] ?? $user['id']);

$action = $_GET['action'] ?? ($_POST['action'] ?? '');
$input = getInput();

$dsn = 'mysql:host=' . DB_HOST . ';dbname=ocre_meta;charset=utf8mb4';
$meta = new PDO($dsn, DB_USER, DB_PASS, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
]);

switch ($action) {

case 'list': {
    $q = trim($_GET['q'] ?? '');
    $sql = "SELECT u.id, u.email, u.display_name, u.role, u.is_suspended, u.created_at, u.last_login_at, u.archived_at,
                   (SELECT GROUP_CONCAT(w.slug) FROM workspace_members m JOIN workspaces w ON w.id=m.workspace_id WHERE m.user_id=u.id AND m.left_at IS NULL) AS slugs
            FROM users u WHERE 1=1";
    $params = [];
    if ($q) {
        $sql .= " AND (u.email LIKE ? OR u.display_name LIKE ?)";
        $params[] = "%$q%"; $params[] = "%$q%";
    }
    $sql .= " ORDER BY u.created_at DESC LIMIT 500";
    $st = $meta->prepare($sql);
    $st->execute($params);
    jsonOk(['users' => $st->fetchAll()]);
}

case 'suspend': {
    $id = (int) ($input['user_id'] ?? 0);
    if (!$id) jsonError('user_id requis', 400);
    $meta->prepare("UPDATE users SET is_suspended = 1 WHERE id = ?")->execute([$id]);
    audit_log($superUid, 'user_suspend', 'user', $id, ['target_uid' => $id]);
    jsonOk(['ok' => true]);
}

case 'reactivate': {
    $id = (int) ($input['user_id'] ?? 0);
    if (!$id) jsonError('user_id requis', 400);
    $meta->prepare("UPDATE users SET is_suspended = 0 WHERE id = ?")->execute([$id]);
    audit_log($superUid, 'user_reactivate', 'user', $id, ['target_uid' => $id]);
    jsonOk(['ok' => true]);
}

case 'archive': {
    $id = (int) ($input['user_id'] ?? 0);
    if (!$id) jsonError('user_id requis', 400);
    if ($id === $superUid) jsonError('Impossible de s\'archiver soi-même', 403);
    $meta->prepare("UPDATE users SET archived_at = NOW() WHERE id = ?")->execute([$id]);
    audit_log($superUid, 'user_archive', 'user', $id, ['target_uid' => $id]);
    jsonOk(['ok' => true]);
}

default:
    jsonError('Action inconnue (list | suspend | reactivate | archive)', 400);
}
