<?php
// A2/2026-05-04 — Soft delete agent (super-admin)
// POST /admin/api/agent_delete.php
// DESTRUCTIF — double confirmation cote frontend ET re-saisie code admin cote serveur.
// Body: { agent_id, confirm: 'DELETE_AGENT_<email>', admin_code: 'OCRE-ADMIN-2026' }
// Action: UPDATE users SET status='deleted', deleted_at=NOW(). PAS de DROP DATABASE.

require_once __DIR__ . '/_admin_lib.php';
setCorsHeaders();

const ADMIN_CONFIRM_CODE = 'OCRE-ADMIN-2026';

$ctx = admin_require_super();
$body = json_decode(file_get_contents('php://input'), true) ?: [];
$agentId = (int) ($body['agent_id'] ?? 0);
$confirm = (string) ($body['confirm'] ?? '');
$adminCode = (string) ($body['admin_code'] ?? '');

if ($agentId <= 0) admin_jout(['ok' => false, 'error' => 'agent_id_required'], 400);
if (!hash_equals(ADMIN_CONFIRM_CODE, $adminCode)) admin_jout(['ok' => false, 'error' => 'admin_code_invalide'], 403);

$agent = admin_get_agent($agentId);
if (!$agent) admin_jout(['ok' => false, 'error' => 'agent_not_found'], 404);
if ($agent['role'] === 'super_admin') admin_jout(['ok' => false, 'error' => 'cannot_delete_super_admin'], 403);
if ($agentId === $ctx['super_uid']) admin_jout(['ok' => false, 'error' => 'cannot_delete_self'], 403);

$expectedConfirm = 'DELETE_AGENT_' . $agent['email'];
if (!hash_equals($expectedConfirm, $confirm)) {
    admin_jout(['ok' => false, 'error' => 'confirm_string_mismatch', 'expected' => $expectedConfirm], 400);
}

$pdo = admin_meta_pdo();

// Soft delete : status=deleted + deletion_requested_at=NOW + invalidation sessions.
// PAS de DROP DATABASE. PAS de TRUNCATE. La DB workspace reste intacte (recuperable via backup).
$pdo->beginTransaction();
try {
    $pdo->prepare(
        "UPDATE users
         SET status = 'deleted',
             is_suspended = 1,
             deletion_requested_at = NOW()
         WHERE id = ?"
    )->execute([$agentId]);

    try { $pdo->prepare("DELETE FROM sessions WHERE user_id = ?")->execute([$agentId]); }
    catch (Throwable $e) { /* silent */ }

    $pdo->commit();
} catch (Throwable $e) {
    $pdo->rollBack();
    admin_jout(['ok' => false, 'error' => 'soft_delete_failed', 'detail' => $e->getMessage()], 500);
}

admin_audit($ctx['super_uid'], 'admin_agent_soft_delete', $agentId, [
    'agent_email' => $agent['email'],
    'agent_slug' => $agent['slug'],
    'previous_status' => $agent['status'],
]);

admin_jout([
    'ok' => true,
    'agent_id' => $agentId,
    'email' => $agent['email'],
    'message' => 'Agent supprime (soft-delete). DB workspace intacte. Recuperable via backup si besoin.',
]);
