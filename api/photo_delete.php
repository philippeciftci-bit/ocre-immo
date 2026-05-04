<?php
// M/2026/05/04/28 — M28 photo delete endpoint. POST {dossier_id, uuid}.
// Auth + workspace check + unlink + retire UUID du JSON array.
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/lib/router.php';
setCorsHeaders();
header('Content-Type: application/json; charset=utf-8');

const PHOTO_BASE_DIR = '/var/lib/ocre/uploads';

function jout($d, $code = 200) { http_response_code($code); echo json_encode($d, JSON_UNESCAPED_UNICODE); exit; }

$ctx = resolve_workspace_context();
require_write_access($ctx);

$body = json_decode(file_get_contents('php://input'), true) ?: [];
$uuid = preg_replace('/[^a-f0-9]/', '', (string) ($body['uuid'] ?? ''));
$dossierId = (int) ($body['dossier_id'] ?? 0);
if (strlen($uuid) !== 32 || !$dossierId) jout(['ok' => false, 'error' => 'invalid_input'], 400);

$pdoTenant = pdo_workspace($ctx['db_name']);
$st = $pdoTenant->prepare("SELECT photos_uuids FROM clients WHERE id = ? AND deleted_at IS NULL LIMIT 1");
$st->execute([$dossierId]);
$row = $st->fetch();
if (!$row) jout(['ok' => false, 'error' => 'dossier_introuvable'], 404);
$uuids = [];
if (!empty($row['photos_uuids'])) {
    $tmp = json_decode($row['photos_uuids'], true);
    if (is_array($tmp)) $uuids = $tmp;
}
if (!in_array($uuid, $uuids, true)) jout(['ok' => false, 'error' => 'uuid_not_found'], 404);

$uuids = array_values(array_filter($uuids, fn($u) => $u !== $uuid));
$pdoTenant->prepare("UPDATE clients SET photos_uuids = ? WHERE id = ?")
    ->execute([json_encode(array_values($uuids), JSON_UNESCAPED_UNICODE), $dossierId]);

$wspSlug = preg_replace('/[^a-z0-9_-]/', '', $ctx['workspace']['slug'] ?? 'unknown');
$path = PHOTO_BASE_DIR . '/' . $wspSlug . '/' . $dossierId . '/' . $uuid . '.webp';
if (is_file($path)) @unlink($path);

jout(['ok' => true, 'uuid' => $uuid, 'remaining' => count($uuids)]);
