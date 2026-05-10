<?php
// M116 — POST /api/webhooks/update.php {id, url?, events?, active?}
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../_session.php';
require_once __DIR__ . '/../lib/webhook_dispatcher.php';
setCorsHeaders();
$user = getCurrentUserDualMode();
if (!$user || !empty($user['_no_tenant_user']) || !empty($user['_tenant_mismatch'])) jsonError('Non authentifie', 401);
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') jsonError('method', 405);
$tenant = $user['slug'];
$d = getInput();
$id = (int) ($d['id'] ?? 0);
if (!$id) jsonError('id requis', 400);
$updates = []; $args = [];
if (isset($d['url'])) { if (!filter_var($d['url'], FILTER_VALIDATE_URL)) jsonError('URL invalide', 400); $updates[] = 'url=?'; $args[] = $d['url']; }
if (isset($d['events'])) { $events = $d['events']; if (!is_array($events) || !$events) jsonError('events invalides', 400); $updates[] = 'events=?'; $args[] = json_encode($events); }
if (isset($d['active'])) { $updates[] = 'active=?'; $args[] = $d['active'] ? 1 : 0; if ($d['active']) { $updates[] = 'consecutive_failures=0'; } }
if (!$updates) jsonError('Rien a modifier', 400);
$args[] = $id; $args[] = $tenant;
$sql = "UPDATE webhooks SET " . implode(', ', $updates) . " WHERE id=? AND tenant_slug=?";
wh_meta_pdo()->prepare($sql)->execute($args);
jsonResponse(['ok' => true, 'id' => $id]);
