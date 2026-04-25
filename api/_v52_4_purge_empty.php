<?php
// V52.4 — one-shot purge IP-whitelist : soft-delete des brouillons vides crees
// par clic + sur cards (bug create-on-click). Ne touche PAS aux dossiers is_demo.
require_once __DIR__ . '/db.php';
$allowed = ['46.225.215.148','127.0.0.1','::1'];
$remote = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? ($_SERVER['REMOTE_ADDR'] ?? '');
$ip = trim(explode(',', $remote)[0]);
if (!in_array($ip, $allowed, true)) { http_response_code(403); exit(json_encode(['ok'=>false,'error'=>'forbidden','seen_ip'=>$ip])); }
header('Content-Type: application/json; charset=utf-8');

try { db()->exec("ALTER TABLE clients ADD COLUMN deleted_at DATETIME NULL DEFAULT NULL"); } catch (Throwable $e) {}

$pdo = db();
// Inspect avant purge
$sel = $pdo->query("SELECT id, user_id, prenom, nom, societe_nom, tel, email, is_demo, is_staged, created_at FROM clients WHERE deleted_at IS NULL AND COALESCE(is_demo,0)=0 AND COALESCE(is_staged,0)=0 AND (prenom IS NULL OR prenom='') AND (nom IS NULL OR nom='') AND (societe_nom IS NULL OR societe_nom='') AND (tel IS NULL OR tel='') AND (email IS NULL OR email='') ORDER BY id DESC");
$victims = $sel->fetchAll(PDO::FETCH_ASSOC);
$count = count($victims);

if ($count > 0) {
    $ids = array_map(fn($v) => (int)$v['id'], $victims);
    $in = implode(',', array_fill(0, count($ids), '?'));
    $upd = $pdo->prepare("UPDATE clients SET deleted_at = NOW() WHERE id IN ($in)");
    $upd->execute($ids);
    $purged = $upd->rowCount();
} else { $purged = 0; }

echo json_encode([
    'ok' => true,
    'inspected' => $count,
    'purged' => $purged,
    'victim_ids' => array_map(fn($v) => (int)$v['id'], $victims),
    'victim_users' => array_unique(array_map(fn($v) => (int)$v['user_id'], $victims)),
], JSON_UNESCAPED_UNICODE);
