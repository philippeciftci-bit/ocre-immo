<?php
// Ocre v18.3 — worker push notifications. IP-whitelist VPS atelier (cron).
// Scanne suivi_events + suivi_todos avec rappel dû non encore notifié → envoie web-push.
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/vendor/autoload.php';

use Minishlink\WebPush\WebPush;
use Minishlink\WebPush\Subscription;

header('Content-Type: application/json; charset=utf-8');

$allowed = ['46.225.215.148'];
$remote = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? ($_SERVER['REMOTE_ADDR'] ?? '');
$remote_ip = trim(explode(',', $remote)[0]);
if (!in_array($remote_ip, $allowed, true)) {
    http_response_code(403);
    exit(json_encode(['ok' => false, 'error' => 'Forbidden']));
}

$pub = getSetting('vapid_public', '');
$priv = getSetting('vapid_private', '');
$subject = getSetting('vapid_subject', 'mailto:contact@ocre.immo');
if (!$pub || !$priv) {
    exit(json_encode(['ok' => false, 'error' => 'VAPID non configuré', 'hint' => 'curl /api/vapid_setup.php']));
}

$auth = ['VAPID' => ['subject' => $subject, 'publicKey' => $pub, 'privateKey' => $priv]];
$webPush = new WebPush($auth, [], 10);

$pdo = db();

// Helpers
function clientNameFor($pdo, $cid) {
    if (!$cid) return null;
    $st = $pdo->prepare("SELECT prenom, nom, societe_nom FROM clients WHERE id = ? LIMIT 1");
    $st->execute([(int)$cid]);
    $r = $st->fetch();
    if (!$r) return null;
    if (!empty($r['societe_nom'])) return $r['societe_nom'];
    return trim(($r['prenom'] ?? '') . ' ' . ($r['nom'] ?? '')) ?: null;
}
function relWhen($iso) {
    $t = strtotime($iso);
    $diff = $t - time();
    if ($diff < 0) return 'maintenant';
    if ($diff < 3600) return 'dans ' . round($diff/60) . ' min';
    if ($diff < 86400) return 'dans ' . round($diff/3600) . 'h';
    return 'dans ' . round($diff/86400) . 'j';
}

$sent = 0; $failed = 0; $items = [];

// 1) Events dont la fenêtre rappel est ouverte.
$evStmt = $pdo->query(
    "SELECT * FROM suivi_events
     WHERE status='planned' AND notified=0 AND reminder_min_before > 0
       AND when_at BETWEEN NOW() AND DATE_ADD(NOW(), INTERVAL reminder_min_before MINUTE)"
);
$events = $evStmt->fetchAll();

// 2) Todos dont l'échéance approche : (a) 1h avant, (b) jour J 9h matin.
$todoStmt = $pdo->query(
    "SELECT * FROM suivi_todos
     WHERE done=0 AND notified=0 AND due_at IS NOT NULL
       AND due_at BETWEEN NOW() AND DATE_ADD(NOW(), INTERVAL 60 MINUTE)"
);
$todos = $todoStmt->fetchAll();

// Cache subscriptions par user_id.
$subsCache = [];
function subsForUser($pdo, $uid, &$subsCache) {
    if (isset($subsCache[$uid])) return $subsCache[$uid];
    $st = $pdo->prepare("SELECT * FROM push_subscriptions WHERE user_id = ?");
    $st->execute([(int)$uid]);
    return $subsCache[$uid] = $st->fetchAll();
}

function pushTo($webPush, $sub, $title, $body, $url) {
    $subscription = Subscription::create([
        'endpoint' => $sub['endpoint'],
        'publicKey' => $sub['p256dh'],
        'authToken' => $sub['auth'],
        'contentEncoding' => 'aes128gcm',
    ]);
    $payload = json_encode([
        'title' => $title,
        'body' => $body,
        'icon' => '/icon-192.png',
        'badge' => '/icon-192.png',
        'url' => $url,
    ], JSON_UNESCAPED_UNICODE);
    $webPush->queueNotification($subscription, $payload, ['TTL' => 3600, 'urgency' => 'high']);
}

foreach ($events as $e) {
    $cname = clientNameFor($pdo, $e['client_id']);
    $title = '📅 ' . $e['title'];
    $body = ucfirst($e['type']) . ' ' . relWhen($e['when_at']) . ($cname ? ' — ' . $cname : '');
    $url = '/#client/' . (int)$e['client_id'] . '?section=4';
    foreach (subsForUser($pdo, $e['user_id'], $subsCache) as $s) {
        pushTo($webPush, $s, $title, $body, $url);
    }
    $items[] = ['kind' => 'event', 'id' => $e['id']];
}
foreach ($todos as $t) {
    $cname = clientNameFor($pdo, $t['client_id']);
    $title = '✅ ' . $t['title'];
    $body = 'Échéance ' . relWhen($t['due_at']) . ($cname ? ' — ' . $cname : '');
    $url = '/#client/' . (int)$t['client_id'] . '?section=4';
    foreach (subsForUser($pdo, $t['user_id'], $subsCache) as $s) {
        pushTo($webPush, $s, $title, $body, $url);
    }
    $items[] = ['kind' => 'todo', 'id' => $t['id']];
}

$expired = [];
foreach ($webPush->flush() as $report) {
    if ($report->isSuccess()) $sent++;
    else {
        $failed++;
        $endpoint = $report->getRequest()->getUri()->__toString();
        $code = $report->getResponse() ? $report->getResponse()->getStatusCode() : 0;
        if (in_array($code, [404, 410], true)) $expired[] = $endpoint;
    }
}

// Cleanup endpoints expirés.
foreach ($expired as $ep) {
    try { $pdo->prepare("DELETE FROM push_subscriptions WHERE endpoint = ?")->execute([$ep]); } catch (Throwable $e) {}
}

// Mark notified.
foreach ($events as $e) {
    try { $pdo->prepare("UPDATE suivi_events SET notified=1 WHERE id=?")->execute([(int)$e['id']]); } catch (Throwable $ex) {}
}
foreach ($todos as $t) {
    try { $pdo->prepare("UPDATE suivi_todos SET notified=1 WHERE id=?")->execute([(int)$t['id']]); } catch (Throwable $ex) {}
}

echo json_encode([
    'ok' => true,
    'sent' => $sent, 'failed' => $failed,
    'events' => count($events), 'todos' => count($todos),
    'expired_cleaned' => count($expired),
    'ts' => date('c'),
]);
