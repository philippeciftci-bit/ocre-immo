<?php
// M/2026/05/04/36 — endpoint client error logging (instrumentation diag iPad).
// POST {type, msg, src?, line?, col?, stack?, url?} -> append /var/log/ocre/client-errors.log.
declare(strict_types=1);
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, X-Session-Token');

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') { http_response_code(204); exit; }
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'method']);
    exit;
}

$raw = file_get_contents('php://input') ?: '';
$data = json_decode($raw, true);
if (!is_array($data)) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'invalid_json']);
    exit;
}

$entry = [
    'ts' => gmdate('c'),
    'host' => $_SERVER['HTTP_HOST'] ?? '',
    'ua' => substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 200),
    'ip' => $_SERVER['REMOTE_ADDR'] ?? '',
    'type' => substr((string)($data['type'] ?? 'unknown'), 0, 40),
    'msg' => substr((string)($data['msg'] ?? ''), 0, 1000),
    'src' => substr((string)($data['src'] ?? ''), 0, 300),
    'line' => isset($data['line']) ? (int)$data['line'] : null,
    'col' => isset($data['col']) ? (int)$data['col'] : null,
    'stack' => substr((string)($data['stack'] ?? ''), 0, 2000),
    'url' => substr((string)($data['url'] ?? ''), 0, 500),
];

$logDir = '/var/log/ocre';
if (!is_dir($logDir)) @mkdir($logDir, 0775, true);
$logFile = $logDir . '/client-errors.log';
@file_put_contents($logFile, json_encode($entry, JSON_UNESCAPED_UNICODE) . "\n", FILE_APPEND | LOCK_EX);
echo json_encode(['ok' => true]);
