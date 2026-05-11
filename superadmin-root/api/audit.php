<?php
// GET /api/audit.php → log audit superadmin (50 derniers + filtres).
require_once __DIR__ . '/_lib.php';
sa_cors();
sa_require_super_admin();
$db = auth_db();

$action = trim((string)($_GET['action'] ?? ''));
$limit = max(1, min(500, (int)($_GET['limit'] ?? 100)));

$sql = "SELECT a.id, a.actor_user_id, u.email AS actor_email, a.action, a.target, a.payload, a.ip, a.created_at
        FROM superadmin_audit a LEFT JOIN auth_users u ON u.id=a.actor_user_id";
$args = [];
if ($action) { $sql .= " WHERE a.action LIKE ?"; $args[] = '%' . $action . '%'; }
$sql .= " ORDER BY a.id DESC LIMIT $limit";
$st = $db->prepare($sql);
$st->execute($args);
$rows = $st->fetchAll();
foreach ($rows as &$r) {
    if ($r['payload']) $r['payload'] = json_decode($r['payload'], true);
}
sa_send_json(['ok' => true, 'entries' => $rows, 'count' => count($rows)]);
