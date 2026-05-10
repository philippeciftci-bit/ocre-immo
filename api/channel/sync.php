<?php
// M104 — POST /api/channel/sync.php {dossier_id}
// Enqueue update job pour tous les mappings actifs (status='published').

require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../_session.php';
require_once __DIR__ . '/../lib/permissions.php';
require_once __DIR__ . '/../lib/channels/registry.php';

setCorsHeaders();
$user = getCurrentUserDualMode();
if (!$user || !empty($user['_no_tenant_user']) || !empty($user['_tenant_mismatch'])) jsonError('Non authentifie', 401);
requireRole(['owner', 'manager', 'collaborator'], $user);
$tenant = $user['slug'];

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') jsonError('method', 405);
$d = getInput();
$dossierId = (int) ($d['dossier_id'] ?? 0);
if ($dossierId <= 0) jsonError('dossier_id requis', 400);

channel_ensure_schema();
$db = channel_meta_pdo();
$st = $db->prepare("SELECT id FROM channel_mappings WHERE tenant_slug=? AND dossier_id=? AND status IN ('published','syncing','pending')");
$st->execute([$tenant, $dossierId]);
$queued = 0;
foreach ($st->fetchAll() as $r) {
    $db->prepare("INSERT INTO channel_queue (mapping_id, job_type, priority) VALUES (?, 'update', 4)")->execute([(int) $r['id']]);
    $queued++;
}

jsonResponse(['ok' => true, 'queued' => $queued]);
