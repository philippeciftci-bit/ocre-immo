<?php
// M/2026/05/11/28 — Lecture historique audit UNION audit_logs (ocre_meta) + super_admin_events (ocre_meta).
// Utilise pdo_meta() directement (DB préservée par reset_total, contrairement à db() qui pointe sur ocre_wsp_<slug>).
require_once __DIR__ . '/lib/router.php';
header('Content-Type: application/json; charset=utf-8');

function al_out(array $d, int $code = 200) { http_response_code($code); echo json_encode($d, JSON_UNESCAPED_UNICODE); exit; }

$user = current_user_or_401();
if (($user['role'] ?? '') !== 'super_admin') al_out(['ok' => false, 'error' => 'super_admin only'], 403);

require_once __DIR__ . '/db.php';
$meta = pdo_meta();

// Garantit que audit_logs existe en ocre_meta (idempotent, swallow si echec).
try {
    $meta->exec("CREATE TABLE IF NOT EXISTS audit_logs (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        user_id INT UNSIGNED NOT NULL,
        action VARCHAR(64) NOT NULL,
        payload JSON,
        ip_address VARCHAR(45),
        user_agent VARCHAR(256),
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_user (user_id), INDEX idx_action (action), INDEX idx_created (created_at)
    ) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
} catch (Throwable $e) { /* swallow */ }

$limit = max(1, min(500, (int) ($_GET['limit'] ?? 200)));
$actionFilter = trim((string) ($_GET['action_filter'] ?? ''));
$emailFilter = trim((string) ($_GET['email'] ?? ''));

$entries = [];

// Source 1 : audit_logs (moderne, ocre_meta)
try {
    $sql = "SELECT a.id, a.user_id AS actor_id, u.email AS actor_email,
                   a.action, a.payload AS detail, a.created_at, a.ip_address AS ip,
                   'audit_logs' AS source
            FROM audit_logs a LEFT JOIN users u ON u.id = a.user_id";
    $where = []; $args = [];
    if ($actionFilter !== '') { $where[] = "a.action LIKE ?"; $args[] = '%' . $actionFilter . '%'; }
    if ($emailFilter !== '') { $where[] = "u.email LIKE ?"; $args[] = '%' . $emailFilter . '%'; }
    if ($where) $sql .= " WHERE " . implode(' AND ', $where);
    $sql .= " ORDER BY a.id DESC LIMIT $limit";
    $st = $meta->prepare($sql); $st->execute($args);
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
        if (!empty($r['detail'])) { $j = json_decode($r['detail'], true); if ($j !== null) $r['detail'] = $j; }
        $entries[] = $r;
    }
} catch (Throwable $e) { /* swallow source 1 */ }

// Source 2 : super_admin_events (legacy, ocre_meta)
try {
    $sql = "SELECT e.id, e.super_admin_user_id AS actor_id, u.email AS actor_email,
                   e.action, e.payload_json AS detail, e.created_at, NULL AS ip,
                   'super_admin_events' AS source
            FROM super_admin_events e LEFT JOIN users u ON u.id = e.super_admin_user_id";
    $where = []; $args = [];
    if ($actionFilter !== '') { $where[] = "e.action LIKE ?"; $args[] = '%' . $actionFilter . '%'; }
    if ($emailFilter !== '') { $where[] = "u.email LIKE ?"; $args[] = '%' . $emailFilter . '%'; }
    if ($where) $sql .= " WHERE " . implode(' AND ', $where);
    $sql .= " ORDER BY e.id DESC LIMIT $limit";
    $st = $meta->prepare($sql); $st->execute($args);
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
        if (!empty($r['detail'])) { $j = json_decode($r['detail'], true); if ($j !== null) $r['detail'] = $j; }
        $entries[] = $r;
    }
} catch (Throwable $e) { /* swallow source 2 */ }

// Sort desc by created_at + clip to limit
usort($entries, fn($a, $b) => strcmp($b['created_at'] ?? '', $a['created_at'] ?? ''));
$entries = array_slice($entries, 0, $limit);

al_out(['ok' => true, 'entries' => $entries, 'count' => count($entries)]);
