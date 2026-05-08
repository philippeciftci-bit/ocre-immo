<?php
// M/2026/05/08/33 — Outils nettoyage super-admin pour phase test.
// Tous endpoints requièrent role=super_admin + confirmation textuelle "RESET" / "RESET TOTAL".
// Audit trail : log persistant /var/log/ocre-superadmin-actions.log + notif Telegram.
//
// Actions :
//   POST {action: 'delete_workspaces',   ids: [int,...]}                  → DROP DB ocre_wsp_<slug> + DELETE meta
//   POST {action: 'delete_users_batch',  ids: [int,...]}                  → DELETE users (batch)
//   POST {action: 'reset_pending',       confirmation: 'RESET'}           → DELETE users WHERE status=pending
//   POST {action: 'reset_workspaces',    confirmation: 'RESET'}           → DROP toutes DB ocre_wsp_* + truncate meta
//   POST {action: 'reset_audit',         confirmation: 'RESET'}           → TRUNCATE super_admin_events
//   POST {action: 'reset_total',         confirmation: 'RESET TOTAL'}     → cumul reset_pending + reset_workspaces + reset_audit

require_once __DIR__ . '/lib/router.php';
header('Content-Type: application/json; charset=utf-8');

function jout(array $d, int $code = 200) {
    http_response_code($code);
    echo json_encode($d, JSON_UNESCAPED_UNICODE);
    exit;
}

$user = current_user_or_401();
if (($user['role'] ?? '') !== 'super_admin') jout(['ok' => false, 'error' => 'super_admin only'], 403);

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
if ($method !== 'POST') jout(['ok' => false, 'error' => 'method not allowed'], 405);

$raw = file_get_contents('php://input');
$input = json_decode($raw, true);
$input = is_array($input) ? $input : [];
$action = (string)($input['action'] ?? '');

$meta = pdo_meta();
$LOG = '/var/log/ocre-superadmin-actions.log';
@touch($LOG); @chmod($LOG, 0664);

function _audit_log(string $LOG, int $superadminId, string $action, array $detail): void {
    $line = "[" . date('c') . "] sa#" . $superadminId . " " . $action . " " . json_encode($detail, JSON_UNESCAPED_UNICODE) . "\n";
    @file_put_contents($LOG, $line, FILE_APPEND);
}

function _audit_telegram(string $action, array $detail): void {
    $body = $action . " : " . json_encode($detail, JSON_UNESCAPED_UNICODE);
    @shell_exec(
        '/root/bin/notify --project ocre --priority high --phase warn '
        . '--mission-id ' . escapeshellarg('SUPERADMIN-CLEANUP/' . time())
        . ' --title ' . escapeshellarg('[OCRE] Super-admin action destructive')
        . ' --body ' . escapeshellarg(substr($body, 0, 1000))
        . ' >/dev/null 2>&1 &'
    );
}

function _drop_workspace_db(PDO $meta, int $id): array {
    $st = $meta->prepare("SELECT id, slug, type, display_name FROM workspaces WHERE id = ? LIMIT 1");
    $st->execute([$id]);
    $w = $st->fetch();
    if (!$w) return ['ok' => false, 'id' => $id, 'error' => 'not_found'];
    $slug = (string)$w['slug'];
    if (!preg_match('/^[a-z0-9_-]+$/', $slug)) return ['ok' => false, 'id' => $id, 'error' => 'invalid_slug'];
    $dbName = 'ocre_wsp_' . $slug;
    try {
        // DROP DATABASE pour le tenant
        $meta->exec("DROP DATABASE IF EXISTS `$dbName`");
        // Cleanup tables meta references workspace
        $meta->prepare("DELETE FROM workspace_members WHERE workspace_id = ?")->execute([$id]);
        $meta->prepare("DELETE FROM pact_signatures WHERE wsc_id = ?")->execute([$id]);
        $meta->prepare("DELETE FROM workspaces WHERE id = ?")->execute([$id]);
        return ['ok' => true, 'id' => $id, 'slug' => $slug, 'db_dropped' => $dbName];
    } catch (Throwable $e) {
        return ['ok' => false, 'id' => $id, 'slug' => $slug, 'error' => $e->getMessage()];
    }
}

// === ACTIONS ===

if ($action === 'delete_workspaces') {
    $ids = array_values(array_filter(array_map('intval', (array)($input['ids'] ?? [])), fn($x) => $x > 0));
    if (empty($ids)) jout(['ok' => false, 'error' => 'ids required'], 400);
    $results = array_map(fn($id) => _drop_workspace_db($meta, $id), $ids);
    $okCount = count(array_filter($results, fn($r) => !empty($r['ok'])));
    _audit_log($LOG, (int)$user['id'], 'delete_workspaces', ['ids' => $ids, 'ok_count' => $okCount, 'results' => $results]);
    _audit_telegram('delete_workspaces', ['count' => $okCount, 'ids' => $ids, 'by' => $user['email']]);
    jout(['ok' => true, 'deleted' => $okCount, 'total' => count($ids), 'results' => $results]);
}

if ($action === 'delete_users_batch') {
    $ids = array_values(array_filter(array_map('intval', (array)($input['ids'] ?? [])), fn($x) => $x > 0));
    if (empty($ids)) jout(['ok' => false, 'error' => 'ids required'], 400);
    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $sel = $meta->prepare("SELECT id, email FROM users WHERE id IN ($placeholders)");
    $sel->execute($ids);
    $rows = $sel->fetchAll();
    $del = $meta->prepare("DELETE FROM users WHERE id IN ($placeholders)");
    $del->execute($ids);
    _audit_log($LOG, (int)$user['id'], 'delete_users_batch', ['ids' => $ids, 'deleted' => $del->rowCount(), 'emails' => array_column($rows, 'email')]);
    _audit_telegram('delete_users_batch', ['count' => $del->rowCount(), 'ids' => $ids, 'by' => $user['email']]);
    jout(['ok' => true, 'deleted' => $del->rowCount()]);
}

if ($action === 'reset_pending') {
    if (($input['confirmation'] ?? '') !== 'RESET') jout(['ok' => false, 'error' => 'confirmation RESET required'], 400);
    $del = $meta->prepare("DELETE FROM users WHERE status = 'pending_activation' AND archived_at IS NULL");
    $del->execute();
    $n = $del->rowCount();
    _audit_log($LOG, (int)$user['id'], 'reset_pending', ['deleted' => $n]);
    _audit_telegram('reset_pending', ['deleted' => $n, 'by' => $user['email']]);
    jout(['ok' => true, 'deleted' => $n]);
}

if ($action === 'reset_workspaces') {
    if (($input['confirmation'] ?? '') !== 'RESET') jout(['ok' => false, 'error' => 'confirmation RESET required'], 400);
    $st = $meta->query("SELECT id, slug FROM workspaces WHERE archived_at IS NULL");
    $all = $st->fetchAll();
    $results = [];
    foreach ($all as $w) $results[] = _drop_workspace_db($meta, (int)$w['id']);
    $okCount = count(array_filter($results, fn($r) => !empty($r['ok'])));
    _audit_log($LOG, (int)$user['id'], 'reset_workspaces', ['count' => $okCount, 'total' => count($all)]);
    _audit_telegram('reset_workspaces', ['count' => $okCount, 'by' => $user['email']]);
    jout(['ok' => true, 'deleted' => $okCount, 'total' => count($all)]);
}

if ($action === 'reset_audit') {
    if (($input['confirmation'] ?? '') !== 'RESET') jout(['ok' => false, 'error' => 'confirmation RESET required'], 400);
    try {
        $meta->exec("TRUNCATE TABLE super_admin_events");
        _audit_log($LOG, (int)$user['id'], 'reset_audit', ['ok' => true]);
        _audit_telegram('reset_audit', ['by' => $user['email']]);
        jout(['ok' => true, 'truncated' => 'super_admin_events']);
    } catch (Throwable $e) {
        jout(['ok' => false, 'error' => $e->getMessage()], 500);
    }
}

if ($action === 'reset_total') {
    if (($input['confirmation'] ?? '') !== 'RESET TOTAL') jout(['ok' => false, 'error' => 'confirmation "RESET TOTAL" required'], 400);
    $report = ['pending' => 0, 'workspaces' => 0, 'audit' => false];
    // 1. pending
    $del = $meta->prepare("DELETE FROM users WHERE status = 'pending_activation' AND archived_at IS NULL");
    $del->execute();
    $report['pending'] = $del->rowCount();
    // 2. workspaces
    $st = $meta->query("SELECT id, slug FROM workspaces WHERE archived_at IS NULL");
    foreach ($st->fetchAll() as $w) {
        $r = _drop_workspace_db($meta, (int)$w['id']);
        if (!empty($r['ok'])) $report['workspaces']++;
    }
    // 3. audit
    try { $meta->exec("TRUNCATE TABLE super_admin_events"); $report['audit'] = true; } catch (Throwable $e) { $report['audit'] = false; }
    _audit_log($LOG, (int)$user['id'], 'reset_total', $report);
    _audit_telegram('RESET TOTAL', array_merge($report, ['by' => $user['email']]));
    jout(['ok' => true, 'report' => $report]);
}

jout(['ok' => false, 'error' => 'unknown action: ' . $action], 400);
