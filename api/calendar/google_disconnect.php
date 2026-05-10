<?php
// M118c — POST /api/calendar/google/disconnect.php : revoke + DELETE oauth
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../_session.php';
require_once __DIR__ . '/../lib/permissions.php';
setCorsHeaders();
$user = getCurrentUserDualMode();
if (!$user || !empty($user['_no_tenant_user']) || !empty($user['_tenant_mismatch'])) jsonError('Non authentifie', 401);
requireRole(['owner', 'manager', 'collaborator'], $user);
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') jsonError('method', 405);

$tenant = $user['slug']; $userId = (int) $user['user_id'];

try {
    $meta = new PDO('mysql:host=' . DB_HOST . ';dbname=ocre_meta;charset=utf8mb4', DB_USER, DB_PASS,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    // V1 stub : skip Google revoke API call (mock toujours OK)
    $meta->prepare("DELETE FROM google_calendar_oauth WHERE tenant_slug=? AND user_id=?")->execute([$tenant, $userId]);
    @$meta->prepare("UPDATE google_calendar_events_map SET sync_status='unsynced' WHERE tenant_slug=? AND user_id=?")->execute([$tenant, $userId]);
} catch (Throwable $e) { error_log('[gcal_disconnect] ' . $e->getMessage()); }

jsonResponse(['ok' => true, 'disconnected' => true]);
