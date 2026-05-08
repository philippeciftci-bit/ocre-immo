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
    // M/2026/05/08/48 — alignement sur super_admin_events (même table que le compteur Vue d'ensemble).
    // L'ancienne table audit_log était vide → tableau vide alors que compteur affichait 12+.
    $rows = $meta->query(
        "SELECT e.id, e.action, e.created_at, e.target_workspace_id, e.payload_json,
                u.email, u.role,
                w.slug AS workspace_slug
           FROM super_admin_events e
      LEFT JOIN users u ON u.id = e.super_admin_user_id
      LEFT JOIN workspaces w ON w.id = e.target_workspace_id
       ORDER BY e.created_at DESC LIMIT 200"
    )->fetchAll();
    // Adapter le format pour cohérence avec le frontend renderAuditTab (target_type / target_id legacy).
    $events = array_map(function($r) {
        return [
            'id' => (int)$r['id'],
            'created_at' => (string)$r['created_at'],
            'email' => (string)($r['email'] ?? '—'),
            'role' => (string)($r['role'] ?? '—'),
            'action' => (string)$r['action'],
            'workspace_slug' => (string)($r['workspace_slug'] ?? ''),
            'target_type' => $r['target_workspace_id'] ? 'workspace' : '',
            'target_id' => $r['target_workspace_id'],
            'payload' => $r['payload_json'] ? (string)$r['payload_json'] : '',
        ];
    }, $rows);
    $meta->prepare("INSERT INTO super_admin_events (super_admin_user_id, action, created_at) VALUES (?, 'audit_view', NOW())")->execute([$user['id']]);
    jout(['ok' => true, 'events' => $events]);
}

default:
    jout(['ok' => false, 'error' => 'action inconnue'], 400);
}
