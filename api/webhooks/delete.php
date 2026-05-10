<?php
// M116 — POST /api/webhooks/delete.php {id}
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../_session.php';
require_once __DIR__ . '/../lib/webhook_dispatcher.php';
setCorsHeaders();
$user = getCurrentUserDualMode();
if (!$user || !empty($user['_no_tenant_user']) || !empty($user['_tenant_mismatch'])) jsonError('Non authentifie', 401);
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') jsonError('method', 405);
$d = getInput();
$id = (int) ($d['id'] ?? 0);
if (!$id) jsonError('id requis', 400);
wh_meta_pdo()->prepare("DELETE FROM webhooks WHERE id=? AND tenant_slug=?")->execute([$id, $user['slug']]);
jsonResponse(['ok' => true, 'id' => $id, 'deleted' => true]);
