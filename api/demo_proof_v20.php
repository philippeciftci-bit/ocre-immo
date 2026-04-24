<?php
// V20 demo proof — one-shot IP-whitelist. Pour chaque client_id passé, renvoie le même
// payload que /api/clients.php?action=get (data décodé + id/archived/is_draft/projet
// injectés). Permet de prouver la structure SANS avoir besoin d'un token de session.
require_once __DIR__ . '/db.php';
$ip = $_SERVER['REMOTE_ADDR'] ?? '';
if ($ip !== '46.225.215.148') { http_response_code(403); exit('Forbidden'); }

$ids = $_GET['ids'] ?? '';
$ids = array_filter(array_map('intval', explode(',', $ids)));
if (!$ids) { http_response_code(400); exit('ids requis'); }
$in = implode(',', array_map('intval', $ids));
$st = db()->query("SELECT id, projet, archived, is_draft, is_investisseur, data FROM clients WHERE id IN ($in)");
$out = [];
foreach ($st as $r) {
    $d = json_decode($r['data'] ?? '{}', true) ?: [];
    $d['id'] = (int) $r['id'];
    $d['archived'] = (bool) (int) $r['archived'];
    $d['is_draft'] = (bool) (int) $r['is_draft'];
    $d['projet'] = $r['projet'];
    $d['is_investisseur'] = (bool) (int) $r['is_investisseur'];
    $out[] = $d;
}
header('Content-Type: application/json; charset=utf-8');
echo json_encode(['ok' => true, 'clients' => $out], JSON_UNESCAPED_UNICODE);
