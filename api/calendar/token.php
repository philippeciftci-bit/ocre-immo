<?php
// M118 — POST /api/calendar/token.php : genere nouveau token feed (revoke ancien)
// GET : retourne URL feed actuelle (si stocke en DB) ou null
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../_session.php';
require_once __DIR__ . '/../lib/permissions.php';
require_once __DIR__ . '/_lib.php';
setCorsHeaders();
$user = getCurrentUserDualMode();
if (!$user || !empty($user['_no_tenant_user']) || !empty($user['_tenant_mismatch'])) jsonError('Non authentifie', 401);
requireRole(['owner', 'manager', 'collaborator'], $user);
$tenant = $user['slug']; $userId = (int) $user['user_id'];

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    // Generate new token (current token invalidates because we use timestamp iat in JWT)
    // Stockage : on save dans users.calendar_feed_token ocre_meta pour permettre revoke ulterieur
    try {
        $meta = new PDO('mysql:host=' . DB_HOST . ';dbname=ocre_meta;charset=utf8mb4', DB_USER, DB_PASS,
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        @$meta->exec("ALTER TABLE users ADD COLUMN calendar_feed_token VARCHAR(512) NULL");
        $token = cal_make_feed_token($tenant, $userId);
        $st = $meta->prepare("UPDATE users SET calendar_feed_token=? WHERE id=?");
        $st->execute([$token, $userId]);
    } catch (Throwable $e) { $token = cal_make_feed_token($tenant, $userId); }
    $url = 'https://' . $tenant . '.ocre.immo/api/calendar/feed.php?token=' . $token;
    jsonResponse(['ok' => true, 'token' => $token, 'feed_url' => $url, 'note' => 'Ajoutez cette URL dans Google Calendar (Other calendars -> From URL) ou Outlook (Add calendar -> Subscribe from web)']);
}

// GET : retourne le current token si exists
try {
    $meta = new PDO('mysql:host=' . DB_HOST . ';dbname=ocre_meta;charset=utf8mb4', DB_USER, DB_PASS,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
    @$meta->exec("ALTER TABLE users ADD COLUMN calendar_feed_token VARCHAR(512) NULL");
    $st = $meta->prepare("SELECT calendar_feed_token FROM users WHERE id=? LIMIT 1");
    $st->execute([$userId]);
    $r = $st->fetch();
    $token = $r['calendar_feed_token'] ?? null;
    if ($token) {
        $url = 'https://' . $tenant . '.ocre.immo/api/calendar/feed.php?token=' . $token;
        jsonResponse(['ok' => true, 'has_token' => true, 'feed_url' => $url]);
    } else {
        jsonResponse(['ok' => true, 'has_token' => false]);
    }
} catch (Throwable $e) {
    jsonResponse(['ok' => true, 'has_token' => false]);
}
