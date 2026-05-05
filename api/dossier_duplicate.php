<?php
// M/2026/05/05/13 — M-Swipe-Grille-Dupliquer-V2 : duplique un dossier en brouillon.
require_once __DIR__.'/db.php';
header('Content-Type: application/json; charset=utf-8');
$tk = $_SERVER['HTTP_X_SESSION_TOKEN'] ?? '';
if (!$tk) { http_response_code(401); echo json_encode(['ok'=>false,'error'=>'no_session']); exit; }
$body = json_decode(file_get_contents('php://input'), true) ?: [];
$src = (int)($body['source_id'] ?? 0);
if (!$src) { http_response_code(400); echo json_encode(['ok'=>false,'error'=>'missing_source_id']); exit; }
try {
  $pdo = pdo_meta();
  $u = $pdo->prepare("SELECT user_id FROM sessions WHERE token=? AND (expires_at IS NULL OR expires_at>NOW()) LIMIT 1");
  $u->execute([$tk]);
  $row = $u->fetch(PDO::FETCH_ASSOC);
  if (!$row) { http_response_code(401); echo json_encode(['ok'=>false,'error'=>'invalid_session']); exit; }
  $uid = (int)$row['user_id'];
  $tenant = function_exists('pdo_for_user') ? pdo_for_user($uid) : (function_exists('db') ? db() : $pdo);
  $s = $tenant->prepare("SELECT * FROM clients WHERE id=? LIMIT 1");
  $s->execute([$src]);
  $source = $s->fetch(PDO::FETCH_ASSOC);
  if (!$source) { http_response_code(404); echo json_encode(['ok'=>false,'error'=>'source_not_found']); exit; }
  unset($source['id']);
  $source['nom'] = ($source['nom'] ?? '') . ' (copie)';
  $source['archived'] = 0;
  $source['created_at'] = date('Y-m-d H:i:s');
  $source['updated_at'] = date('Y-m-d H:i:s');
  if (array_key_exists('user_id', $source)) $source['user_id'] = $uid;
  $cols = array_keys($source);
  $place = array_fill(0, count($cols), '?');
  $sql = "INSERT INTO clients (" . implode(',', array_map(fn($c)=>"`$c`",$cols)) . ") VALUES (" . implode(',', $place) . ")";
  $stmt = $tenant->prepare($sql);
  $stmt->execute(array_values($source));
  echo json_encode(['ok'=>true, 'new_dossier_id'=>(int)$tenant->lastInsertId()]);
} catch (Throwable $e) {
  http_response_code(500);
  echo json_encode(['ok'=>false, 'error'=>'server_error', 'detail'=>$e->getMessage()]);
}
