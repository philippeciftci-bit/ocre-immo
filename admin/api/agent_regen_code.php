<?php
// A2/2026-05-04 — Regenerate agent code/password (super-admin)
// POST /admin/api/agent_regen_code.php
// Body: { agent_id }
// Generate aleatoire 6 chiffres + UPDATE password_hash + must_change_password=1
// Return : { ok, new_code } pour transmission par canal securise.

require_once __DIR__ . '/_admin_lib.php';
setCorsHeaders();

$ctx = admin_require_super();
$body = json_decode(file_get_contents('php://input'), true) ?: [];
$agentId = (int) ($body['agent_id'] ?? 0);

if ($agentId <= 0) admin_jout(['ok' => false, 'error' => 'agent_id_required'], 400);

$agent = admin_get_agent($agentId);
if (!$agent) admin_jout(['ok' => false, 'error' => 'agent_not_found'], 404);
if ($agent['role'] === 'super_admin') admin_jout(['ok' => false, 'error' => 'cannot_regen_super_admin'], 403);

// Genere code 6 chiffres cryptographiquement sur (pas Math.random).
$newCode = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
$hash = password_hash($newCode, PASSWORD_DEFAULT);

$pdo = admin_meta_pdo();
$pdo->prepare("UPDATE users SET password_hash = ?, must_change_password = 1 WHERE id = ?")
    ->execute([$hash, $agentId]);

// Invalide sessions actives -> force relogin avec nouveau code
try { $pdo->prepare("DELETE FROM sessions WHERE user_id = ?")->execute([$agentId]); }
catch (Throwable $e) { /* silent */ }

admin_audit($ctx['super_uid'], 'admin_agent_regen_code', $agentId, [
    'agent_email' => $agent['email'],
    // ne PAS logger le code en clair, juste timestamp
]);

admin_jout([
    'ok' => true,
    'agent_id' => $agentId,
    'email' => $agent['email'],
    'new_code' => $newCode,
    'message' => 'Nouveau code genere. A transmettre a l\'agent par canal securise (whatsapp/sms direct, jamais email plain).',
]);
