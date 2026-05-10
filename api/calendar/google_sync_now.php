<?php
// M118c — POST /api/calendar/google/sync_now.php : trigger manuel worker
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../_session.php';
require_once __DIR__ . '/../lib/permissions.php';
setCorsHeaders();
$user = getCurrentUserDualMode();
if (!$user || !empty($user['_no_tenant_user']) || !empty($user['_tenant_mismatch'])) jsonError('Non authentifie', 401);
requireRole(['owner', 'manager', 'collaborator'], $user);
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') jsonError('method', 405);

// Touch fichier signal pour worker
@file_put_contents('/tmp/ocre-calendar-force-sync', date('c'));

// Run worker en sync immédiat (V1 stub : exec direct)
$out = [];
@exec('php /opt/ocre-app/scripts/calendar_sync_worker.php 2>&1', $out);

jsonResponse(['ok' => true, 'triggered' => true, 'output_lines' => count($out)]);
