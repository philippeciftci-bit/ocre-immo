<?php
// M114 — POST /api/team/change-role.php (owner only)
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../_session.php';
require_once __DIR__ . '/_lib.php';
setCorsHeaders();
$user = getCurrentUserDualMode();
if (!$user || !empty($user['_no_tenant_user']) || !empty($user['_tenant_mismatch'])) jsonError('Non authentifie', 401);
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') jsonError('method', 405);
$tenant = $user['slug'];
team_require_role($tenant, (int) $user['user_id'], $user['email'], ['owner']);
$d = getInput();
$memberId = (int) ($d['member_id'] ?? 0);
$newRole = $d['new_role'] ?? '';
if (!$memberId || !in_array($newRole, TEAM_ROLES, true)) jsonError('member_id + new_role requis', 400);
if ($newRole === 'owner') jsonError('Promotion owner = transfert ownership separe (non supporte V1)', 400);
team_meta_pdo()->prepare("UPDATE auth_team_members SET role=? WHERE id=? AND tenant_slug=? AND removed_at IS NULL AND role!='owner'")
    ->execute([$newRole, $memberId, $tenant]);
jsonResponse(['ok' => true, 'member_id' => $memberId, 'new_role' => $newRole]);
