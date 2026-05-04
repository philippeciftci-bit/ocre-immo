<?php
// M/2026/05/04/28 — M28 photo upload endpoint. multipart/form-data avec file + dossier_id.
// Convertit en webp (qualite 85, max 1920px largeur), stocke /var/lib/ocre/uploads/<wsp>/<id>/<uuid>.webp.
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/lib/router.php';
setCorsHeaders();
header('Content-Type: application/json; charset=utf-8');

const PHOTO_MAX_BYTES = 20 * 1024 * 1024; // 20 MB pre-compression
const PHOTO_MAX_WIDTH = 1920;
const PHOTO_WEBP_QUALITY = 85;
const PHOTO_BASE_DIR = '/var/lib/ocre/uploads';

function jout($d, $code = 200) { http_response_code($code); echo json_encode($d, JSON_UNESCAPED_UNICODE); exit; }

$ctx = resolve_workspace_context();
require_write_access($ctx);

$dossierId = (int) ($_POST['dossier_id'] ?? 0);
if (!$dossierId) jout(['ok' => false, 'error' => 'dossier_id_requis'], 400);
if (empty($_FILES['file']) || !is_array($_FILES['file'])) jout(['ok' => false, 'error' => 'file_manquant'], 400);
$file = $_FILES['file'];
if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) jout(['ok' => false, 'error' => 'upload_failed', 'code' => (int)$file['error']], 400);
if ($file['size'] > PHOTO_MAX_BYTES) jout(['ok' => false, 'error' => 'too_large'], 413);

$mime = mime_content_type($file['tmp_name']) ?: '';
$allowedMimes = ['image/jpeg', 'image/png', 'image/webp', 'image/heic', 'image/heif'];
if (!in_array($mime, $allowedMimes, true)) jout(['ok' => false, 'error' => 'mime_invalid', 'detail' => $mime], 415);

// Verifie que le dossier appartient au workspace.
$pdoTenant = pdo_workspace($ctx['db_name']);
$ck = $pdoTenant->prepare("SELECT id, photos_uuids FROM clients WHERE id = ? AND deleted_at IS NULL LIMIT 1");
$ck->execute([$dossierId]);
$row = $ck->fetch();
if (!$row) jout(['ok' => false, 'error' => 'dossier_introuvable'], 404);

// Genere UUID + path.
$uuid = bin2hex(random_bytes(16));
$wspSlug = preg_replace('/[^a-z0-9_-]/', '', $ctx['workspace']['slug'] ?? 'unknown');
$dir = PHOTO_BASE_DIR . '/' . $wspSlug . '/' . $dossierId;
if (!is_dir($dir)) {
    if (!@mkdir($dir, 0755, true)) jout(['ok' => false, 'error' => 'mkdir_failed'], 500);
}
$path = $dir . '/' . $uuid . '.webp';

// Charge image (auto-detect format) + resize si > 1920 + ecrit en webp.
if (!function_exists('imagewebp')) jout(['ok' => false, 'error' => 'gd_no_webp'], 500);
$src = null;
if ($mime === 'image/jpeg') $src = @imagecreatefromjpeg($file['tmp_name']);
elseif ($mime === 'image/png') $src = @imagecreatefrompng($file['tmp_name']);
elseif ($mime === 'image/webp') $src = @imagecreatefromwebp($file['tmp_name']);
else {
    // HEIC/HEIF : essayer via Imagick si dispo (PHP-GD ne gere pas).
    if (class_exists('Imagick')) {
        try {
            $im = new Imagick($file['tmp_name']);
            $im->setImageFormat('jpeg');
            $tmpJpg = $file['tmp_name'] . '.jpg';
            $im->writeImage($tmpJpg);
            $src = @imagecreatefromjpeg($tmpJpg);
            @unlink($tmpJpg);
        } catch (Throwable $e) { $src = null; }
    }
}
if (!$src) jout(['ok' => false, 'error' => 'gd_failed', 'detail' => 'decode_'. $mime], 500);

$w0 = imagesx($src); $h0 = imagesy($src);
$w = $w0; $h = $h0;
if ($w0 > PHOTO_MAX_WIDTH) {
    $ratio = PHOTO_MAX_WIDTH / $w0;
    $w = PHOTO_MAX_WIDTH; $h = (int) round($h0 * $ratio);
    $resized = imagecreatetruecolor($w, $h);
    imagealphablending($resized, false); imagesavealpha($resized, true);
    imagecopyresampled($resized, $src, 0, 0, 0, 0, $w, $h, $w0, $h0);
    imagedestroy($src);
    $src = $resized;
}
$ok = @imagewebp($src, $path, PHOTO_WEBP_QUALITY);
imagedestroy($src);
if (!$ok || !is_file($path)) jout(['ok' => false, 'error' => 'webp_write_failed'], 500);
@chmod($path, 0644);
$sizeKb = (int) round(filesize($path) / 1024);

// Update clients.photos_uuids JSON array (append).
$existing = [];
if (!empty($row['photos_uuids'])) {
    $tmp = json_decode($row['photos_uuids'], true);
    if (is_array($tmp)) $existing = $tmp;
}
$existing[] = $uuid;
$pdoTenant->prepare("UPDATE clients SET photos_uuids = ? WHERE id = ?")
    ->execute([json_encode(array_values($existing), JSON_UNESCAPED_UNICODE), $dossierId]);

jout([
    'ok' => true,
    'uuid' => $uuid,
    'url' => '/api/photo_serve.php?u=' . $uuid . '&d=' . $dossierId,
    'size_kb' => $sizeKb,
    'w' => $w,
    'h' => $h,
]);
