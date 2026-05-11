<?php
// GET /api/auth.php → vue auth & sécurité.
// POST /api/auth.php → action: kill_all_sessions | revoke_jti
require_once __DIR__ . '/_lib.php';
sa_cors();
$admin = sa_require_super_admin();
$db = auth_db();

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $data = json_decode(file_get_contents('php://input'), true) ?: [];
    $action = (string)($data['action'] ?? '');
    if ($action === 'kill_all_sessions') {
        $n = $db->exec("UPDATE auth_sessions SET revoked_at=NOW() WHERE revoked_at IS NULL");
        sa_audit((int)$admin['id'], 'auth.kill_all_sessions', null, ['affected' => $n]);
        sa_send_json(['ok' => true, 'affected' => $n]);
    }
    if ($action === 'revoke_jti') {
        $jti = (string)($data['jti'] ?? '');
        if (!$jti) sa_send_json(['ok' => false, 'error' => 'missing_jti'], 400);
        $st = $db->prepare("UPDATE auth_sessions SET revoked_at=NOW() WHERE jti=? AND revoked_at IS NULL");
        $st->execute([$jti]);
        sa_audit((int)$admin['id'], 'auth.revoke_jti', $jti);
        sa_send_json(['ok' => true, 'affected' => $st->rowCount()]);
    }
    sa_send_json(['ok' => false, 'error' => 'unknown_action'], 400);
}

// Sessions actives (top 50 récentes)
$sessions = $db->query(
    "SELECT s.id, s.jti, s.user_id, u.email, s.user_agent, s.ip, s.expires_at, s.created_at
     FROM auth_sessions s
     LEFT JOIN auth_users u ON u.id=s.user_id
     WHERE s.revoked_at IS NULL AND s.expires_at > NOW()
     ORDER BY s.created_at DESC LIMIT 50"
)->fetchAll();

// Magic links pending (non consommés non expirés, top 30)
$magic_pending = $db->query(
    "SELECT m.id, m.user_id, u.email, LEFT(m.token, 12) AS token_short, m.expires_at, m.ip
     FROM auth_magic_tokens m
     LEFT JOIN auth_users u ON u.id=m.user_id
     WHERE m.used_at IS NULL AND m.expires_at > NOW()
     ORDER BY m.id DESC LIMIT 30"
)->fetchAll();

// Magic links récents (50 derniers tous statuts)
$magic_recent = $db->query(
    "SELECT m.id, m.user_id, u.email, m.used_at, m.expires_at, m.created_at, m.ip,
            CASE WHEN m.used_at IS NOT NULL THEN 'consumed'
                 WHEN m.expires_at < NOW() THEN 'expired'
                 ELSE 'pending' END AS state
     FROM auth_magic_tokens m
     LEFT JOIN auth_users u ON u.id=m.user_id
     ORDER BY m.id DESC LIMIT 50"
)->fetchAll();

// Échecs login (depuis log error_log si dispo) — simplifie : table absent → vide
$login_failures = [];
try {
    $login_failures = $db->query(
        "SELECT ip, COUNT(*) AS attempts, MAX(created_at) AS last_seen
         FROM auth_rate_limits
         WHERE action='login_fail' AND created_at > DATE_SUB(NOW(), INTERVAL 1 HOUR)
         GROUP BY ip
         HAVING attempts >= 5
         ORDER BY attempts DESC LIMIT 20"
    )->fetchAll();
} catch (Throwable $e) { /* table absent */ }

$totals = [
    'sessions_active' => (int) $db->query("SELECT COUNT(*) FROM auth_sessions WHERE revoked_at IS NULL AND expires_at > NOW()")->fetchColumn(),
    'magic_pending' => count($magic_pending),
    'sessions_revoked_24h' => (int) $db->query("SELECT COUNT(*) FROM auth_sessions WHERE revoked_at > DATE_SUB(NOW(), INTERVAL 1 DAY)")->fetchColumn(),
];

sa_send_json([
    'ok' => true,
    'sessions' => $sessions,
    'magic_pending' => $magic_pending,
    'magic_recent' => $magic_recent,
    'login_failures' => $login_failures,
    'totals' => $totals,
]);
