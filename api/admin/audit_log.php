<?php
// M/2026/04/28/66 — Endpoint admin audit_log : list + filters + export CSV.
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/_audit.php';
setCorsHeaders();

$user = requireAuth();
$isSuper = ($user['role'] ?? '') === 'super_admin' || ($user['_origin_role'] ?? '') === 'super_admin';
if (!$isSuper) jsonError('Accès refusé (super_admin requis)', 403);

$action = $_GET['action'] ?? 'list';
audit_log_ensure_schema();
$dsn = 'mysql:host=' . DB_HOST . ';dbname=ocre_meta;charset=utf8mb4';
$pdo = new PDO($dsn, DB_USER, DB_PASS, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
]);

if ($action === 'list') {
    $userId = isset($_GET['user_id']) ? (int) $_GET['user_id'] : null;
    $actionPat = trim($_GET['action_pattern'] ?? '');
    $targetType = trim($_GET['target_type'] ?? '');
    $from = $_GET['from'] ?? null;
    $to = $_GET['to'] ?? null;
    $searchPayload = trim($_GET['search_payload'] ?? '');
    $limit = min((int) ($_GET['limit'] ?? 50), 500);
    $offset = max(0, (int) ($_GET['offset'] ?? 0));

    $where = [];
    $params = [];
    if ($userId) { $where[] = 'user_id = ?'; $params[] = $userId; }
    if ($actionPat) { $where[] = 'action LIKE ?'; $params[] = str_replace('*', '%', $actionPat); }
    if ($targetType) { $where[] = 'target_type = ?'; $params[] = $targetType; }
    if ($from) { $where[] = 'created_at >= ?'; $params[] = $from; }
    if ($to) { $where[] = 'created_at <= ?'; $params[] = $to; }
    if ($searchPayload) { $where[] = 'payload LIKE ?'; $params[] = '%' . $searchPayload . '%'; }
    $sql = "SELECT * FROM audit_log";
    if ($where) $sql .= ' WHERE ' . implode(' AND ', $where);
    $sql .= " ORDER BY created_at DESC LIMIT $limit OFFSET $offset";

    $st = $pdo->prepare($sql);
    $st->execute($params);
    $rows = $st->fetchAll();

    // Total count
    $totalSql = "SELECT COUNT(*) FROM audit_log";
    if ($where) $totalSql .= ' WHERE ' . implode(' AND ', $where);
    $stt = $pdo->prepare($totalSql);
    $stt->execute($params);
    $total = (int) $stt->fetchColumn();

    jsonOk(['logs' => $rows, 'total' => $total, 'limit' => $limit, 'offset' => $offset]);
}

if ($action === 'export_csv') {
    $userId = isset($_GET['user_id']) ? (int) $_GET['user_id'] : null;
    $actionPat = trim($_GET['action_pattern'] ?? '');
    $from = $_GET['from'] ?? date('Y-m-d', strtotime('-30 days'));
    $to = $_GET['to'] ?? date('Y-m-d');

    $where = ['created_at >= ?', 'created_at <= ?'];
    $params = [$from . ' 00:00:00', $to . ' 23:59:59'];
    if ($userId) { $where[] = 'user_id = ?'; $params[] = $userId; }
    if ($actionPat) { $where[] = 'action LIKE ?'; $params[] = str_replace('*', '%', $actionPat); }
    $sql = "SELECT * FROM audit_log WHERE " . implode(' AND ', $where) . " ORDER BY created_at DESC LIMIT 5000";
    $st = $pdo->prepare($sql);
    $st->execute($params);

    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="audit-' . $from . '-' . $to . '.csv"');
    echo "\xEF\xBB\xBF";
    echo "id;created_at;user_id;action;target_type;target_id;ip;user_agent;payload\r\n";
    while ($r = $st->fetch()) {
        $cols = [
            $r['id'], $r['created_at'], $r['user_id'], $r['action'],
            $r['target_type'] ?? '', $r['target_id'] ?? '',
            $r['ip'] ?? '', mb_substr($r['user_agent'] ?? '', 0, 100),
            str_replace(["\r", "\n", '"'], [' ', ' ', "''"], (string) ($r['payload'] ?? '')),
        ];
        echo implode(';', array_map(fn($c) => '"' . str_replace('"', '""', (string) $c) . '"', $cols)) . "\r\n";
    }
    exit;
}

jsonError('Action inconnue (list | export_csv)', 400);
