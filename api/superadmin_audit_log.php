<?php
// M/2026/05/11/25 — Lecture historique audit super_admin_events.
//   GET ?action=list&limit=200 → 200 derniers événements.
// Schema reel super_admin_events : id, super_admin_user_id, action, target_workspace_id, payload_json, created_at.
require_once __DIR__ . '/lib/router.php';
header('Content-Type: application/json; charset=utf-8');

function al_out(array $d, int $code = 200) { http_response_code($code); echo json_encode($d, JSON_UNESCAPED_UNICODE); exit; }

$user = current_user_or_401();
if (($user['role'] ?? '') !== 'super_admin') al_out(['ok' => false, 'error' => 'super_admin only'], 403);

require_once __DIR__ . '/db.php';
$meta = pdo_meta();

$limit = max(1, min(500, (int) ($_GET['limit'] ?? 200)));
$actionFilter = trim((string) ($_GET['action_filter'] ?? ''));
$emailFilter = trim((string) ($_GET['email'] ?? ''));

$sql = "SELECT e.id, e.super_admin_user_id AS actor_id, u.email AS actor_email,
               e.action, e.target_workspace_id, e.payload_json AS detail, e.created_at
        FROM super_admin_events e LEFT JOIN users u ON u.id = e.super_admin_user_id";
$where = []; $args = [];
if ($actionFilter !== '') { $where[] = "e.action LIKE ?"; $args[] = '%' . $actionFilter . '%'; }
if ($emailFilter !== '') { $where[] = "u.email LIKE ?"; $args[] = '%' . $emailFilter . '%'; }
if ($where) $sql .= " WHERE " . implode(' AND ', $where);
$sql .= " ORDER BY e.id DESC LIMIT $limit";

try {
    $st = $meta->prepare($sql);
    $st->execute($args);
    $rows = $st->fetchAll(PDO::FETCH_ASSOC);
    foreach ($rows as &$r) {
        if (!empty($r['detail'])) {
            $j = json_decode($r['detail'], true);
            if ($j !== null) $r['detail'] = $j;
        }
        $r['ip'] = null; // colonne absente du schema legacy
    }
    al_out(['ok' => true, 'entries' => $rows, 'count' => count($rows)]);
} catch (Throwable $e) {
    al_out(['ok' => false, 'error' => $e->getMessage()], 500);
}
