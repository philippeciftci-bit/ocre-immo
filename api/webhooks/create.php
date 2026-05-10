<?php
// M116 — POST /api/webhooks/create.php {url, events[]}
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../_session.php';
require_once __DIR__ . '/../lib/webhook_dispatcher.php';
setCorsHeaders();
$user = getCurrentUserDualMode();
if (!$user || !empty($user['_no_tenant_user']) || !empty($user['_tenant_mismatch'])) jsonError('Non authentifie', 401);
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') jsonError('method', 405);
$tenant = $user['slug']; $userId = (int) $user['user_id'];
$d = getInput();
$url = trim($d['url'] ?? '');
$events = $d['events'] ?? [];
if (!filter_var($url, FILTER_VALIDATE_URL) || !preg_match('#^https?://#i', $url)) jsonError('URL invalide (https requis)', 400);
if (!is_array($events) || !$events) jsonError('events[] requis', 400);
$invalid = array_diff($events, WEBHOOK_EVENTS);
if ($invalid) jsonError('Events invalides : ' . implode(', ', $invalid), 400);
wh_ensure_schema();
$secret = bin2hex(random_bytes(32));
$st = wh_meta_pdo()->prepare("INSERT INTO webhooks (tenant_slug, user_id, url, events, secret) VALUES (?, ?, ?, ?, ?)");
$st->execute([$tenant, $userId, $url, json_encode($events), $secret]);
$id = (int) wh_meta_pdo()->lastInsertId();
jsonResponse(['ok' => true, 'webhook' => ['id' => $id, 'url' => $url, 'events' => $events, 'secret' => $secret, 'note' => 'Conservez ce secret, il ne sera plus visible.']]);
