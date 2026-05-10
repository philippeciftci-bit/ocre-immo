<?php
// M116 — GET /api/webhooks/list.php
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../_session.php';
require_once __DIR__ . '/../lib/permissions.php';
require_once __DIR__ . '/../lib/webhook_dispatcher.php';
setCorsHeaders();
$user = getCurrentUserDualMode();
if (!$user || !empty($user['_no_tenant_user']) || !empty($user['_tenant_mismatch'])) jsonError('Non authentifie', 401);
requireRole(['owner', 'manager'], $user);
$tenant = $user['slug'];
wh_ensure_schema();
$st = wh_meta_pdo()->prepare("SELECT id, url, events, active, created_at, last_triggered_at, consecutive_failures FROM webhooks WHERE tenant_slug=? ORDER BY created_at DESC");
$st->execute([$tenant]);
$webhooks = $st->fetchAll();
foreach ($webhooks as &$w) { $w['events'] = json_decode($w['events'], true); $w['active'] = (bool) $w['active']; }
unset($w);
jsonResponse(['ok' => true, 'webhooks' => $webhooks, 'available_events' => WEBHOOK_EVENTS]);
