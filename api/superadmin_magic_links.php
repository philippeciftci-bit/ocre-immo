<?php
// M/2026/05/11/25 — Magic links monitor + revocation.
//   GET  ?action=list  → tous les magic_tokens récents (pending + consumed + expired)
//   POST ?action=revoke body {token_id} → marque used_at=NOW() (revoque)
require_once __DIR__ . '/lib/router.php';
require_once __DIR__ . '/lib/sa_audit.php';
require_once __DIR__ . '/lib/audit_logs.php';
header('Content-Type: application/json; charset=utf-8');

function ml_out(array $d, int $code = 200) { http_response_code($code); echo json_encode($d, JSON_UNESCAPED_UNICODE); exit; }

$user = current_user_or_401();
if (($user['role'] ?? '') !== 'super_admin') ml_out(['ok' => false, 'error' => 'super_admin only'], 403);

require_once __DIR__ . '/db.php';
$meta = pdo_meta();
$action = $_GET['action'] ?? 'list';

if ($action === 'list') {
    $rows = $meta->query(
        "SELECT m.id, m.user_id, u.email, LEFT(m.token, 12) AS token_short, m.created_at,
                m.used_at, m.expires_at, m.ip,
                CASE WHEN m.used_at IS NOT NULL THEN 'consumed'
                     WHEN m.expires_at < NOW() THEN 'expired'
                     ELSE 'pending' END AS state
         FROM auth_magic_tokens m LEFT JOIN auth_users u ON u.id = m.user_id
         ORDER BY m.id DESC LIMIT 200"
    )->fetchAll(PDO::FETCH_ASSOC);
    $counts = [
        'total' => (int) $meta->query("SELECT COUNT(*) FROM auth_magic_tokens")->fetchColumn(),
        'pending' => (int) $meta->query("SELECT COUNT(*) FROM auth_magic_tokens WHERE used_at IS NULL AND expires_at > NOW()")->fetchColumn(),
        'consumed' => (int) $meta->query("SELECT COUNT(*) FROM auth_magic_tokens WHERE used_at IS NOT NULL")->fetchColumn(),
        'expired' => (int) $meta->query("SELECT COUNT(*) FROM auth_magic_tokens WHERE used_at IS NULL AND expires_at < NOW()")->fetchColumn(),
    ];
    ml_out(['ok' => true, 'tokens' => $rows, 'counts' => $counts]);
}

if ($action === 'revoke' && ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    $input = json_decode(file_get_contents('php://input'), true) ?: [];
    $tokenId = (int) ($input['token_id'] ?? 0);
    if (!$tokenId) ml_out(['ok' => false, 'error' => 'missing token_id'], 400);
    $st = $meta->prepare("UPDATE auth_magic_tokens SET used_at = NOW() WHERE id = ? AND used_at IS NULL");
    $st->execute([$tokenId]);
    sa_audit_meta((int) $user['id'], 'magic_link.revoke', ['token_id' => $tokenId]);
    ml_out(['ok' => true, 'affected' => $st->rowCount()]);
}

ml_out(['ok' => false, 'error' => 'unknown action: ' . $action], 400);
