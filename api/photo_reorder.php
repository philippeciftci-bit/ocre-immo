<?php
// M/2026/05/04/30 — M30 photo reorder endpoint. POST {dossier_id, photos_uuids:[...]}.
// Validation : meme set d'UUIDs que celui actuel, juste ordre change (pas d'ajout/retrait).
// UPDATE clients.photos_uuids JSON.
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/lib/router.php';
setCorsHeaders();
header('Content-Type: application/json; charset=utf-8');

function jout($d, $code = 200) { http_response_code($code); echo json_encode($d, JSON_UNESCAPED_UNICODE); exit; }

$ctx = resolve_workspace_context();
require_write_access($ctx);

$body = json_decode(file_get_contents('php://input'), true) ?: [];
$dossierId = (int) ($body['dossier_id'] ?? 0);
$newOrder = $body['photos_uuids'] ?? null;
if (!$dossierId || !is_array($newOrder)) jout(['ok' => false, 'error' => 'invalid_input'], 400);

// Validate each UUID format (32 hex chars).
$cleanNew = [];
foreach ($newOrder as $u) {
    $clean = preg_replace('/[^a-f0-9]/', '', (string)$u);
    if (strlen($clean) !== 32) jout(['ok' => false, 'error' => 'uuid_format_invalid'], 400);
    $cleanNew[] = $clean;
}

$pdoTenant = pdo_workspace($ctx['db_name']);
$st = $pdoTenant->prepare("SELECT photos_uuids FROM clients WHERE id = ? AND deleted_at IS NULL LIMIT 1");
$st->execute([$dossierId]);
$row = $st->fetch();
if (!$row) jout(['ok' => false, 'error' => 'dossier_introuvable'], 404);
$current = [];
if (!empty($row['photos_uuids'])) {
    $tmp = json_decode($row['photos_uuids'], true);
    if (is_array($tmp)) $current = $tmp;
}
sort($current); $sortedNew = $cleanNew; sort($sortedNew);
if ($current !== $sortedNew) jout(['ok' => false, 'error' => 'set_mismatch', 'detail' => 'reorder set differs from current set'], 400);

$pdoTenant->prepare("UPDATE clients SET photos_uuids = ? WHERE id = ?")
    ->execute([json_encode(array_values($cleanNew), JSON_UNESCAPED_UNICODE), $dossierId]);
jout(['ok' => true, 'dossier_id' => $dossierId, 'count' => count($cleanNew)]);
