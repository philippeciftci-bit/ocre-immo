<?php
// A2/2026-05-04 — Suspend / unsuspend agent (super-admin)
// POST /admin/api/agent_suspend.php
// Body: { agent_id, action: 'suspend'|'unsuspend' }

require_once __DIR__ . '/_admin_lib.php';
setCorsHeaders();

$ctx = admin_require_super();
$body = json_decode(file_get_contents('php://input'), true) ?: [];
$agentId = (int) ($body['agent_id'] ?? 0);
$action = $body['action'] ?? '';

if ($agentId <= 0) admin_jout(['ok' => false, 'error' => 'agent_id_required'], 400);
if (!in_array($action, ['suspend', 'unsuspend'], true)) admin_jout(['ok' => false, 'error' => 'invalid_action'], 400);

$agent = admin_get_agent($agentId);
if (!$agent) admin_jout(['ok' => false, 'error' => 'agent_not_found'], 404);
if ($agent['role'] === 'super_admin') admin_jout(['ok' => false, 'error' => 'cannot_suspend_super_admin'], 403);
if ($agentId === $ctx['super_uid']) admin_jout(['ok' => false, 'error' => 'cannot_suspend_self'], 403);

$pdo = admin_meta_pdo();
$newStatus = ($action === 'suspend') ? 'suspended' : 'active';
$newSuspended = ($action === 'suspend') ? 1 : 0;

$st = $pdo->prepare("UPDATE users SET status = ?, is_suspended = ? WHERE id = ?");
$st->execute([$newStatus, $newSuspended, $agentId]);

// Invalide les sessions actives si suspension
if ($action === 'suspend') {
    try { $pdo->prepare("DELETE FROM sessions WHERE user_id = ?")->execute([$agentId]); }
    catch (Throwable $e) { /* silent */ }
}

admin_audit($ctx['super_uid'], 'admin_agent_' . $action, $agentId, [
    'agent_email' => $agent['email'],
    'previous_status' => $agent['status'],
    'new_status' => $newStatus,
]);

admin_jout([
    'ok' => true,
    'agent_id' => $agentId,
    'email' => $agent['email'],
    'new_status' => $newStatus,
]);
