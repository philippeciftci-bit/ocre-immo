<?php
// M/2026/04/28/46 — Recycle bin admin (soft-delete dossiers).
// Soft-delete : clients.deleted_at IS NOT NULL (colonne pré-existante depuis V50).
// Endpoint super_admin : list / restore / purge (DELETE physique).
require_once __DIR__ . '/../db.php';
setCorsHeaders();

$user = requireAuth();
$isSuper = ($user['role'] ?? '') === 'super_admin' || ($user['_origin_role'] ?? '') === 'super_admin';
if (!$isSuper) jsonError('Accès refusé (super_admin requis)', 403);

$action = $_GET['action'] ?? ($_POST['action'] ?? '');
$input = getInput();
$uid = (int) ($user['_origin_user_id'] ?? $user['id']);

function ensureDeletedBy(): void {
    static $done = false;
    if ($done) return;
    try { db()->exec("ALTER TABLE clients ADD COLUMN deleted_by INT UNSIGNED NULL"); } catch (Throwable $e) {}
    try { db()->exec("ALTER TABLE clients ADD INDEX idx_deleted_at (deleted_at)"); } catch (Throwable $e) {}
    $done = true;
}
ensureDeletedBy();

switch ($action) {

case 'list': {
    $stmt = db()->query(
        "SELECT id, prenom, nom, societe_nom, projet, deleted_at, deleted_by, updated_at
         FROM clients WHERE deleted_at IS NOT NULL
         ORDER BY deleted_at DESC LIMIT 500"
    );
    jsonOk(['deleted' => $stmt->fetchAll()]);
}

case 'restore': {
    $id = (int) ($input['client_id'] ?? $_GET['client_id'] ?? 0);
    if (!$id) jsonError('client_id requis', 400);
    db()->prepare("UPDATE clients SET deleted_at = NULL, deleted_by = NULL WHERE id = ?")->execute([$id]);
    jsonOk(['restored_id' => $id]);
}

case 'purge': {
    $id = (int) ($input['client_id'] ?? 0);
    if (!$id) jsonError('client_id requis', 400);
    if (empty($input['confirm']) || $input['confirm'] !== 'YES_DELETE_PHYSICALLY') {
        jsonError("Purge bloquée. Re-poster avec body { confirm: 'YES_DELETE_PHYSICALLY' }", 400);
    }
    db()->prepare("DELETE FROM clients WHERE id = ? AND deleted_at IS NOT NULL")->execute([$id]);
    jsonOk(['purged_id' => $id]);
}

default:
    jsonError('Action inconnue (list | restore | purge)', 400);
}
