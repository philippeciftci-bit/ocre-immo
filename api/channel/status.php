<?php
// M104 — GET /api/channel/status.php?dossier_id=XXX

require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../_session.php';
require_once __DIR__ . '/../lib/permissions.php';
require_once __DIR__ . '/../lib/channels/registry.php';

setCorsHeaders();
$user = getCurrentUserDualMode();
if (!$user || !empty($user['_no_tenant_user']) || !empty($user['_tenant_mismatch'])) jsonError('Non authentifie', 401);
requireRole(['owner', 'manager', 'collaborator', 'viewer'], $user);
$tenant = $user['slug'];

$dossierId = (int) ($_GET['dossier_id'] ?? 0);
if ($dossierId <= 0) jsonError('dossier_id requis', 400);

channel_ensure_schema();
$db = channel_meta_pdo();
$st = $db->prepare(
    "SELECT channel_name, external_listing_id, status, error_message, last_synced_at, retry_count, views, updated_at
     FROM channel_mappings
     WHERE tenant_slug=? AND dossier_id=?
     ORDER BY channel_name"
);
$st->execute([$tenant, $dossierId]);
$mappings = $st->fetchAll();

jsonResponse(['ok' => true, 'dossier_id' => $dossierId, 'mappings' => $mappings]);
