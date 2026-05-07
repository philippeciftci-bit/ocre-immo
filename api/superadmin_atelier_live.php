<?php
// M/2026/05/07/97-MVP — SSE live preview tmux panes pour superadmin.
// GET /api/superadmin_atelier_live.php?session=claude:0|cc-atelier:0|cc-ocre-admin:0
// Auth super_admin. Lit /var/lib/atelier/pane_<safe>.txt (rafraichi par atelier-pane-dump.timer 1s),
// push event "pane_update" SSE si delta. Keepalive 15s.
require_once __DIR__ . '/lib/router.php';

// EventSource ne peut pas envoyer de header custom : fallback token via query string ?_t=
if (empty($_SERVER['HTTP_X_SESSION_TOKEN']) && !empty($_GET['_t'])) {
    $_SERVER['HTTP_X_SESSION_TOKEN'] = (string)$_GET['_t'];
}

$user = current_user_or_401();
if (($user['role'] ?? '') !== 'super_admin') {
    http_response_code(403);
    header('Content-Type: application/json');
    echo json_encode(['ok' => false, 'error' => 'super_admin only']);
    exit;
}

$session = (string)($_GET['session'] ?? '');
$allowed = ['claude:0', 'cc-atelier:0', 'cc-ocre-admin:0'];
if (!in_array($session, $allowed, true)) {
    http_response_code(400);
    header('Content-Type: application/json');
    echo json_encode(['ok' => false, 'error' => 'session not allowed', 'allowed' => $allowed]);
    exit;
}

$safe = str_replace([':', '/'], '_', $session);
$path = '/var/lib/atelier/pane_' . $safe . '.txt';

header('Content-Type: text/event-stream');
header('Cache-Control: no-cache');
header('X-Accel-Buffering: no');
header('Connection: keep-alive');

@ini_set('output_buffering', '0');
@ini_set('zlib.output_compression', '0');
@ini_set('implicit_flush', '1');
while (ob_get_level() > 0) ob_end_flush();
ob_implicit_flush(true);

set_time_limit(0);
ignore_user_abort(false);

$lastHash = '';
$lastKeepalive = time();
$start = time();
$maxDuration = 600; // 10 min puis force reconnect cote client

while (true) {
    if (connection_aborted()) exit;
    if ((time() - $start) > $maxDuration) {
        echo "event: timeout\ndata: reconnect\n\n";
        @flush();
        exit;
    }

    $content = @file_get_contents($path);
    if ($content === false) $content = '(pane indisponible)';
    $hash = md5($content);

    if ($hash !== $lastHash) {
        $payload = json_encode([
            'ts' => time(),
            'session' => $session,
            'content' => $content,
        ], JSON_UNESCAPED_UNICODE);
        echo "event: pane_update\ndata: " . $payload . "\n\n";
        @flush();
        $lastHash = $hash;
        $lastKeepalive = time();
    } elseif ((time() - $lastKeepalive) >= 15) {
        echo ": ping " . time() . "\n\n";
        @flush();
        $lastKeepalive = time();
    }

    usleep(500000); // 0.5s entre polls (timer dump tourne a 1s)
}
