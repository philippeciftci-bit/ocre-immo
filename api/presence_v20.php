<?php
// V20 phase 7 — heartbeat presence + co-édition (pessimist lock léger 30s).
require_once __DIR__ . '/lib/router.php';
header('Content-Type: application/json; charset=utf-8');

function jout(array $d, int $code = 200) {
    http_response_code($code);
    echo json_encode($d, JSON_UNESCAPED_UNICODE);
    exit;
}

$ctx = resolve_workspace_context();
$pdo = pdo_workspace($ctx['db_name']);
$action = $_GET['action'] ?? 'heartbeat';

switch ($action) {
case 'heartbeat': {
    $input = json_decode(file_get_contents('php://input'), true) ?: [];
    $dossier_id = (int)($input['dossier_id'] ?? 0);
    if (!$dossier_id) jout(['ok' => false, 'error' => 'dossier_id requis'], 400);
    $pdo->prepare(
        "INSERT INTO presence (workspace_user_id, dossier_id, user_id, user_display, last_seen)
         VALUES (?, ?, ?, ?, NOW())
         ON DUPLICATE KEY UPDATE user_display=VALUES(user_display), last_seen=NOW()"
    )->execute([$ctx['user']['id'], $dossier_id, $ctx['user']['id'], $ctx['user']['display_name'] ?? $ctx['user']['email']]);
    $st = $pdo->prepare("SELECT user_id, user_display, last_seen FROM presence WHERE dossier_id = ? AND user_id != ? AND last_seen > DATE_SUB(NOW(), INTERVAL 30 SECOND)");
    $st->execute([$dossier_id, $ctx['user']['id']]);
    jout(['ok' => true, 'others' => $st->fetchAll()]);
}

case 'release': {
    $input = json_decode(file_get_contents('php://input'), true) ?: [];
    $dossier_id = (int)($input['dossier_id'] ?? 0);
    $pdo->prepare("DELETE FROM presence WHERE user_id = ? AND dossier_id = ?")
        ->execute([$ctx['user']['id'], $dossier_id]);
    jout(['ok' => true]);
}

default:
    jout(['ok' => false, 'error' => 'action inconnue'], 400);
}
