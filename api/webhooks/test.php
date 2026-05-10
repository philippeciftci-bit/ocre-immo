<?php
// M116 — POST /api/webhooks/test.php {id} -> envoie event test au webhook
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
$st = wh_meta_pdo()->prepare("SELECT * FROM webhooks WHERE id=? AND tenant_slug=? LIMIT 1");
$st->execute([$id, $user['slug']]);
$h = $st->fetch();
if (!$h) jsonError('webhook introuvable', 404);
$result = wh_deliver_one((int) $h['id'], $h['url'], $h['secret'], 'dossier.created', [
    'test' => true,
    'tenant_slug' => $user['slug'],
    'message' => 'Ceci est un event de test envoye depuis Ocre Immo. Si vous voyez ce payload, votre webhook fonctionne.',
    'sample_dossier_id' => 999,
]);
jsonResponse(['ok' => true, 'result' => $result]);
