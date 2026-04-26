<?php
// V20 phase 3 — auth multi-tenant. Login mail+password (ocre_meta.users + sessions),
// switch-mode (cookie OCRE_MODE_<slug>), status, logout.
require_once __DIR__ . '/lib/router.php';

header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Headers: Content-Type, X-Session-Token');
header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(200); exit; }

$action = $_GET['action'] ?? ($_POST['action'] ?? '');
$input = json_decode(file_get_contents('php://input'), true) ?: [];

function jout(array $d, int $code = 200) {
    http_response_code($code);
    echo json_encode($d, JSON_UNESCAPED_UNICODE);
    exit;
}

switch ($action) {

case 'status': {
    jout(['ok' => true, 'app_name' => 'Ocre Immo', 'app_tagline' => 'CRM Immobilier multi-tenant']);
}

case 'login': {
    $email = strtolower(trim((string)($input['email'] ?? '')));
    $pwd = (string)($input['password'] ?? '');
    if (!filter_var($email, FILTER_VALIDATE_EMAIL) || !$pwd) jout(['ok' => false, 'error' => 'email/pwd requis'], 400);
    $stmt = pdo_meta()->prepare("SELECT * FROM users WHERE email = ? AND archived_at IS NULL LIMIT 1");
    $stmt->execute([$email]);
    $u = $stmt->fetch();
    if (!$u || !password_verify($pwd, $u['password_hash'])) jout(['ok' => false, 'error' => 'Identifiants invalides'], 401);
    $token = bin2hex(random_bytes(32));
    pdo_meta()->prepare(
        "INSERT INTO sessions (token, user_id, expires_at, ip, user_agent)
         VALUES (?, ?, DATE_ADD(NOW(), INTERVAL 30 DAY), ?, ?)"
    )->execute([
        $token, $u['id'],
        $_SERVER['REMOTE_ADDR'] ?? null,
        substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 255)
    ]);
    pdo_meta()->prepare("UPDATE users SET last_login = NOW() WHERE id = ?")->execute([$u['id']]);

    // Workspaces accessibles (WSp owner + WSc actifs avec pacte signe)
    $ws_stmt = pdo_meta()->prepare(
        "SELECT w.id, w.slug, w.type, w.display_name, w.country_code, m.role,
                CASE WHEN w.type = 'wsc' THEN
                    (SELECT COUNT(*) FROM pact_signatures p
                     WHERE p.wsc_id = w.id AND p.user_id = ? AND p.signed_at IS NOT NULL)
                ELSE 1 END AS pact_signed
         FROM workspace_members m
         JOIN workspaces w ON w.id = m.workspace_id
         WHERE m.user_id = ? AND m.left_at IS NULL AND w.archived_at IS NULL
         ORDER BY w.type, w.slug"
    );
    $ws_stmt->execute([$u['id'], $u['id']]);
    $workspaces = $ws_stmt->fetchAll();

    jout([
        'ok' => true,
        'token' => $token,
        'user' => [
            'id' => (int)$u['id'],
            'email' => $u['email'],
            'display_name' => $u['display_name'],
            'role' => $u['role'],
            'country_code' => $u['country_code'],
            'must_change_password' => (bool)$u['must_change_password'],
        ],
        'workspaces' => $workspaces,
    ]);
}

case 'me': {
    $u = current_user_or_401();
    $ws_stmt = pdo_meta()->prepare(
        "SELECT w.id, w.slug, w.type, w.display_name, w.country_code, m.role
         FROM workspace_members m JOIN workspaces w ON w.id = m.workspace_id
         WHERE m.user_id = ? AND m.left_at IS NULL AND w.archived_at IS NULL
         ORDER BY w.type, w.slug"
    );
    $ws_stmt->execute([$u['id']]);
    jout([
        'ok' => true,
        'user' => [
            'id' => (int)$u['id'], 'email' => $u['email'],
            'display_name' => $u['display_name'], 'role' => $u['role'],
            'country_code' => $u['country_code'],
            'must_change_password' => (bool)$u['must_change_password'],
        ],
        'workspaces' => $ws_stmt->fetchAll(),
    ]);
}

case 'switch_mode': {
    $u = current_user_or_401();
    $slug = strtolower(preg_replace('/[^a-z0-9-]/', '', (string)($input['workspace_slug'] ?? '')));
    $mode = $input['mode'] ?? 'agent';
    if (!in_array($mode, ['agent', 'test'], true)) jout(['ok' => false, 'error' => 'mode invalide'], 400);
    $check = pdo_meta()->prepare(
        "SELECT m.role, w.type FROM workspace_members m
         JOIN workspaces w ON w.id = m.workspace_id
         WHERE w.slug = ? AND m.user_id = ? AND m.left_at IS NULL AND w.archived_at IS NULL"
    );
    $check->execute([$slug, $u['id']]);
    $r = $check->fetch();
    if (!$r) jout(['ok' => false, 'error' => 'Pas membre de ce workspace'], 403);
    if ($r['type'] !== 'wsp') jout(['ok' => false, 'error' => 'Mode test/agent uniquement sur WSp'], 400);
    setcookie('OCRE_MODE_' . strtoupper($slug), $mode, [
        'expires' => time() + 86400 * 30,
        'path' => '/', 'domain' => '.ocre.immo', 'secure' => true, 'httponly' => false, 'samesite' => 'Lax',
    ]);
    jout(['ok' => true, 'workspace_slug' => $slug, 'mode' => $mode]);
}

case 'logout': {
    $token = $_SERVER['HTTP_X_SESSION_TOKEN'] ?? '';
    if ($token) pdo_meta()->prepare("DELETE FROM sessions WHERE token = ?")->execute([$token]);
    jout(['ok' => true]);
}

case 'change_password': {
    $u = current_user_or_401();
    $old = (string)($input['old_password'] ?? '');
    $new = (string)($input['new_password'] ?? '');
    if (strlen($new) < 10) jout(['ok' => false, 'error' => 'mot de passe min 10 chars'], 400);
    if (!password_verify($old, $u['password_hash'])) jout(['ok' => false, 'error' => 'ancien mdp invalide'], 401);
    $hash = password_hash($new, PASSWORD_BCRYPT, ['cost' => 10]);
    pdo_meta()->prepare("UPDATE users SET password_hash = ?, must_change_password = 0 WHERE id = ?")
        ->execute([$hash, $u['id']]);
    jout(['ok' => true]);
}

default:
    jout(['ok' => false, 'error' => 'action inconnue : ' . $action], 400);
}
