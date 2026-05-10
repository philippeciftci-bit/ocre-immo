<?php
// M114 — POST /api/team/invite.php (owner/manager only)
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../_session.php';
require_once __DIR__ . '/_lib.php';
setCorsHeaders();
$user = getCurrentUserDualMode();
if (!$user || !empty($user['_no_tenant_user']) || !empty($user['_tenant_mismatch'])) jsonError('Non authentifie', 401);
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') jsonError('method', 405);
$tenant = $user['slug']; $userId = (int) $user['user_id']; $email = $user['email'];
team_require_role($tenant, $userId, $email, ['owner', 'manager']);
$d = getInput();
$inviteEmail = strtolower(trim($d['email'] ?? ''));
$role = $d['role'] ?? 'collaborator';
if (!filter_var($inviteEmail, FILTER_VALIDATE_EMAIL)) jsonError('Email invalide', 400);
if (!in_array($role, TEAM_ROLES, true)) jsonError('Role invalide', 400);
if ($role === 'owner') jsonError('Owner ne peut etre invite (transfert ownership separe)', 400);
$token = bin2hex(random_bytes(32));
team_meta_pdo()->prepare(
    "INSERT INTO auth_team_members (email, tenant_slug, role, invited_by_user_id, invitation_token, invitation_expires_at)
     VALUES (?, ?, ?, ?, ?, DATE_ADD(NOW(), INTERVAL 7 DAY))
     ON DUPLICATE KEY UPDATE role=VALUES(role), invitation_token=VALUES(invitation_token), invitation_expires_at=VALUES(invitation_expires_at), removed_at=NULL"
)->execute([$inviteEmail, $tenant, $role, $userId, $token]);
$inviteUrl = 'https://auth.ocre.immo/?invite_token=' . urlencode($token);
// TODO email send via OVH SMTP (M97 helper)
@error_log("[team/invite] tenant=$tenant invited=$inviteEmail role=$role url=$inviteUrl");
jsonResponse(['ok' => true, 'invitation_token' => $token, 'invite_url' => $inviteUrl, 'role' => $role]);
