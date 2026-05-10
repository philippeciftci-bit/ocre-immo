<?php
// M104 — POST /api/channel/unpublish.php {dossier_id, channel}

require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../_session.php';
require_once __DIR__ . '/../lib/channels/registry.php';

setCorsHeaders();
$user = getCurrentUserDualMode();
if (!$user || !empty($user['_no_tenant_user']) || !empty($user['_tenant_mismatch'])) jsonError('Non authentifie', 401);
$tenant = $user['slug'];

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') jsonError('method', 405);
$d = getInput();
$dossierId = (int) ($d['dossier_id'] ?? 0);
$channel = $d['channel'] ?? '';
if ($dossierId <= 0 || !$channel) jsonError('dossier_id + channel requis', 400);

channel_ensure_schema();
$db = channel_meta_pdo();
$sel = $db->prepare("SELECT id, status FROM channel_mappings WHERE tenant_slug=? AND dossier_id=? AND channel_name=? LIMIT 1");
$sel->execute([$tenant, $dossierId, $channel]);
$row = $sel->fetch();
if (!$row) jsonError('Mapping introuvable', 404);
$mappingId = (int) $row['id'];

$db->prepare("INSERT INTO channel_queue (mapping_id, job_type, priority) VALUES (?, 'delete', 3)")
   ->execute([$mappingId]);

jsonResponse(['ok' => true, 'mapping_id' => $mappingId, 'queued' => true]);
