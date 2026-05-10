<?php
// M114 — GET /api/team/list.php
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../_session.php';
require_once __DIR__ . '/_lib.php';
setCorsHeaders();
$user = getCurrentUserDualMode();
if (!$user || !empty($user['_no_tenant_user']) || !empty($user['_tenant_mismatch'])) jsonError('Non authentifie', 401);
$tenant = $user['slug'];
team_ensure_schema();
$st = team_meta_pdo()->prepare("SELECT id, user_id, email, role, joined_at, created_at, invitation_expires_at FROM auth_team_members WHERE tenant_slug=? AND removed_at IS NULL ORDER BY (role='owner') DESC, joined_at IS NULL, joined_at DESC");
$st->execute([$tenant]);
$members = $st->fetchAll();
foreach ($members as &$m) {
    $m['status'] = $m['joined_at'] ? 'active' : (strtotime($m['invitation_expires_at']) < time() ? 'expired' : 'pending');
}
jsonResponse(['ok' => true, 'tenant' => $tenant, 'members' => $members, 'my_role' => team_get_role($tenant, (int) $user['user_id'], $user['email'])]);
