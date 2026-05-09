<?php
// M/2026/05/09/47 — M93 : logs PWA push (M88-M89) pour superadmin dashboard.
// Source : table ocre_meta.push_subscriptions + log /var/log/ocre-push.log.
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/_session.php';
setCorsHeaders();

$user = getCurrentUserFromCookie();
if (!$user || ($user['role'] ?? '') !== 'super_admin') {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'super_admin_required']);
    exit;
}

$dsn = 'mysql:host=' . DB_HOST . ';dbname=ocre_meta;charset=utf8mb4';
$pdo = new PDO($dsn, DB_USER, DB_PASS, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);

// KPIs subscriptions
$subsActive = (int) $pdo->query("SELECT COUNT(*) FROM push_subscriptions WHERE expired_at IS NULL")->fetchColumn();
$subsExpired = (int) $pdo->query("SELECT COUNT(*) FROM push_subscriptions WHERE expired_at IS NOT NULL")->fetchColumn();

// Parse log file pour KPIs 24h + events
$logFile = '/var/log/ocre-push.log';
$push24h = 0; $push24hOk = 0;
$events = [];
if (is_readable($logFile)) {
    // tail -200 pour limiter parse
    $lines = [];
    $fp = @fopen($logFile, 'r');
    if ($fp) {
        // Saute à la fin et lit ~50 KB derniers
        fseek($fp, 0, SEEK_END);
        $size = ftell($fp);
        $tailSize = min(50000, $size);
        fseek($fp, max(0, $size - $tailSize));
        if ($size > $tailSize) fgets($fp); // skip ligne incomplète
        while (($l = fgets($fp)) !== false) $lines[] = rtrim($l);
        fclose($fp);
    }
    $now = time();
    foreach (array_slice($lines, -200) as $l) {
        // Format : 2026-05-09T21:14:59+00:00 OK uid=126 type=test title=... resp=...
        // ou 2026-05-09T21:13:24+00:00 ERR uid=126 type=... err=...
        if (!preg_match('/^(\S+)\s+(OK|ERR|FAIL|WARN)\s+(.*)$/', $l, $m)) continue;
        $ts = $m[1]; $status = $m[2]; $rest = $m[3];
        $tsEpoch = strtotime($ts);
        if ($tsEpoch === false) continue;
        $uid = ''; $type = ''; $detail = $rest;
        if (preg_match('/uid=(\d+)/', $rest, $mm)) $uid = $mm[1];
        if (preg_match('/type=([a-z_]+)/i', $rest, $mm)) $type = $mm[1];
        if ($now - $tsEpoch < 86400) {
            $push24h++;
            if ($status === 'OK') $push24hOk++;
        }
        $events[] = ['ts' => substr($ts, 0, 19), 'user_id' => $uid, 'type' => $type, 'status' => $status, 'detail' => $detail];
    }
    $events = array_slice(array_reverse($events), 0, 50);
}
$deliveryPct = $push24h > 0 ? round($push24hOk / $push24h * 1000) / 10 : 0;

echo json_encode([
    'ok' => true,
    'kpis' => [
        'push_24h' => $push24h,
        'delivery_pct' => $deliveryPct,
        'subs_active' => $subsActive,
        'subs_expired' => $subsExpired,
    ],
    'events' => $events,
]);
