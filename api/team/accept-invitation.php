<?php
// M114 — POST /api/team/accept-invitation.php (public, lien magic invitation)
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../_session.php';
require_once __DIR__ . '/_lib.php';
setCorsHeaders();
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') jsonError('method', 405);
$d = getInput();
$token = $d['token'] ?? '';
if (!preg_match('/^[a-f0-9]{64}$/', $token)) jsonError('Token invalide', 400);
team_ensure_schema();
$st = team_meta_pdo()->prepare("SELECT id, email, tenant_slug, role, invitation_expires_at FROM auth_team_members WHERE invitation_token=? AND joined_at IS NULL LIMIT 1");
$st->execute([$token]);
$inv = $st->fetch();
if (!$inv) jsonError('Invitation introuvable', 404);
if (strtotime($inv['invitation_expires_at']) < time()) jsonError('Invitation expiree', 410);
$user = getCurrentUserDualMode();
if (!$user) jsonError('Authentification requise (login auth.ocre.immo avant accept)', 401);
if (strtolower($user['email']) !== $inv['email']) jsonError('Email auth ne correspond pas a invitation', 403);
team_meta_pdo()->prepare("UPDATE auth_team_members SET joined_at=NOW(), user_id=?, invitation_token=NULL WHERE id=?")
    ->execute([(int) $user['user_id'], (int) $inv['id']]);
jsonResponse(['ok' => true, 'tenant_slug' => $inv['tenant_slug'], 'role' => $inv['role'], 'redirect' => 'https://' . $inv['tenant_slug'] . '.ocre.immo/']);
