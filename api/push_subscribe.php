<?php
// Ocre v18.3 — enregistre / supprime une souscription Web Push.
require_once __DIR__ . '/db.php';
setCorsHeaders();
$user = requireAuth();
$action = $_GET['action'] ?? 'subscribe';
$input = getInput();

switch ($action) {
    case 'subscribe': {
        $endpoint = (string)($input['endpoint'] ?? '');
        $p256dh   = (string)($input['p256dh'] ?? '');
        $auth     = (string)($input['auth'] ?? '');
        $ua       = substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255);
        if (!$endpoint || !$p256dh || !$auth) jsonError('endpoint+p256dh+auth requis');
        $st = db()->prepare(
            "INSERT INTO push_subscriptions (user_id, endpoint, p256dh, auth, ua, last_used_at)
             VALUES (?, ?, ?, ?, ?, NOW())
             ON DUPLICATE KEY UPDATE p256dh = VALUES(p256dh), auth = VALUES(auth), ua = VALUES(ua), last_used_at = NOW(), user_id = VALUES(user_id)"
        );
        $st->execute([(int)$user['id'], $endpoint, $p256dh, $auth, $ua]);
        jsonOk(['stored' => true]);
    }
    case 'unsubscribe': {
        $endpoint = (string)($input['endpoint'] ?? '');
        if (!$endpoint) jsonError('endpoint requis');
        $st = db()->prepare("DELETE FROM push_subscriptions WHERE user_id = ? AND endpoint = ?");
        $st->execute([(int)$user['id'], $endpoint]);
        jsonOk(['removed' => $st->rowCount()]);
    }
    case 'list': {
        $st = db()->prepare("SELECT id, endpoint, ua, created_at, last_used_at FROM push_subscriptions WHERE user_id = ?");
        $st->execute([(int)$user['id']]);
        jsonOk(['subscriptions' => $st->fetchAll()]);
    }
    default:
        jsonError('Action inconnue', 404);
}
