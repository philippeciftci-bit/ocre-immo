<?php
// V18.16 — one-shot IP-whitelist VPS pour poser le token bridge atelier dans settings.
// Usage VPS : curl -sS -X POST "https://app.ocre.immo/api/set_atelier_token_oneshot.php" \
//   -H "Content-Type: application/json" -d "{\"token\":\"$(cat /root/.atelier_token)\"}"
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
$tok = trim((string)($j['token'] ?? ''));
if (!$tok || strlen($tok) < 32) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'token trop court']);
    exit;
}
$pdo = db();
$stmt = $pdo->prepare("INSERT INTO settings (key_name, value) VALUES ('atelier_vps_token', ?)
                      ON DUPLICATE KEY UPDATE value = VALUES(value)");
$stmt->execute([$tok]);
echo json_encode(['ok' => true, 'length' => strlen($tok), 'prefix' => substr($tok, 0, 8)]);
