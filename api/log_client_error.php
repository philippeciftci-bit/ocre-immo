<?php
// M/2026/05/14/1 — Endpoint logging erreurs client-side (front -> server).
// Volet 2 stabilisation Ocre: chaque toast critique 5xx ou unhandled rejection
// front POST ici. Append JSON line dans /var/log/ocre/client_errors.log.
// Vocab: "wsp" (espace de travail). Le terme "tenant" est banni.

require_once __DIR__ . '/db.php';

setCorsHeaders();

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'method_not_allowed']);
    exit;
}

$raw = file_get_contents('php://input');
$input = json_decode($raw, true);
if (!is_array($input)) $input = [];

$entry = [
    'ts'           => date('c'),
    'remote_ip'    => $_SERVER['REMOTE_ADDR'] ?? '',
    'host'         => $_SERVER['HTTP_HOST'] ?? '',
    'url'          => isset($input['url'])          ? substr((string)$input['url'], 0, 500)          : '',
    'status'       => isset($input['status'])       ? (int)$input['status']                          : 0,
    'body_snippet' => isset($input['body_snippet']) ? substr((string)$input['body_snippet'], 0, 500) : '',
    'user_id'      => isset($input['user_id'])      ? (int)$input['user_id']                        : 0,
    'wsp_slug'     => isset($input['wsp_slug'])     ? substr((string)$input['wsp_slug'], 0, 80)      : '',
    'ua'           => isset($input['ua'])           ? substr((string)$input['ua'], 0, 250)           : '',
    'client_ts'    => isset($input['ts'])           ? substr((string)$input['ts'], 0, 32)            : '',
];

$logDir = '/var/log/ocre';
if (!is_dir($logDir)) @mkdir($logDir, 0775, true);
$logFile = $logDir . '/client_errors.log';
@file_put_contents($logFile, json_encode($entry, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n", FILE_APPEND | LOCK_EX);

http_response_code(204);
exit;
