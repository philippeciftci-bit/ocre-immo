<?php
// M/2026/05/04/28 — M28 photo serve endpoint. GET ?u=<uuid>&d=<dossier_id>.
// Auth + workspace check. Sert /var/lib/ocre/uploads/<wsp>/<id>/<uuid>.webp.
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/lib/router.php';

const PHOTO_BASE_DIR = '/var/lib/ocre/uploads';

$uuid = preg_replace('/[^a-f0-9]/', '', (string) ($_GET['u'] ?? ''));
$dossierId = (int) ($_GET['d'] ?? 0);
if (strlen($uuid) !== 32 || !$dossierId) { http_response_code(400); echo 'bad_request'; exit; }

try {
    $ctx = resolve_workspace_context();
} catch (Throwable $e) { http_response_code(401); echo 'auth_required'; exit; }

// Verifie acces dossier dans le workspace courant.
$pdoTenant = pdo_workspace($ctx['db_name']);
$st = $pdoTenant->prepare("SELECT photos_uuids FROM clients WHERE id = ? AND deleted_at IS NULL LIMIT 1");
$st->execute([$dossierId]);
$row = $st->fetch();
if (!$row) { http_response_code(404); echo 'not_found'; exit; }
$uuids = [];
if (!empty($row['photos_uuids'])) {
    $tmp = json_decode($row['photos_uuids'], true);
    if (is_array($tmp)) $uuids = $tmp;
}
if (!in_array($uuid, $uuids, true)) { http_response_code(403); echo 'forbidden'; exit; }

$wspSlug = preg_replace('/[^a-z0-9_-]/', '', $ctx['workspace']['slug'] ?? 'unknown');
$path = PHOTO_BASE_DIR . '/' . $wspSlug . '/' . $dossierId . '/' . $uuid . '.webp';
if (!is_file($path)) { http_response_code(404); echo 'file_not_found'; exit; }

header('Content-Type: image/webp');
header('Cache-Control: private, max-age=86400');
header('X-Content-Type-Options: nosniff');
header('Content-Length: ' . filesize($path));
readfile($path);
