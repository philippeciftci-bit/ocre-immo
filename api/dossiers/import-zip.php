<?php
// M_OCRE_V1832_ZIP — POST /api/dossiers/import-zip.php
// Body multipart : file (ZIP genere par export-zip.php) → cree dossier dans tenant courant + copie photos
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../_session.php';
setCorsHeaders();

$user = requireAuth();
$uid = (int) $user['id'];

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') jsonError('method', 405);
if (empty($_FILES['file']) || ($_FILES['file']['error'] ?? 0) !== UPLOAD_ERR_OK) jsonError('Aucun ZIP fourni', 400);
$f = $_FILES['file'];
if ($f['size'] > 50 * 1024 * 1024) jsonError('ZIP trop volumineux (max 50 Mo)', 400);

$tmpZip = $f['tmp_name'];
$zip = new ZipArchive();
if ($zip->open($tmpZip) !== true) jsonError('ZIP invalide ou corrompu', 400);

// Extraction temp
$extractDir = sys_get_temp_dir() . '/ocrezip_import_' . bin2hex(random_bytes(6));
@mkdir($extractDir, 0755, true);
if (!$zip->extractTo($extractDir)) { $zip->close(); jsonError('Extraction echouee', 500); }
$zip->close();

// Parse data.json
$dataPath = $extractDir . '/data.json';
if (!is_file($dataPath)) jsonError('data.json manquant dans le ZIP', 400);
$payload = json_decode((string) @file_get_contents($dataPath), true);
if (!is_array($payload) || empty($payload['ocre_version'])) jsonError('Format ZIP non reconnu (signature ocre_version manquante)', 400);

// Compatibilite version : on accepte 18.x
if (substr((string)$payload['ocre_version'], 0, 3) !== '18.') jsonError('Version ZIP incompatible : ' . $payload['ocre_version'], 400);

$data = $payload['data'] ?? [];
$projet = $payload['projet'] ?? 'acheteur';
$is_inv = (int) ($payload['is_investisseur'] ?? 0);

// INSERT clients
$st = db()->prepare("INSERT INTO clients (user_id, data, projet, is_investisseur, archived, created_at) VALUES (?, ?, ?, ?, 0, NOW())");
$st->execute([$uid, json_encode($data, JSON_UNESCAPED_UNICODE), $projet, $is_inv]);
$newId = (int) db()->lastInsertId();

// Copie photos
$photoCount = 0;
$photosDir = $extractDir . '/photos';
$destDir = '/opt/ocre-app/uploads/' . $newId;
if (is_dir($photosDir) && is_dir(dirname($destDir))) {
    @mkdir($destDir, 0755, true);
    foreach (scandir($photosDir) ?: [] as $f2) {
        if ($f2 === '.' || $f2 === '..') continue;
        $src = $photosDir . '/' . $f2;
        if (!is_file($src)) continue;
        $dest = $destDir . '/' . $f2;
        if (@copy($src, $dest)) { @chmod($dest, 0644); $photoCount++; }
    }
}

// Copie documents (si presents)
$docCount = 0;
$docsDir = $extractDir . '/documents';
if (is_dir($docsDir)) {
    $destDocs = $destDir . '/documents';
    @mkdir($destDocs, 0755, true);
    foreach (scandir($docsDir) ?: [] as $f3) {
        if ($f3 === '.' || $f3 === '..') continue;
        $src = $docsDir . '/' . $f3;
        if (!is_file($src)) continue;
        $dest = $destDocs . '/' . $f3;
        if (@copy($src, $dest)) { @chmod($dest, 0644); $docCount++; }
    }
}

// Cleanup extraction temp
$rm = function($d) use (&$rm) {
    if (!is_dir($d)) return;
    foreach (scandir($d) ?: [] as $i) { if ($i==='.' || $i==='..') continue; $p=$d.'/'.$i; is_dir($p) ? $rm($p) : @unlink($p); }
    @rmdir($d);
};
$rm($extractDir);

jsonResponse([
    'ok' => true,
    'dossier_id_new' => $newId,
    'imported_from_version' => $payload['ocre_version'],
    'imported_from_dossier_id' => $payload['dossier_id_source'] ?? null,
    'photos_imported' => $photoCount,
    'documents_imported' => $docCount,
]);
