<?php
// V20 phase 10 — console super-admin (lecture seule globale).
require_once __DIR__ . '/lib/router.php';
header('Content-Type: application/json; charset=utf-8');

function jout(array $d, int $code = 200) {
    http_response_code($code);
    echo json_encode($d, JSON_UNESCAPED_UNICODE);
    exit;
}

$user = current_user_or_401();
if (($user['role'] ?? '') !== 'super_admin') jout(['ok' => false, 'error' => 'super_admin only'], 403);

$action = $_GET['action'] ?? 'overview';
$meta = pdo_meta();

switch ($action) {
case 'overview': {
    $stats = [
        'workspaces' => (int)$meta->query("SELECT COUNT(*) FROM workspaces WHERE archived_at IS NULL")->fetchColumn(),
        'wsp_active' => (int)$meta->query("SELECT COUNT(*) FROM workspaces WHERE type='wsp' AND archived_at IS NULL")->fetchColumn(),
        'wsc_active' => (int)$meta->query("SELECT COUNT(*) FROM workspaces WHERE type='wsc' AND archived_at IS NULL")->fetchColumn(),
        'users' => (int)$meta->query("SELECT COUNT(*) FROM users WHERE archived_at IS NULL")->fetchColumn(),
        'sessions_active' => (int)$meta->query("SELECT COUNT(*) FROM sessions WHERE expires_at > NOW()")->fetchColumn(),
        'pending_ruptures' => (int)$meta->query("SELECT COUNT(*) FROM rupture_requests WHERE cancelled_at IS NULL AND executed_at IS NULL")->fetchColumn(),
        'super_admin_actions_24h' => (int)$meta->query("SELECT COUNT(*) FROM super_admin_events WHERE created_at >= DATE_SUB(NOW(), INTERVAL 1 DAY)")->fetchColumn(),
    ];
    // log
    $meta->prepare("INSERT INTO super_admin_events (super_admin_user_id, action, created_at) VALUES (?, 'overview', NOW())")->execute([$user['id']]);
    jout(['ok' => true, 'stats' => $stats]);
}

case 'workspaces': {
    $rows = $meta->query(
        "SELECT w.id, w.slug, w.type, w.display_name, w.country_code, w.created_at, w.archived_at,
                (SELECT COUNT(*) FROM workspace_members m WHERE m.workspace_id = w.id AND m.left_at IS NULL) AS members_count
         FROM workspaces w ORDER BY w.created_at DESC"
    )->fetchAll();
    $meta->prepare("INSERT INTO super_admin_events (super_admin_user_id, action, created_at) VALUES (?, 'workspaces_list', NOW())")->execute([$user['id']]);
    jout(['ok' => true, 'workspaces' => $rows]);
}

case 'audit_recent': {
    $rows = $meta->query(
        "SELECT a.*, u.email, w.slug AS workspace_slug FROM audit_log a
         LEFT JOIN users u ON u.id = a.actor_user_id
         LEFT JOIN workspaces w ON w.id = a.workspace_id
         ORDER BY a.created_at DESC LIMIT 200"
    )->fetchAll();
    $meta->prepare("INSERT INTO super_admin_events (super_admin_user_id, action, created_at) VALUES (?, 'audit_view', NOW())")->execute([$user['id']]);
    jout(['ok' => true, 'events' => $rows]);
}

default:
    jout(['ok' => false, 'error' => 'action inconnue'], 400);
}
