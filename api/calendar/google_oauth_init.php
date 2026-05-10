<?php
// M118b — GET /api/calendar/google/oauth/init.php → redirect Google OAuth (STUB MODE = mock local).
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../_session.php';
require_once __DIR__ . '/_lib.php';
$user = getCurrentUserDualMode();
if (!$user || !empty($user['_no_tenant_user']) || !empty($user['_tenant_mismatch'])) jsonError('Non authentifie', 401);
$tenant = $user['slug']; $userId = (int) $user['user_id'];

// Génère state + persiste pour vérification callback
$state = bin2hex(random_bytes(16));
try {
    $meta = new PDO('mysql:host=' . DB_HOST . ';dbname=ocre_meta;charset=utf8mb4', DB_USER, DB_PASS,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    @$meta->exec("CREATE TABLE IF NOT EXISTS google_oauth_states (state VARCHAR(32) PRIMARY KEY, tenant_slug VARCHAR(64), user_id INT, created_at DATETIME DEFAULT CURRENT_TIMESTAMP)");
    $meta->prepare("INSERT INTO google_oauth_states (state, tenant_slug, user_id) VALUES (?, ?, ?)")
        ->execute([$state, $tenant, $userId]);
} catch (Throwable $e) { @error_log('[gcal_init] ' . $e->getMessage()); }

// MODE STUB : redirige vers mock local. En prod : remplacer par https://accounts.google.com/o/oauth2/v2/auth?...
$mockBase = 'http://127.0.0.1:8892';
$callbackUrl = 'https://' . $tenant . '.ocre.immo/api/calendar/google_oauth_callback.php';
$url = $mockBase . '/mock/google-oauth/consent?state=' . urlencode($state)
    . '&redirect_uri=' . urlencode($callbackUrl)
    . '&scope=https%3A//www.googleapis.com/auth/calendar';

header('Location: ' . $url);
exit;
