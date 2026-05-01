<?php
// M/2026/05/01/15 — endpoint diagnostic : recoit les exceptions React non catchees
// par ErrorBoundary cote client. Append-only en /var/log/ocre-react-errors.log avec
// rotation manuelle (pas de logrotate dedicated, low volume attendu).
header('Content-Type: application/json; charset=utf-8');
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo '{"ok":false,"error":"POST only"}';
    exit;
}
$raw = file_get_contents('php://input');
if (!$raw || strlen($raw) > 32768) {
    http_response_code(400);
    echo '{"ok":false,"error":"empty or too large"}';
    exit;
}
$data = json_decode($raw, true);
if (!is_array($data)) {
    http_response_code(400);
    echo '{"ok":false,"error":"invalid json"}';
    exit;
}
$logFile = '/var/log/ocre-react-errors.log';
$line = json_encode([
    'ts'              => date('c'),
    'ip'              => $_SERVER['REMOTE_ADDR'] ?? '',
    'host'            => $_SERVER['HTTP_HOST'] ?? '',
    'session_token'   => substr($_SERVER['HTTP_X_SESSION_TOKEN'] ?? '', 0, 8),
    'message'         => substr((string)($data['message'] ?? ''), 0, 500),
    'stack'           => substr((string)($data['stack'] ?? ''), 0, 4000),
    'component_stack' => substr((string)($data['component_stack'] ?? ''), 0, 4000),
    'url'             => substr((string)($data['url'] ?? ''), 0, 500),
    'ua'              => substr((string)($data['ua'] ?? ''), 0, 300),
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
@file_put_contents($logFile, $line . "\n", FILE_APPEND | LOCK_EX);
echo '{"ok":true}';
