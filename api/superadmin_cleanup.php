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

if ($action === 'reset_users') {
    // M/2026/05/08/47 — DELETE users role != super_admin AND id != current super_admin.
    if (($input['confirmation'] ?? '') !== 'RESET') jout(['ok' => false, 'error' => 'confirmation RESET required'], 400);
    $superAdminId = (int)$user['id'];
    try {
        $del = $meta->prepare("DELETE FROM users WHERE role != 'super_admin' AND id != ?");
        $del->execute([$superAdminId]);
        $n = $del->rowCount();
        // Cleanup orphan workspace_members + sessions des users supprimés (cascade soft).
        @$meta->exec("DELETE FROM workspace_members WHERE user_id NOT IN (SELECT id FROM users)");
        @$meta->exec("DELETE FROM sessions WHERE user_id NOT IN (SELECT id FROM users)");
        _audit_log($LOG, $superAdminId, 'reset_users', ['deleted' => $n]);
        _audit_telegram('reset_users', ['deleted' => $n, 'by' => $user['email']]);
        jout(['ok' => true, 'deleted' => $n]);
    } catch (Throwable $e) {
        jout(['ok' => false, 'error' => $e->getMessage()], 500);
    }
}

if ($action === 'reset_sessions') {
    // M/2026/05/08/47 — DELETE TOUTES sessions sauf courante (token != current).
    if (($input['confirmation'] ?? '') !== 'RESET') jout(['ok' => false, 'error' => 'confirmation RESET required'], 400);
    try {
        $currentToken = (string)($_SERVER['HTTP_X_SESSION_TOKEN'] ?? '');
        $del = $meta->prepare("DELETE FROM sessions WHERE token != ?");
        $del->execute([$currentToken]);
        $n = $del->rowCount();
        _audit_log($LOG, (int)$user['id'], 'reset_sessions', ['deleted' => $n]);
        _audit_telegram('reset_sessions', ['deleted' => $n, 'by' => $user['email']]);
        jout(['ok' => true, 'deleted' => $n]);
    } catch (Throwable $e) {
        jout(['ok' => false, 'error' => $e->getMessage()], 500);
    }
}

if ($action === 'reset_clients') {
    // M/2026/05/08/47 — TRUNCATE clients dans CHAQUE DB tenant ocre_wsp_*. Préserve les workspaces eux-mêmes.
    if (($input['confirmation'] ?? '') !== 'RESET') jout(['ok' => false, 'error' => 'confirmation RESET required'], 400);
    $report = ['workspaces_processed' => 0, 'total_clients_deleted' => 0, 'errors' => []];
    try {
        $tenants = $meta->query("SHOW DATABASES LIKE 'ocre_wsp_%'")->fetchAll(PDO::FETCH_COLUMN);
        foreach ($tenants as $dbName) {
            if (!preg_match('/^ocre_wsp_[a-z0-9_-]+$/', $dbName)) continue;
            try {
                $tenantPdo = new PDO('mysql:host=' . DB_HOST . ';dbname=' . $dbName . ';charset=utf8mb4', DB_USER, DB_PASS,
                    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
                $cnt = (int)$tenantPdo->query("SELECT COUNT(*) FROM clients")->fetchColumn();
                $tenantPdo->exec("TRUNCATE TABLE clients");
                $report['total_clients_deleted'] += $cnt;
                $report['workspaces_processed']++;
            } catch (Throwable $e) {
                $report['errors'][] = $dbName . ': ' . $e->getMessage();
            }
        }
        _audit_log($LOG, (int)$user['id'], 'reset_clients', $report);
        _audit_telegram('reset_clients', array_merge($report, ['by' => $user['email']]));
        jout(['ok' => true, 'report' => $report]);
    } catch (Throwable $e) {
        jout(['ok' => false, 'error' => $e->getMessage()], 500);
    }
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
    // M/2026/05/08/34 — refonte FULL purge. Préserve uniquement super_admin Philippe.
    if (($input['confirmation'] ?? '') !== 'RESET TOTAL') jout(['ok' => false, 'error' => 'confirmation "RESET TOTAL" required'], 400);
    $superAdminId = (int)$user['id'];
    $report = [
        'workspaces_dropped' => 0,
        'workspaces_meta_deleted' => 0,
        'orphan_dbs_dropped' => 0,
        'users_deleted' => 0,
        'sessions_deleted' => 0,
        'pending_deleted' => 0,
        'audit_truncated' => false,
        'auto_increment_reset' => 0,
        'errors' => [],
    ];

    // 1. DROP DATABASE pour TOUS les workspaces meta (archived ou non)
    $st = $meta->query("SELECT id, slug FROM workspaces");
    foreach ($st->fetchAll() as $w) {
        $r = _drop_workspace_db($meta, (int)$w['id']);
        if (!empty($r['ok'])) {
            $report['workspaces_dropped']++;
            $report['workspaces_meta_deleted']++;
        } else {
            $report['errors'][] = 'workspace_id=' . $w['id'] . ' err=' . ($r['error'] ?? '?');
        }
    }

    // 2. DROP DATABASE orphelines (DBs ocre_wsp_* sans entrée meta correspondante)
    try {
        $orphans = $meta->query("SHOW DATABASES LIKE 'ocre_wsp_%'")->fetchAll(PDO::FETCH_COLUMN);
        foreach ($orphans as $dbName) {
            if (preg_match('/^ocre_wsp_[a-z0-9_-]+$/', $dbName)) {
                try {
                    $meta->exec("DROP DATABASE IF EXISTS `$dbName`");
                    $report['orphan_dbs_dropped']++;
                } catch (Throwable $e) {
                    $report['errors'][] = 'orphan_db=' . $dbName . ' err=' . $e->getMessage();
                }
            }
        }
    } catch (Throwable $e) {
        $report['errors'][] = 'show_databases err=' . $e->getMessage();
    }

    // 3. Cleanup workspaces meta table (idempotent même si déjà fait par _drop_workspace_db)
    try {
        $meta->exec("DELETE FROM workspaces");
        $meta->exec("DELETE FROM workspace_members");
        $meta->exec("DELETE FROM pact_signatures");
    } catch (Throwable $e) {
        $report['errors'][] = 'workspaces_meta err=' . $e->getMessage();
    }

    // 4. DELETE pending users (avant la délétion globale, pour compter)
    try {
        $del = $meta->prepare("DELETE FROM users WHERE status = 'pending_activation' AND id != ?");
        $del->execute([$superAdminId]);
        $report['pending_deleted'] = $del->rowCount();
    } catch (Throwable $e) {
        $report['errors'][] = 'pending err=' . $e->getMessage();
    }

    // 5. DELETE TOUS les users sauf super_admin Philippe
    try {
        $del = $meta->prepare("DELETE FROM users WHERE role != 'super_admin' AND id != ?");
        $del->execute([$superAdminId]);
        $report['users_deleted'] = $del->rowCount();
    } catch (Throwable $e) {
        $report['errors'][] = 'users err=' . $e->getMessage();
    }

    // 6. DELETE sessions :
    // M/2026/05/08/44 — étendu : purge AUSSI les sessions VPS internes même si user_id=super_admin
    //   (sessions curl/scripts CC qui s'accumulent, capture Philippe 12:19 montrait 4x curl/7.81.0).
    //   Garde-fou : préserve la session courante via token != $currentToken.
    try {
        $currentToken = (string)($_SERVER['HTTP_X_SESSION_TOKEN'] ?? '');
        $del = $meta->prepare(
            "DELETE FROM sessions
              WHERE token != ?
                AND (
                      user_id != ?
                   OR ip = '46.225.215.148'
                   OR user_agent LIKE 'curl%'
                   OR user_agent LIKE 'PHP-Curl%'
                   OR user_agent LIKE 'smoke-tests%'
                   OR user_agent = ''
                   OR user_agent IS NULL
                )"
        );
        $del->execute([$currentToken, $superAdminId]);
        $report['sessions_deleted'] = $del->rowCount();
    } catch (Throwable $e) {
        $report['errors'][] = 'sessions err=' . $e->getMessage();
    }

    // 7. TRUNCATE audit
    try {
        $meta->exec("TRUNCATE TABLE super_admin_events");
        $report['audit_truncated'] = true;
    } catch (Throwable $e) {
        $report['errors'][] = 'audit err=' . $e->getMessage();
    }

    // 8. ALTER TABLE AUTO_INCREMENT=1 sur les tables vidées
    foreach (['workspaces', 'workspace_members', 'pact_signatures', 'sessions', 'super_admin_events'] as $tbl) {
        try {
            $meta->exec("ALTER TABLE `$tbl` AUTO_INCREMENT = 1");
            $report['auto_increment_reset']++;
        } catch (Throwable $e) {
            $report['errors'][] = "auto_increment $tbl err=" . $e->getMessage();
        }
    }

    // 9. M/2026/05/11/24 — WIPE tables auth_* (DB V4 magic-link).
    //    Bug racine : reset_total nettoyait users legacy mais PAS auth_users → user
    //    reconnu sur auth.ocre.immo après reset. Préserve uniquement super-admin Philippe (par email).
    $report['auth_users_deleted'] = 0;
    $report['auth_tables_truncated'] = [];
    try {
        $del = $meta->prepare("DELETE FROM auth_users WHERE email != ?");
        $del->execute(['philippe.ciftci@gmail.com']);
        $report['auth_users_deleted'] = $del->rowCount();
    } catch (Throwable $e) {
        $report['errors'][] = 'auth_users err=' . $e->getMessage();
    }
    foreach (['auth_magic_tokens', 'auth_sessions', 'auth_user_modules', 'auth_refresh_tokens', 'superadmin_audit'] as $tbl) {
        try {
            $meta->exec("TRUNCATE TABLE `$tbl`");
            $report['auth_tables_truncated'][] = $tbl;
        } catch (Throwable $e) {
            $report['errors'][] = "auth $tbl err=" . $e->getMessage();
        }
    }

    _audit_log($LOG, $superAdminId, 'reset_total', $report);
    _audit_telegram('RESET TOTAL', array_merge($report, ['by' => $user['email']]));
    jout(['ok' => true, 'report' => $report]);
}

jout(['ok' => false, 'error' => 'unknown action: ' . $action], 400);
