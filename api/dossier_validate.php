<?php
// M/2026/05/04/24 — Endpoint POST validation finale d'un dossier (lock edition).
// Auth session + workspace context (resolve via X-Tenant-Slug ou subdomain).
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/lib/router.php';
setCorsHeaders();
header('Content-Type: application/json; charset=utf-8');

$ctx = resolve_workspace_context();
require_write_access($ctx);

$input = json_decode(file_get_contents('php://input'), true) ?: [];
$dossierId = (int) ($input['dossier_id'] ?? 0);
if (!$dossierId) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'dossier_id requis']);
    exit;
}

$pdoTenant = pdo_workspace($ctx['db_name']);
// Verifie etat actuel.
$st = $pdoTenant->prepare("SELECT id, status_final, prenom, nom FROM clients WHERE id = ? AND deleted_at IS NULL LIMIT 1");
$st->execute([$dossierId]);
$row = $st->fetch();
if (!$row) {
    http_response_code(404);
    echo json_encode(['ok' => false, 'error' => 'dossier_introuvable']);
    exit;
}
if (($row['status_final'] ?? 'brouillon') === 'valide') {
    echo json_encode(['ok' => false, 'error' => 'already_validated']);
    exit;
}

$uid = (int) $ctx['user']['id'];
$upd = $pdoTenant->prepare("UPDATE clients SET status_final = 'valide', validated_at = NOW(), validated_by = ? WHERE id = ? AND status_final != 'valide'");
$upd->execute([$uid, $dossierId]);
if ($upd->rowCount() === 0) {
    echo json_encode(['ok' => false, 'error' => 'update_failed']);
    exit;
}

// Re-fetch pour retour validated_at + nom valideur (depuis ocre_meta.users).
$st2 = $pdoTenant->prepare("SELECT validated_at FROM clients WHERE id = ? LIMIT 1");
$st2->execute([$dossierId]);
$validatedAt = $st2->fetchColumn() ?: gmdate('Y-m-d H:i:s');

$displayName = '';
try {
    $stMeta = pdo_meta()->prepare("SELECT display_name, email FROM users WHERE id = ? LIMIT 1");
    $stMeta->execute([$uid]);
    $u = $stMeta->fetch();
    $displayName = trim($u['display_name'] ?? $u['email'] ?? '');
} catch (Throwable $e) {}

echo json_encode([
    'ok' => true,
    'dossier_id' => $dossierId,
    'validated_at' => $validatedAt,
    'validated_by' => $uid,
    'validated_by_name' => $displayName,
]);
