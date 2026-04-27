<?php
// V20 phase 2 — CGU endpoint (current + accept).
require_once __DIR__ . '/lib/router.php';
header('Content-Type: application/json; charset=utf-8');

function jout(array $d, int $code = 200) {
    http_response_code($code);
    echo json_encode($d, JSON_UNESCAPED_UNICODE);
    exit;
}

$action = $_GET['action'] ?? 'current';
$input = json_decode(file_get_contents('php://input'), true) ?: [];

const CGU_CURRENT_VERSION = '1.0';
const CGU_HTML_URL = '/cgu/cgu-v1.0.html';

switch ($action) {
case 'current': {
    jout([
        'ok' => true,
        'version' => CGU_CURRENT_VERSION,
        'url' => CGU_HTML_URL,
    ]);
}

case 'accept': {
    $u = current_user_or_401();
    $version = (string)($input['version'] ?? CGU_CURRENT_VERSION);
    $ip = $_SERVER['REMOTE_ADDR'] ?? '';
    pdo_meta()->prepare(
        "UPDATE users SET cgu_accepted_at = NOW(), cgu_version = ?, cgu_accepted_ip = ? WHERE id = ?"
    )->execute([$version, $ip, $u['id']]);
    jout(['ok' => true, 'version' => $version, 'accepted_at' => gmdate('c')]);
}

case 'tour_completed': {
    $u = current_user_or_401();
    pdo_meta()->prepare("UPDATE users SET tour_completed_at = NOW() WHERE id = ?")->execute([$u['id']]);
    jout(['ok' => true]);
}

default:
    jout(['ok' => false, 'error' => 'action inconnue'], 400);
}
