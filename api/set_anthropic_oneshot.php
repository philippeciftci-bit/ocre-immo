<?php
// V17.15 — one-shot IP-whitelist VPS pour poser la clé Anthropic dans settings.
require_once __DIR__ . '/db.php';
header('Content-Type: application/json; charset=utf-8');
$ip = $_SERVER['REMOTE_ADDR'] ?? '';
if (!in_array($ip, ['46.225.215.148'], true)) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'IP refusée (' . $ip . ')']);
    exit;
}
$body = file_get_contents('php://input');
$j = json_decode($body, true);
$key = trim((string)($j['api_key'] ?? ''));
if (!$key || !str_starts_with($key, 'sk-ant-')) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'clé sk-ant- attendue']);
    exit;
}
// Ocre settings schema uses (key_name, value), pas (k, v).
$pdo = db();
$stmt = $pdo->prepare("INSERT INTO settings (key_name, value) VALUES ('anthropic_api_key', ?)
                      ON DUPLICATE KEY UPDATE value = VALUES(value)");
$stmt->execute([$key]);
echo json_encode(['ok' => true, 'length' => strlen($key), 'prefix' => substr($key, 0, 12)]);
