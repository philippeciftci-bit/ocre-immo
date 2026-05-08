<?php
// M/2026/05/08/37 — Super-admin endpoint sessions actives.
// GET ?action=list                       → liste sessions non-expirées avec user/IP/UA
// POST {action:force_logout, session_id} → DELETE session (déconnecte le user)
// POST {action:logout_all_except_me}     → DELETE toutes sessions sauf la courante (super-admin)

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
    $action = $_GET['action'] ?? 'list';
    if ($action === 'list') {
        // M/2026/05/08/40 — table sessions n'a pas de colonne id, PK=token. On utilise token comme identifiant.
        $sql = "SELECT s.token, s.user_id, s.expires_at, s.ip, s.user_agent,
                       s.created_at, u.email, u.role, u.slug AS user_slug, u.display_name
                FROM sessions s
                LEFT JOIN users u ON u.id = s.user_id
                WHERE s.expires_at > NOW()
                ORDER BY s.created_at DESC LIMIT 500";
        $rows = $meta->query($sql)->fetchAll();
        $list = [];
        foreach ($rows as $r) {
            $list[] = [
                // token affiché en abrégé côté JSON (les 12 premiers chars suffisent comme identifiant lisible).
                'token_prefix' => substr((string)$r['token'], 0, 12),
                'token' => (string)$r['token'], // référence complète pour force_logout
                'user_id' => (int)$r['user_id'],
                'email' => (string)($r['email'] ?? '—'),
                'role' => (string)($r['role'] ?? '—'),
                'workspace_slug' => (string)($r['user_slug'] ?? '—'),
                'display_name' => (string)($r['display_name'] ?? '—'),
                'ip' => (string)$r['ip'],
                'user_agent' => substr((string)$r['user_agent'], 0, 80),
                'created_at' => (string)$r['created_at'],
                'expires_at' => (string)$r['expires_at'],
                'is_self' => ((int)$r['user_id'] === (int)$user['id']),
            ];
        }
        jout(['ok' => true, 'count' => count($list), 'sessions' => $list]);
    }
    jout(['ok' => false, 'error' => 'unknown GET action'], 400);
}

if ($method !== 'POST') jout(['ok' => false, 'error' => 'method not allowed'], 405);

$raw = file_get_contents('php://input');
$input = is_array(($j = json_decode($raw, true))) ? $j : [];
$action = (string)($input['action'] ?? '');

if ($action === 'force_logout') {
    // M/2026/05/08/40 — clé sessions = token (pas id). Accepte token complet ou (legacy) session_id ignoré.
    $sToken = (string)($input['session_token'] ?? '');
    if ($sToken === '' || !preg_match('/^[a-f0-9]{32,128}$/', $sToken)) jout(['ok' => false, 'error' => 'session_token required (hex 32-128)'], 400);
    $st = $meta->prepare("SELECT user_id FROM sessions WHERE token = ?");
    $st->execute([$sToken]);
    $row = $st->fetch();
    if (!$row) jout(['ok' => false, 'error' => 'session not found'], 404);
    if ((int)$row['user_id'] === (int)$user['id']) jout(['ok' => false, 'error' => 'cannot logout self'], 409);
    $del = $meta->prepare("DELETE FROM sessions WHERE token = ?");
    $del->execute([$sToken]);
    @file_put_contents($LOG, "[" . date('c') . "] sa#" . $user['id'] . " force_logout token=" . substr($sToken,0,12) . "... user_id=" . $row['user_id'] . "\n", FILE_APPEND);
    @shell_exec('/root/bin/notify --project ocre --priority warning --phase warn --mission-id ' . escapeshellarg('SUPERADMIN-SESSIONS/' . time()) . ' --title ' . escapeshellarg('[OCRE] Force logout session') . ' --body ' . escapeshellarg("token_prefix=" . substr($sToken,0,12) . " user_id=" . $row['user_id'] . " by sa=" . $user['email']) . ' >/dev/null 2>&1 &');
    jout(['ok' => true, 'deleted' => $del->rowCount()]);
}

if ($action === 'logout_all_except_me') {
    $del = $meta->prepare("DELETE FROM sessions WHERE user_id != ?");
    $del->execute([(int)$user['id']]);
    $n = $del->rowCount();
    @file_put_contents($LOG, "[" . date('c') . "] sa#" . $user['id'] . " logout_all_except_me deleted=$n\n", FILE_APPEND);
    @shell_exec('/root/bin/notify --project ocre --priority high --phase warn --mission-id ' . escapeshellarg('SUPERADMIN-SESSIONS/' . time()) . ' --title ' . escapeshellarg('[OCRE] Logout all sessions') . ' --body ' . escapeshellarg("$n sessions deconnectees by sa=" . $user['email']) . ' >/dev/null 2>&1 &');
    jout(['ok' => true, 'deleted' => $n]);
}

jout(['ok' => false, 'error' => 'unknown action: ' . $action], 400);
