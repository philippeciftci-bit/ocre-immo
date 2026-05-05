<?php
// M/2026/05/05/12 — M-Partage-Menu-V2 : liste WSC dont l agent courant est membre.
require_once __DIR__.'/db.php';
header('Content-Type: application/json; charset=utf-8');
$tk = $_SERVER['HTTP_X_SESSION_TOKEN'] ?? '';
if (!$tk) { http_response_code(401); echo json_encode(['ok'=>false,'error'=>'no_session']); exit; }
try {
  $pdo = pdo_meta();
  $u = $pdo->prepare("SELECT user_id FROM sessions WHERE token=? AND (expires_at IS NULL OR expires_at>NOW()) LIMIT 1");
  $u->execute([$tk]);
  $row = $u->fetch(PDO::FETCH_ASSOC);
  if (!$row) { http_response_code(401); echo json_encode(['ok'=>false,'error'=>'invalid_session']); exit; }
  $uid = (int)$row['user_id'];
  $stmt = $pdo->prepare("
    SELECT w.id, w.name,
           COALESCE(GROUP_CONCAT(DISTINCT u2.first_name SEPARATOR '||'), '') as member_first_names,
           COUNT(DISTINCT m2.user_id) as member_count
    FROM workspaces w
    JOIN workspace_members m ON m.workspace_id=w.id AND m.user_id=? AND m.left_at IS NULL
    LEFT JOIN workspace_members m2 ON m2.workspace_id=w.id AND m2.left_at IS NULL
    LEFT JOIN users u2 ON u2.id=m2.user_id AND u2.id!=?
    WHERE w.type='collaborative'
    GROUP BY w.id, w.name
    ORDER BY w.created_at DESC LIMIT 50
  ");
  $stmt->execute([$uid, $uid]);
  $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
  $out = array_map(function($r){
    $names = $r['member_first_names'] ? array_filter(explode('||', $r['member_first_names'])) : [];
    return ['id'=>(int)$r['id'], 'name'=>$r['name'], 'members'=>array_values($names), 'member_count'=>(int)$r['member_count']];
  }, $rows);
  echo json_encode(['ok'=>true, 'workspaces'=>$out]);
} catch (Throwable $e) {
  echo json_encode(['ok'=>true, 'workspaces'=>[], 'note'=>'feature_not_yet_provisioned']);
}
