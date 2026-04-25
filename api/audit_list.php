<?php
// V52 — Liste paginée des audit_log pour l'utilisateur courant (ou admin, scope global).
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/_audit.php';
setCorsHeaders();
$user = requireAuth();
auditEnsureSchema();

$limit = max(1, min(200, (int)($_GET['limit'] ?? 50)));
$offset = max(0, (int)($_GET['offset'] ?? 0));
$table = trim((string)($_GET['table'] ?? ''));
$record_id = (int)($_GET['record_id'] ?? 0);
$is_admin = !empty($user['is_admin']) || ($user['role'] ?? '') === 'admin';

$where = [];
$params = [];
if (!$is_admin) { $where[] = 'a.user_id = ?'; $params[] = (int)$user['id']; }
if ($table) { $where[] = 'a.table_name = ?'; $params[] = $table; }
if ($record_id) { $where[] = 'a.record_id = ?'; $params[] = $record_id; }
$wsql = $where ? 'WHERE ' . implode(' AND ', $where) : '';

$sql = "SELECT a.id, a.user_id, u.email, a.table_name, a.record_id, a.action,
               a.before_state, a.after_state, a.created_at, a.ip
        FROM audit_log a LEFT JOIN users u ON u.id = a.user_id
        $wsql ORDER BY a.created_at DESC LIMIT $limit OFFSET $offset";
$stmt = db()->prepare($sql);
$stmt->execute($params);
$rows = $stmt->fetchAll();
foreach ($rows as &$r) {
    $r['before_state'] = $r['before_state'] ? json_decode($r['before_state'], true) : null;
    $r['after_state']  = $r['after_state']  ? json_decode($r['after_state'],  true) : null;
}
jsonOk(['entries' => $rows, 'limit' => $limit, 'offset' => $offset]);
