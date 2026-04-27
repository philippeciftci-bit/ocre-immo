<?php
// V20 M/2026/04/27/8 — endpoint dev codes : récupère le dernier code email généré.
// Auth simple : header X-Dev-Key === valeur stockée dans /root/.secrets/ocre_dev_key.
// Workaround temporaire pendant que DNS Resend ocre.immo se propage.
require_once __DIR__ . '/lib/router.php';
header('Content-Type: application/json; charset=utf-8');

function jout(array $d, int $code = 200) {
    http_response_code($code);
    echo json_encode($d, JSON_UNESCAPED_UNICODE);
    exit;
}

$dev_key_file = '/root/.secrets/ocre_dev_key';
if (!is_readable($dev_key_file)) jout(['ok'=>false,'error'=>'dev mode disabled (no key)'], 503);
$dev_key = trim(file_get_contents($dev_key_file));
$presented = $_SERVER['HTTP_X_DEV_KEY'] ?? ($_GET['key'] ?? '');
if (!hash_equals($dev_key, $presented)) jout(['ok'=>false,'error'=>'unauthorized'], 403);

$action = $_GET['action'] ?? 'last_code';

switch ($action) {
case 'last_code': {
    $email = strtolower(trim($_GET['email'] ?? ''));
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) jout(['ok'=>false,'error'=>'email requis'], 400);
    $st = pdo_meta()->prepare(
        "SELECT code_plain, context, created_at FROM dev_codes
         WHERE email = ? AND created_at > DATE_SUB(NOW(), INTERVAL 1 HOUR)
         ORDER BY created_at DESC LIMIT 1"
    );
    $st->execute([$email]);
    $row = $st->fetch();
    if (!$row) jout(['ok'=>false,'error'=>'no recent code (1h max)']);
    jout(['ok'=>true, 'email'=>$email, 'code'=>$row['code_plain'], 'context'=>$row['context'], 'created_at'=>$row['created_at']]);
}

case 'list': {
    $rows = pdo_meta()->query(
        "SELECT email, code_plain, context, created_at FROM dev_codes
         WHERE created_at > DATE_SUB(NOW(), INTERVAL 1 HOUR)
         ORDER BY created_at DESC LIMIT 30"
    )->fetchAll();
    jout(['ok'=>true, 'codes'=>$rows]);
}

default:
    jout(['ok'=>false,'error'=>'action inconnue (last_code|list)'], 400);
}
