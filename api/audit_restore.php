<?php
// V52 — Restauration granulaire depuis un audit_log.
// Scope : l'utilisateur ne peut restaurer que ses propres records (vérif via clients.user_id).
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/_audit.php';
setCorsHeaders();
$user = requireAuth();
auditEnsureSchema();

$input = getInput();
$audit_id = (int)($input['audit_id'] ?? 0);
if (!$audit_id) jsonError('audit_id requis');

$pdo = db();
$stmt = $pdo->prepare("SELECT * FROM audit_log WHERE id = ? LIMIT 1");
$stmt->execute([$audit_id]);
$a = $stmt->fetch();
if (!$a) jsonError('audit introuvable', 404);

$is_admin = !empty($user['is_admin']) || ($user['role'] ?? '') === 'admin';
if (!$is_admin && (int)$a['user_id'] !== (int)$user['id']) jsonError('Accès refusé', 403);
// Scope complémentaire : le record doit appartenir à l'utilisateur (pour clients).
$table = (string)$a['table_name'];
$rec_id = (int)$a['record_id'];
if ($table === 'clients' && !$is_admin) {
    $c = $pdo->prepare("SELECT user_id FROM clients WHERE id = ?");
    $c->execute([$rec_id]);
    $row = $c->fetch();
    if ($row && (int)$row['user_id'] !== (int)$user['id']) jsonError('Accès refusé (record)', 403);
}

$before = $a['before_state'] ? json_decode($a['before_state'], true) : null;
$after  = $a['after_state']  ? json_decode($a['after_state'],  true) : null;
$action = (string)$a['action'];

$restored = false;
if ($action === 'DELETE' && $before && $table === 'clients') {
    // Réactiver : deleted_at = NULL. Optionnellement restaurer l'état data JSON du before.
    $pdo->prepare("UPDATE clients SET deleted_at = NULL WHERE id = ?")->execute([$rec_id]);
    audit_log((int)$user['id'], $table, $rec_id, 'RESTORE', null, ['source_audit_id' => $audit_id]);
    $restored = true;
} elseif ($action === 'UPDATE' && $before && $table === 'clients') {
    // Ré-écrit le JSON data + champs plats depuis $before (best-effort).
    $fields = ['data', 'projet', 'is_investisseur', 'archived', 'is_draft', 'prenom', 'nom', 'societe_nom', 'tel', 'email', 'payment_plan', 'received_payments'];
    $set = []; $vals = [];
    foreach ($fields as $f) {
        if (array_key_exists($f, $before)) { $set[] = "$f = ?"; $vals[] = $before[$f]; }
    }
    if (!$set) jsonError('before vide, restore impossible');
    $vals[] = $rec_id;
    $sql = "UPDATE clients SET " . implode(', ', $set) . ", updated_at = NOW() WHERE id = ?";
    $pdo->prepare($sql)->execute($vals);
    audit_log((int)$user['id'], $table, $rec_id, 'RESTORE', null, ['source_audit_id' => $audit_id]);
    $restored = true;
} elseif ($action === 'INSERT' && $table === 'clients') {
    // Soft-delete du record (rollback d'un INSERT).
    $pdo->prepare("UPDATE clients SET deleted_at = NOW() WHERE id = ? AND deleted_at IS NULL")->execute([$rec_id]);
    audit_log((int)$user['id'], $table, $rec_id, 'RESTORE', null, ['source_audit_id' => $audit_id, 'note' => 'INSERT rollback → soft-delete']);
    $restored = true;
}
if (!$restored) jsonError("action $action / table $table non restaurable", 400);
jsonOk(['restored' => true, 'table' => $table, 'record_id' => $rec_id, 'via_audit' => $audit_id]);
