<?php
// V23 — proxy lecture photo + 2 auth paths : (a) session normale (header X-Session-Token
// ou ?token=…), (b) URL pré-signée HMAC-SHA256 pour les <img> browser (t + e).
// Pattern : users/user_{N}/imports/{uuid}.jpg — N doit matcher user session OU signature valide.
require_once __DIR__ . '/db.php';
@require_once __DIR__ . '/_image_hmac.php';
setCorsHeaders();

$path = (string) ($_GET['path'] ?? '');
$path = ltrim($path, '/');

// Sécurité chemin : regex strict.
if (!preg_match('#^users/user_(\d+)/imports/[a-f0-9]{24}\.(jpe?g|png|webp)$#i', $path, $m)) {
    jsonError('chemin invalide', 400);
}
$owner_id = (int) $m[1];

// === Auth path B : URL pré-signée HMAC ===
$t = (string) ($_GET['t'] ?? '');
$e = (int) ($_GET['e'] ?? 0);
$hmac_ok = false;
if ($t && $e && defined('IMAGE_HMAC_SECRET')) {
    if ($e >= time()) {
        $payload = $path . '|' . $e;
        $expected = hash_hmac('sha256', $payload, IMAGE_HMAC_SECRET);
        if (hash_equals($expected, $t)) $hmac_ok = true;
    }
}

// === Auth path A : session (header ou ?token=) ===
if (!$hmac_ok) {
    $user = requireAuth();
    if ($owner_id !== (int) $user['id']) {
        if (($user['role'] ?? '') !== 'admin') jsonError('Accès refusé', 403);
    }
}

$base = realpath(__DIR__ . '/../uploads');
$file = $base . '/' . $path;
$real = realpath($file);
if (!$real || strpos($real, $base) !== 0 || !is_file($real)) {
    jsonError('introuvable', 404);
}

$mime = 'image/jpeg';
$ext = strtolower(pathinfo($real, PATHINFO_EXTENSION));
if ($ext === 'png') $mime = 'image/png';
elseif ($ext === 'webp') $mime = 'image/webp';

header('Content-Type: ' . $mime);
header('Content-Length: ' . filesize($real));
header('Cache-Control: private, max-age=3600');
readfile($real);
exit;
