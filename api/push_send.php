<?php
// M/2026/05/09/42 — M88 : envoie un push aux subscriptions actives d'un user_id.
// Endpoint INTERNE (utilisé par events métier, pas exposé directement à l'UI utilisateur).
// Dispatch via helper Python pywebpush (pas de composer requis).
//
// POST {user_id, type, title, body, url?, tag?, icon?, badge?, internal_token?}
//
// Auth : si appelé hors process serveur (pas via cron/cli/internal), exige un token interne
// stocké dans /root/.secrets/ocre_push_internal.token.
require_once __DIR__ . '/db.php';
setCorsHeaders();

$input = getInput();
$internalTokenFile = '/root/.secrets/ocre_push_internal.token';

// Auth : si called via CLI ou avec internal token correct, on autorise. Sinon on demande session.
$isCli = (php_sapi_name() === 'cli');
$tokenOk = false;
if (is_readable($internalTokenFile)) {
    $expected = trim(file_get_contents($internalTokenFile));
    $provided = trim((string) ($input['internal_token'] ?? ''));
    if ($expected !== '' && hash_equals($expected, $provided)) $tokenOk = true;
}
if (!$isCli && !$tokenOk) {
    // Fallback : exige session valide (utile pour tests admin).
    requireAuth();
}

$uid = (int) ($input['user_id'] ?? 0);
$type = trim((string) ($input['type'] ?? 'info'));
$title = trim((string) ($input['title'] ?? ''));
$body = trim((string) ($input['body'] ?? ''));
$url = trim((string) ($input['url'] ?? '/'));
$tag = trim((string) ($input['tag'] ?? 'ocre-default'));
$icon = trim((string) ($input['icon'] ?? '/icons/icon-192.png'));
$badge = trim((string) ($input['badge'] ?? '/icons/badge-72.png'));

if ($uid <= 0 || $title === '' || $body === '') {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'missing_fields']);
    exit;
}

$privFile = '/root/.secrets/ocre_vapid_priv.pem';
if (!is_readable($privFile)) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'vapid_priv_missing']);
    exit;
}
$vapidPriv = file_get_contents($privFile);

$dsn = 'mysql:host=' . DB_HOST . ';dbname=ocre_meta;charset=utf8mb4';
$pdo = new PDO($dsn, DB_USER, DB_PASS, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);

$st = $pdo->prepare("SELECT id, endpoint, p256dh_key, auth_key, types_enabled FROM push_subscriptions WHERE user_id = ? AND expired_at IS NULL");
$st->execute([$uid]);
$subs = $st->fetchAll();

$results = [];
$payload = json_encode([
    'title' => $title,
    'body' => $body,
    'icon' => $icon,
    'badge' => $badge,
    'tag' => $tag,
    'url' => $url,
    'type' => $type,
]);

foreach ($subs as $s) {
    $types = json_decode($s['types_enabled'] ?? '[]', true) ?: [];
    if (!empty($types) && !in_array($type, $types, true) && $type !== 'test') {
        $results[] = ['sub_id' => $s['id'], 'skipped' => 'type_not_subscribed'];
        continue;
    }

    $helperInput = json_encode([
        'endpoint' => $s['endpoint'],
        'p256dh' => $s['p256dh_key'],
        'auth' => $s['auth_key'],
        'payload' => $payload,
        'vapid_priv_pem' => $vapidPriv,
        'vapid_subject' => 'mailto:support@ocre.immo',
    ]);

    $descriptors = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
    $proc = proc_open(['python3', __DIR__ . '/lib/push_send_helper.py'], $descriptors, $pipes);
    if (!is_resource($proc)) {
        $results[] = ['sub_id' => $s['id'], 'ok' => false, 'err' => 'proc_open_failed'];
        continue;
    }
    fwrite($pipes[0], $helperInput);
    fclose($pipes[0]);
    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $exitCode = proc_close($proc);

    $r = json_decode($stdout, true);
    if (!is_array($r)) {
        $results[] = ['sub_id' => $s['id'], 'ok' => false, 'err' => 'helper_invalid_output', 'stdout' => substr($stdout, 0, 200), 'stderr' => substr($stderr, 0, 200)];
        continue;
    }
    if (!empty($r['ok'])) {
        $pdo->prepare("UPDATE push_subscriptions SET last_used_at = NOW() WHERE id = ?")->execute([$s['id']]);
    } elseif (in_array((int) ($r['status_code'] ?? 0), [404, 410], true)) {
        // Subscription expirée côté push service.
        $pdo->prepare("UPDATE push_subscriptions SET expired_at = NOW() WHERE id = ?")->execute([$s['id']]);
        $r['marked_expired'] = true;
    }
    $r['sub_id'] = $s['id'];
    $results[] = $r;
}

echo json_encode(['ok' => true, 'sent' => count($subs), 'results' => $results]);
