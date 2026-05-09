<?php
// M/2026/05/09/42 — M88 : enregistre/met à jour la PushSubscription du Service Worker pour l'agent connecté.
// Refactor du legacy v18.3 pour utiliser ocre_meta.push_subscriptions (centralisé multi-tenant).
//
// Schémas supportés (compat) :
//   POST {endpoint, keys: {p256dh, auth}, types?: [...]}  ← M88
//   POST {endpoint, p256dh, auth}                          ← legacy v18.3
//
// L'optionnel `action=subscribe` legacy reste reconnu (default subscribe).
require_once __DIR__ . '/db.php';
setCorsHeaders();

$user = requireAuth();
$uid = (int) ($user['_origin_user_id'] ?? $user['id']);
$action = $_GET['action'] ?? 'subscribe';
$input = getInput();

$dsn = 'mysql:host=' . DB_HOST . ';dbname=ocre_meta;charset=utf8mb4';
$pdo = new PDO($dsn, DB_USER, DB_PASS, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);

if ($action === 'subscribe') {
    $endpoint = trim((string) ($input['endpoint'] ?? ''));
    $p256dh = trim((string) ($input['keys']['p256dh'] ?? $input['p256dh'] ?? ''));
    $auth = trim((string) ($input['keys']['auth'] ?? $input['auth'] ?? ''));
    $types = isset($input['types']) && is_array($input['types']) ? array_values($input['types']) : ['new_pact','proposal','document_signed','reminder','matching'];
    if ($endpoint === '' || $p256dh === '' || $auth === '') {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'missing_fields']);
        exit;
    }
    if (strlen($endpoint) > 512 || strlen($p256dh) > 256 || strlen($auth) > 64) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'field_too_long']);
        exit;
    }
    $ua = isset($_SERVER['HTTP_USER_AGENT']) ? substr($_SERVER['HTTP_USER_AGENT'], 0, 256) : null;
    $st = $pdo->prepare(
        "INSERT INTO push_subscriptions (user_id, endpoint, p256dh_key, auth_key, types_enabled, user_agent, last_used_at)
         VALUES (?, ?, ?, ?, ?, ?, NOW())
         ON DUPLICATE KEY UPDATE p256dh_key=VALUES(p256dh_key), auth_key=VALUES(auth_key), types_enabled=VALUES(types_enabled), user_agent=VALUES(user_agent), last_used_at=NOW(), expired_at=NULL"
    );
    $st->execute([$uid, $endpoint, $p256dh, $auth, json_encode($types), $ua]);
    echo json_encode(['ok' => true, 'subscription_id' => (int) $pdo->lastInsertId(), 'types' => $types, 'stored' => true]);
    exit;
}

if ($action === 'list') {
    $st = $pdo->prepare("SELECT id, endpoint, user_agent, types_enabled, created_at, last_used_at, expired_at FROM push_subscriptions WHERE user_id = ? ORDER BY id DESC");
    $st->execute([$uid]);
    $rows = $st->fetchAll(PDO::FETCH_ASSOC);
    foreach ($rows as &$r) { $r['types_enabled'] = json_decode($r['types_enabled'], true) ?: []; }
    echo json_encode(['ok' => true, 'subscriptions' => $rows]);
    exit;
}

http_response_code(404);
echo json_encode(['ok' => false, 'error' => 'unknown_action']);
