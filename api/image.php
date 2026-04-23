<?php
// V18.18 — proxy lecture d'une image importée (auth user + verify ownership).
// Path attendu : users/user_{N}/imports/{uuid}.jpg. Le N doit correspondre à l'user session.
require_once __DIR__ . '/db.php';
setCorsHeaders();

$user = requireAuth();
$path = (string) ($_GET['path'] ?? '');
$path = ltrim($path, '/');

// Sécurité : pas de .., pas de slashes Windows, doit matcher le pattern exact.
if (!preg_match('#^users/user_(\d+)/imports/[a-f0-9]{24}\.(jpe?g|png|webp)$#i', $path, $m)) {
    jsonError('chemin invalide', 400);
}
$owner_id = (int) $m[1];
if ($owner_id !== (int) $user['id']) {
    // Sauf admin : accès refusé.
    if (($user['role'] ?? '') !== 'admin') jsonError('Accès refusé', 403);
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
