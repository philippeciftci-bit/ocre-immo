<?php
// V20 — proxy lecture photo agent. Path attendu : uploads/agents/<uid>/avatar-<size>.jpg.
// Public en lecture : utilisé aussi par vitrine. Size 400 ou 120.
require_once __DIR__ . '/db.php';
setCorsHeaders();

$uid = (int) ($_GET['uid'] ?? 0);
$size = (int) ($_GET['size'] ?? 400);
if (!$uid || !in_array($size, [400, 120], true)) { http_response_code(400); exit('bad params'); }

$base = realpath(__DIR__ . '/../uploads');
$file = $base . '/agents/' . $uid . '/avatar-' . $size . '.jpg';
$real = realpath($file);
if (!$real || strpos($real, $base) !== 0 || !is_file($real)) { http_response_code(404); exit; }

header('Content-Type: image/jpeg');
header('Content-Length: ' . filesize($real));
header('Cache-Control: public, max-age=600');
readfile($real);
exit;
