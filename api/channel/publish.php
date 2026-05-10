<?php
// M104 — POST /api/channel/publish.php
// Body : {dossier_id, channels: ['leboncoin','seloger','bienici']}
// Verifie auth tenant + crée/met a jour mappings + enqueue jobs publish.

require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../_session.php';
require_once __DIR__ . '/../lib/channels/registry.php';
require_once __DIR__ . '/../lib/billing_channel.php';
require_once __DIR__ . '/../lib/permissions.php';

setCorsHeaders();
$user = getCurrentUserDualMode();
if (!$user || !empty($user['_no_tenant_user']) || !empty($user['_tenant_mismatch'])) {
    jsonError('Non authentifie', 401);
}
$tenant = $user['slug'] ?? null;
if (!$tenant) jsonError('Tenant requis', 400);
requireRole(['owner', 'manager', 'collaborator'], $user);

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') jsonError('method', 405);

// M107 — Verification abonnement Channel Premium avant push queue.
$canPub = bch_channel_can_publish($tenant);
if (!$canPub['can_publish']) {
    http_response_code(402);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode([
        'ok' => false,
        'error' => 'channel_premium_required',
        'reason' => $canPub['reason'],
        'message' => 'Channel Premium requis pour syndiquer sur les portails. Activez pour 99€/mois HT.',
        'upgrade_url' => '/reglages-abonnement.html',
    ]);
    exit;
}

channel_ensure_schema();

$data = getInput();
$dossierId = (int) ($data['dossier_id'] ?? 0);
$channels = $data['channels'] ?? [];
if ($dossierId <= 0) jsonError('dossier_id requis', 400);
if (!is_array($channels) || empty($channels)) jsonError('channels[] requis', 400);

$dossier = channel_get_dossier($tenant, $dossierId);
if (!$dossier) jsonError('Dossier introuvable', 404);

// M104 mode STUB : tous les abonnements consideres actifs si table vide.
$db = channel_meta_pdo();
$created = [];
$validation_fails = [];
foreach ($channels as $ch) {
    $driver = channel_driver($ch);
    if (!$driver) continue;

    // Validate avant queue (fail-fast UI)
    $listing = channel_dossier_to_listing($dossier);
    $val = $driver->validateListing($listing);

    // Upsert mapping
    $st = $db->prepare(
        "INSERT INTO channel_mappings (tenant_slug, dossier_id, channel_name, status)
         VALUES (?, ?, ?, ?)
         ON DUPLICATE KEY UPDATE status=VALUES(status), error_message=NULL, retry_count=0"
    );
    $st->execute([$tenant, $dossierId, $ch, $val['ok'] ? 'pending' : 'refused']);
    $mappingId = (int) $db->lastInsertId();
    if ($mappingId === 0) {
        $sel = $db->prepare("SELECT id FROM channel_mappings WHERE tenant_slug=? AND dossier_id=? AND channel_name=?");
        $sel->execute([$tenant, $dossierId, $ch]);
        $mappingId = (int) $sel->fetch()['id'];
    }

    if (!$val['ok']) {
        $err = 'Champs manquants: ' . implode(', ', $val['missing_fields']);
        $db->prepare("UPDATE channel_mappings SET error_message=? WHERE id=?")->execute([$err, $mappingId]);
        $validation_fails[] = ['channel' => $ch, 'missing_fields' => $val['missing_fields']];
        continue;
    }

    // Enqueue
    $db->prepare("INSERT INTO channel_queue (mapping_id, job_type, priority) VALUES (?, 'publish', 5)")
       ->execute([$mappingId]);
    $created[] = ['channel' => $ch, 'mapping_id' => $mappingId, 'queued' => true];
}

jsonResponse([
    'ok' => true,
    'queued' => $created,
    'validation_fails' => $validation_fails,
]);
