<?php
// GET /api/users.php → liste users multi-modules.
// POST /api/users.php → action: toggle_active | toggle_admin | send_magic_link | revoke_sessions
require_once __DIR__ . '/_lib.php';
sa_cors();
$admin = sa_require_super_admin();

$db = auth_db();
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

if ($method === 'GET') {
    $q = trim((string)($_GET['q'] ?? ''));
    $limit = max(1, min(200, (int)($_GET['limit'] ?? 50)));
    $sql = "SELECT u.id, u.email, u.first_name, u.last_name, u.status, u.is_super_admin,
                   u.created_at, u.last_login_at,
                   (SELECT COUNT(*) FROM auth_sessions s WHERE s.user_id=u.id AND s.revoked_at IS NULL AND s.expires_at > NOW()) AS active_sessions,
                   (SELECT GROUP_CONCAT(module_slug ORDER BY module_slug) FROM auth_user_modules m WHERE m.user_id=u.id AND m.active=1) AS modules
            FROM auth_users u";
    $args = [];
    if ($q !== '') {
        $sql .= " WHERE u.email LIKE ? OR u.first_name LIKE ? OR u.last_name LIKE ?";
        $like = '%' . $q . '%';
        $args = [$like, $like, $like];
    }
    $sql .= " ORDER BY u.last_login_at DESC, u.created_at DESC LIMIT $limit";
    $st = $db->prepare($sql);
    $st->execute($args);
    $rows = $st->fetchAll();
    sa_send_json(['ok' => true, 'users' => $rows, 'count' => count($rows)]);
}

if ($method === 'POST') {
    $data = json_decode(file_get_contents('php://input'), true) ?: [];
    $action = (string)($data['action'] ?? '');
    $userId = (int)($data['user_id'] ?? 0);
    if (!$userId) sa_send_json(['ok' => false, 'error' => 'missing_user_id'], 400);
    $st = $db->prepare("SELECT id, email, status, is_super_admin FROM auth_users WHERE id=?");
    $st->execute([$userId]);
    $target = $st->fetch();
    if (!$target) sa_send_json(['ok' => false, 'error' => 'user_not_found'], 404);

    switch ($action) {
        case 'toggle_active':
            $new = $target['status'] === 'active' ? 'suspended' : 'active';
            $db->prepare("UPDATE auth_users SET status=? WHERE id=?")->execute([$new, $userId]);
            if ($new === 'suspended') {
                $db->prepare("UPDATE auth_sessions SET revoked_at=NOW() WHERE user_id=? AND revoked_at IS NULL")->execute([$userId]);
            }
            sa_audit((int)$admin['id'], 'user.toggle_active', $target['email'], ['from' => $target['status'], 'to' => $new]);
            sa_send_json(['ok' => true, 'status' => $new]);
        case 'toggle_admin':
            if ((int)$target['id'] === (int)$admin['id']) sa_send_json(['ok' => false, 'error' => 'cannot_toggle_self'], 400);
            $new = (int)$target['is_super_admin'] ? 0 : 1;
            $db->prepare("UPDATE auth_users SET is_super_admin=? WHERE id=?")->execute([$new, $userId]);
            sa_audit((int)$admin['id'], 'user.toggle_admin', $target['email'], ['to' => $new]);
            sa_send_json(['ok' => true, 'is_super_admin' => $new]);
        case 'revoke_sessions':
            $db->prepare("UPDATE auth_sessions SET revoked_at=NOW() WHERE user_id=? AND revoked_at IS NULL")->execute([$userId]);
            sa_audit((int)$admin['id'], 'user.revoke_sessions', $target['email']);
            sa_send_json(['ok' => true]);
        case 'send_magic_link':
            // Invalide tokens existants + insère un nouveau token (envoi email côté request.php non rejoué ici pour ne pas dupliquer la logique mail).
            $db->prepare("UPDATE auth_magic_tokens SET used_at=NOW() WHERE user_id=? AND used_at IS NULL")->execute([$userId]);
            $tok = bin2hex(random_bytes(32));
            $db->prepare("INSERT INTO auth_magic_tokens (user_id, token, expires_at, ip) VALUES (?, ?, DATE_ADD(NOW(), INTERVAL 15 MINUTE), ?)")
               ->execute([$userId, $tok, auth_client_ip()]);
            sa_audit((int)$admin['id'], 'user.send_magic_link', $target['email']);
            $url = 'https://auth.ocre.immo/api/magic-link/validate.php?token=' . $tok . '&app=agent';
            sa_send_json(['ok' => true, 'token_url' => $url, 'note' => 'Copier ce lien et le transmettre, ou utiliser /api/magic-link/request.php pour envoi email.']);
    }
    sa_send_json(['ok' => false, 'error' => 'unknown_action'], 400);
}

sa_send_json(['ok' => false, 'error' => 'method_not_allowed'], 405);
