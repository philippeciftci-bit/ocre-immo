<?php
// M115b — GET /api/photo_render.php?u=<url>&w=<width>&fmt=auto|webp|jpg
// Resize + format conversion server-side via PHP GD (Imagick non installe).
// Cache disque /var/cache/ocre-photo-render/<hash>.<ext> avec Cache-Control immutable.

const PHR_CACHE_DIR = '/var/cache/ocre-photo-render';
const PHR_MAX_WIDTH = 2048;
const PHR_DEFAULT_WIDTH = 800;
const PHR_QUALITY_JPG = 82;
const PHR_QUALITY_WEBP = 80;
const PHR_ALLOWED_DOMAINS = ['exbat-tat-ad7d.ocre.immo', 'm72-test-e45b.ocre.immo', 'm73-test-ba94.ocre.immo']; // whitelist

function phr_send_image(string $path, string $contentType): void {
    header('Content-Type: ' . $contentType);
    header('Cache-Control: public, max-age=31536000, immutable');
    header('Vary: Accept');
    header('Content-Length: ' . filesize($path));
    readfile($path);
    exit;
}

$url = $_GET['u'] ?? '';
$width = max(48, min(PHR_MAX_WIDTH, (int) ($_GET['w'] ?? PHR_DEFAULT_WIDTH)));
$fmtRequest = strtolower($_GET['fmt'] ?? 'auto');

if (!filter_var($url, FILTER_VALIDATE_URL)) { http_response_code(400); exit('bad url'); }
$host = parse_url($url, PHP_URL_HOST);
if (!in_array($host, PHR_ALLOWED_DOMAINS, true) && !preg_match('/\.ocre\.immo$/', $host ?? '')) {
    http_response_code(403); exit('domain not allowed');
}

// Detect format support
$accept = $_SERVER['HTTP_ACCEPT'] ?? '';
$useWebp = ($fmtRequest === 'webp') || ($fmtRequest === 'auto' && strpos($accept, 'image/webp') !== false);
$ext = $useWebp ? 'webp' : 'jpg';
$contentType = $useWebp ? 'image/webp' : 'image/jpeg';

// Cache key
$hash = sha1($url . '|w=' . $width . '|fmt=' . $ext);
if (!is_dir(PHR_CACHE_DIR)) @mkdir(PHR_CACHE_DIR, 0755, true);
$cachePath = PHR_CACHE_DIR . '/' . $hash . '.' . $ext;
if (file_exists($cachePath)) phr_send_image($cachePath, $contentType);

// Fetch source
$ch = curl_init($url);
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT => 10,
    CURLOPT_FOLLOWLOCATION => true,
    CURLOPT_MAXREDIRS => 3,
    CURLOPT_USERAGENT => 'OcrePhotoRender/1.0',
]);
$src = curl_exec($ch);
$srcCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);
if ($src === false || $srcCode !== 200) { http_response_code(502); exit('fetch failed'); }

// Decode + resize via GD
$im = @imagecreatefromstring($src);
if (!$im) { http_response_code(415); exit('unsupported image'); }
$origW = imagesx($im); $origH = imagesy($im);
if ($origW > $width) {
    $newH = (int) round($origH * $width / $origW);
    $resized = imagecreatetruecolor($width, $newH);
    // Preserve alpha
    imagealphablending($resized, false);
    imagesavealpha($resized, true);
    imagecopyresampled($resized, $im, 0, 0, 0, 0, $width, $newH, $origW, $origH);
    imagedestroy($im);
    $im = $resized;
}

if ($useWebp && function_exists('imagewebp')) {
    @imagewebp($im, $cachePath, PHR_QUALITY_WEBP);
} else {
    $useWebp = false;
    $contentType = 'image/jpeg';
    $cachePath = preg_replace('/\.webp$/', '.jpg', $cachePath);
    @imagejpeg($im, $cachePath, PHR_QUALITY_JPG);
}
imagedestroy($im);

if (!file_exists($cachePath)) { http_response_code(500); exit('write failed'); }
phr_send_image($cachePath, $contentType);
