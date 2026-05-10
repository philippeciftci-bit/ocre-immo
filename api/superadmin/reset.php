<?php
// M_OCRE_RESET_TOTAL — Alias + extension de api/superadmin/maintenance.php (M_SUPERADMIN_RESET M48)
// Adds : action=reset_dossiers (TRUNCATE par tenant_slug uniquement tables dossier-related)
//        action=backup_now (alias backup_create), list_backups (alias backups_list), restore_backup (alias restore)
//        action=reset_total (alias reset_with_backup level=2 + recreate tenant test exbat-tat-ad7d)
// Endpoints (toutes super_admin requises) :
//   POST ?action=backup_now        → mysqldump (alias)
//   GET  ?action=list_backups      → liste backups (alias)
//   POST ?action=reset_dossiers    body {tenant_slug, confirmation_word: 'RESET'} → TRUNCATE tables dossiers/photos/documents/matches/pacts du tenant
//   POST ?action=reset_tenant      body {tenant_slug, confirmation_word: 'RESET'} → DROP+CREATE DATABASE ocre_wsp_<slug>
//   POST ?action=reset_total       body {confirmation_word: 'RESET TOTAL'} → reset niveau 2 + recreate tenant test
//   POST ?action=restore_backup    body {filename, confirmation_word: 'RESTORE'} → alias restore
require_once __DIR__ . '/../lib/router.php';
require_once __DIR__ . '/../lib/audit_logs.php';
header('Content-Type: application/json; charset=utf-8');

function rout(array $d, int $code = 200) { http_response_code($code); echo json_encode($d, JSON_UNESCAPED_UNICODE); exit; }

$user = current_user_or_401();
if (($user['role'] ?? '') !== 'super_admin') rout(['ok'=>false,'error'=>'super_admin only'], 403);

$action = $_GET['action'] ?? ($_POST['action'] ?? '');
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$raw = file_get_contents('php://input');
$input = json_decode($raw, true) ?: [];

const RBACKUP_DIR = '/var/backups/ocre-meta';
const RESET_LOG = '/var/log/ocre-reset-actions.log';

@touch(RESET_LOG); @chmod(RESET_LOG, 0640);
if (!is_dir(RBACKUP_DIR)) @mkdir(RBACKUP_DIR, 0750, true);

function reset_log(int $sid, string $action, array $detail): void {
    $line = "[" . date('c') . "] sa#$sid $action " . json_encode($detail, JSON_UNESCAPED_UNICODE) . " ip=" . ($_SERVER['REMOTE_ADDR'] ?? '?') . "\n";
    @file_put_contents(RESET_LOG, $line, FILE_APPEND);
    audit_log_insert($sid, 'reset.' . $action, $detail);
}

function reset_telegram(string $action, array $detail): void {
    $body = "[$action] " . json_encode($detail, JSON_UNESCAPED_UNICODE);
    @shell_exec('/root/bin/notify --project ocre --priority high --phase warn '
        . '--title ' . escapeshellarg('[OCRE] Reset super-admin')
        . ' --body ' . escapeshellarg(substr($body, 0, 1000))
        . ' >/dev/null 2>&1 &');
}

function do_dump(string $outFile): array {
    $cnf = '/root/.secrets/mysql_dump.cnf';
    $start = microtime(true);
    $cmd = is_file($cnf)
        ? "mysqldump --defaults-extra-file=" . escapeshellarg($cnf) . " --all-databases --single-transaction --triggers --routines --quick 2>&1 | gzip > " . escapeshellarg($outFile)
        : "mysqldump -u" . escapeshellarg(DB_USER) . " -p" . escapeshellarg(DB_PASS) . " --all-databases --single-transaction --triggers --routines --quick 2>&1 | gzip > " . escapeshellarg($outFile);
    $rc = 0; $out = []; @exec($cmd, $out, $rc);
    return ['ok'=>($rc===0 && @filesize($outFile)>1024), 'size'=>@filesize($outFile), 'duration_ms'=>(int)round((microtime(true)-$start)*1000), 'rc'=>$rc, 'output'=>implode("\n", array_slice($out,-5))];
}

function valid_slug(string $s): string {
    return preg_replace('/[^a-z0-9_-]/', '', strtolower($s));
}

function tenant_pdo(string $slug): ?PDO {
    $dbName = 'ocre_wsp_' . valid_slug($slug);
    try {
        return new PDO('mysql:host=' . DB_HOST . ';dbname=' . $dbName . ';charset=utf8mb4', DB_USER, DB_PASS,
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    } catch (Throwable $e) { return null; }
}

switch ($action) {

case 'backup_now': {
    if ($method !== 'POST') rout(['ok'=>false,'error'=>'method'], 405);
    $ts = date('Ymd-His');
    $file = RBACKUP_DIR . '/manual-' . $ts . '.sql.gz';
    $r = do_dump($file);
    @chmod($file, 0600);
    if (!$r['ok']) { reset_log((int)$user['id'], 'backup_failed', $r); rout(['ok'=>false,'error'=>'mysqldump failed','detail'=>$r], 500); }
    reset_log((int)$user['id'], 'backup_now', ['file'=>$file,'size'=>$r['size'],'duration_ms'=>$r['duration_ms']]);
    rout(['ok'=>true, 'file_path'=>$file, 'size_bytes'=>$r['size'], 'duration_ms'=>$r['duration_ms'], 'message'=>'Backup créé : ' . round($r['size']/1048576,1) . ' Mo']);
}

case 'list_backups': {
    $files = glob(RBACKUP_DIR . '/*.sql.gz') ?: [];
    usort($files, fn($a,$b) => @filemtime($b) - @filemtime($a));
    $files = array_slice($files, 0, 20);
    $rows = array_map(function($f) {
        $base = basename($f);
        $type = preg_match('/^([a-z0-9-]+?)-\d/', $base, $m) ? $m[1] : 'unknown';
        return ['filename'=>$base, 'size_bytes'=>@filesize($f), 'mtime'=>@filemtime($f), 'iso'=>date('c', (int)@filemtime($f)), 'type'=>$type];
    }, $files);
    rout(['ok'=>true, 'backups'=>$rows]);
}

case 'reset_dossiers': {
    if ($method !== 'POST') rout(['ok'=>false,'error'=>'method'], 405);
    $slug = valid_slug((string)($input['tenant_slug'] ?? ''));
    $confirm = (string)($input['confirmation_word'] ?? '');
    if (!$slug) rout(['ok'=>false,'error'=>'tenant_slug requis'], 400);
    if ($confirm !== 'RESET') rout(['ok'=>false,'error'=>'confirmation_word doit etre "RESET"'], 400);

    // Backup auto pre-reset
    $ts = date('Ymd-His');
    $backup = RBACKUP_DIR . "/pre-reset-dossiers-$slug-$ts.sql.gz";
    $r = do_dump($backup);
    @chmod($backup, 0600);
    if (!$r['ok']) { reset_log((int)$user['id'], 'reset_dossiers_aborted_backup_failed', ['slug'=>$slug,'detail'=>$r]); rout(['ok'=>false,'error'=>'Backup pre-reset echoue, RESET ANNULE','detail'=>$r], 500); }
    reset_log((int)$user['id'], 'reset_dossiers_backup_ok', ['file'=>$backup,'slug'=>$slug,'size'=>$r['size']]);

    // TRUNCATE tables dossier-related dans la DB tenant
    $pdo = tenant_pdo($slug);
    if (!$pdo) {
        // Fallback : dossiers stockes dans ocre_meta (table clients filtree par user)
        // Dans ce cas TRUNCATE par tenant n est pas possible sans suppression ciblee user_id IN (tenant users)
        // On utilise donc une approche hybride : delete clients/matches/documents WHERE user_id IN (users du tenant)
        $tenantUserIds = [];
        try {
            $stt = db()->prepare("SELECT id FROM users WHERE tenant_slug = ?");
            $stt->execute([$slug]);
            $tenantUserIds = $stt->fetchAll(PDO::FETCH_COLUMN);
        } catch (Throwable $e) { /* table users sans tenant_slug = legacy non-multitenant */ }
        $deleted = ['clients'=>0, 'matches'=>0, 'documents'=>0];
        if ($tenantUserIds) {
            $ph = implode(',', array_fill(0, count($tenantUserIds), '?'));
            foreach (['matches','documents','clients'] as $t) {
                try { $st = db()->prepare("DELETE FROM `$t` WHERE owner_user_id IN ($ph) OR user_id IN ($ph)"); $st->execute(array_merge($tenantUserIds, $tenantUserIds)); $deleted[$t] = $st->rowCount(); } catch (Throwable $e) {}
            }
        }
        reset_log((int)$user['id'], 'reset_dossiers_meta_fallback', ['slug'=>$slug,'tenant_users'=>count($tenantUserIds),'deleted'=>$deleted,'backup'=>$backup]);
        reset_telegram('reset_dossiers', ['slug'=>$slug,'mode'=>'meta_fallback','deleted'=>$deleted,'backup'=>basename($backup)]);
        rout(['ok'=>true, 'mode'=>'meta_fallback', 'tenant_user_count'=>count($tenantUserIds), 'rows_deleted'=>$deleted, 'backup_file'=>basename($backup)]);
    }
    // Tenant DB existe : TRUNCATE tables candidates
    $tablesCandidates = ['dossiers','photos','documents','matchings','matches','pacts','propositions','dossier_comments','dossier_versions','dossier_presence','dossier_followers','realtime_events'];
    $truncated = []; $errors = [];
    foreach ($tablesCandidates as $t) {
        try { $pdo->exec("TRUNCATE TABLE `$t`"); $truncated[] = $t; } catch (Throwable $e) { $errors[$t] = $e->getMessage(); }
    }
    reset_log((int)$user['id'], 'reset_dossiers_truncate', ['slug'=>$slug,'truncated'=>$truncated,'skipped'=>array_keys($errors),'backup'=>$backup]);
    reset_telegram('reset_dossiers', ['slug'=>$slug,'truncated_count'=>count($truncated),'backup'=>basename($backup)]);
    rout(['ok'=>true, 'mode'=>'tenant_db', 'tables_truncated'=>$truncated, 'tables_skipped_or_absent'=>array_keys($errors), 'backup_file'=>basename($backup)]);
}

case 'reset_tenant': {
    if ($method !== 'POST') rout(['ok'=>false,'error'=>'method'], 405);
    $slug = valid_slug((string)($input['tenant_slug'] ?? ''));
    $confirm = (string)($input['confirmation_word'] ?? '');
    if (!$slug) rout(['ok'=>false,'error'=>'tenant_slug requis'], 400);
    if ($confirm !== 'RESET') rout(['ok'=>false,'error'=>'confirmation_word doit etre "RESET"'], 400);
    $ts = date('Ymd-His');
    $backup = RBACKUP_DIR . "/pre-reset-tenant-$slug-$ts.sql.gz";
    $r = do_dump($backup);
    @chmod($backup, 0600);
    if (!$r['ok']) rout(['ok'=>false,'error'=>'Backup pre-reset echoue, RESET ANNULE','detail'=>$r], 500);
    $dbName = 'ocre_wsp_' . $slug;
    try {
        $meta = pdo_meta();
        $meta->exec("DROP DATABASE IF EXISTS `$dbName`");
        $meta->exec("CREATE DATABASE `$dbName` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
        // Tenter import schema template
        $template = '/root/workspace/ocre-immo/db/tenant_template.sql';
        $imported = false;
        if (is_file($template)) {
            $cnf = '/root/.secrets/mysql_dump.cnf';
            $cmd = is_file($cnf)
                ? "mysql --defaults-extra-file=" . escapeshellarg($cnf) . " " . escapeshellarg($dbName) . " < " . escapeshellarg($template) . " 2>&1"
                : "mysql -u" . escapeshellarg(DB_USER) . " -p" . escapeshellarg(DB_PASS) . " " . escapeshellarg($dbName) . " < " . escapeshellarg($template) . " 2>&1";
            @exec($cmd, $oo, $rc);
            $imported = ($rc === 0);
        }
        reset_log((int)$user['id'], 'reset_tenant', ['slug'=>$slug,'db'=>$dbName,'template_imported'=>$imported,'backup'=>$backup]);
        reset_telegram('reset_tenant', ['slug'=>$slug,'template_imported'=>$imported,'backup'=>basename($backup)]);
        rout(['ok'=>true, 'tenant_db_recreated'=>$dbName, 'template_imported'=>$imported, 'backup_file'=>basename($backup)]);
    } catch (Throwable $e) {
        reset_log((int)$user['id'], 'reset_tenant_failed', ['slug'=>$slug,'error'=>$e->getMessage()]);
        rout(['ok'=>false,'error'=>'reset_tenant failed: ' . $e->getMessage(),'backup_file'=>basename($backup)], 500);
    }
}

case 'reset_total': {
    if ($method !== 'POST') rout(['ok'=>false,'error'=>'method'], 405);
    $confirm = (string)($input['confirmation_word'] ?? '');
    if ($confirm !== 'RESET TOTAL') rout(['ok'=>false,'error'=>'confirmation_word doit etre "RESET TOTAL" exactement'], 400);
    $ts = date('Ymd-His');
    $backup = RBACKUP_DIR . "/pre-reset-total-$ts.sql.gz";
    $r = do_dump($backup);
    @chmod($backup, 0600);
    if (!$r['ok']) rout(['ok'=>false,'error'=>'Backup pre-reset echoue, RESET ANNULE','detail'=>$r], 500);
    try {
        $meta = pdo_meta();
        $dbs = $meta->query("SHOW DATABASES LIKE 'ocre_wsp_%'")->fetchAll(PDO::FETCH_COLUMN);
        $droppedCount = 0;
        foreach ($dbs as $d) { try { $meta->exec("DROP DATABASE IF EXISTS `$d`"); $droppedCount++; } catch (Throwable $e) {} }
        // TRUNCATE meta tables sauf super_admin + feature_flags + settings + audit_logs
        $preserveTables = ['super_admin', 'super_admin_users', 'super_admin_events', 'feature_flags', 'settings', 'system_settings', 'audit_logs'];
        $allTables = $meta->query("SHOW TABLES")->fetchAll(PDO::FETCH_COLUMN);
        $truncatedCount = 0;
        foreach ($allTables as $t) {
            if (in_array($t, $preserveTables, true)) continue;
            try { $meta->exec("TRUNCATE TABLE `$t`"); $truncatedCount++; } catch (Throwable $e) {}
        }
        // Recreate tenant test exbat-tat-ad7d minimal
        try {
            $meta->exec("CREATE DATABASE IF NOT EXISTS `ocre_wsp_exbat-tat-ad7d` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
            $meta->exec("INSERT INTO users (email, role, tenant_slug, is_active, created_at) VALUES ('philippe.ciftci@gmail.com', 'owner', 'exbat-tat-ad7d', 1, NOW()) ON DUPLICATE KEY UPDATE is_active=1");
        } catch (Throwable $e) { /* tables differentes selon schema, swallow */ }
        reset_log((int)$user['id'], 'reset_total', ['dbs_dropped'=>$droppedCount,'tables_truncated'=>$truncatedCount,'backup'=>$backup]);
        reset_telegram('reset_total', ['dbs_dropped'=>$droppedCount,'tables_truncated'=>$truncatedCount,'backup'=>basename($backup),'by'=>$user['email']??'?']);
        rout(['ok'=>true, 'dbs_dropped'=>$droppedCount, 'tables_truncated'=>$truncatedCount, 'backup_file'=>basename($backup), 'message'=>'Reset TOTAL OK. Tu peux tester sur exbat-tat-ad7d.ocre.immo']);
    } catch (Throwable $e) {
        reset_log((int)$user['id'], 'reset_total_failed', ['error'=>$e->getMessage()]);
        rout(['ok'=>false,'error'=>'reset_total failed: ' . $e->getMessage(),'backup_file'=>basename($backup)], 500);
    }
}

case 'restore_backup': {
    if ($method !== 'POST') rout(['ok'=>false,'error'=>'method'], 405);
    $filename = preg_replace('/[^a-zA-Z0-9_.-]/', '', (string)($input['filename'] ?? ''));
    $confirm = (string)($input['confirmation_word'] ?? '');
    if (!$filename) rout(['ok'=>false,'error'=>'filename requis'], 400);
    if ($confirm !== 'RESTORE') rout(['ok'=>false,'error'=>'confirmation_word doit etre "RESTORE"'], 400);
    $full = RBACKUP_DIR . '/' . $filename;
    if (!is_file($full)) rout(['ok'=>false,'error'=>'Backup file introuvable'], 404);
    $ts = date('Ymd-His');
    $preRestore = RBACKUP_DIR . "/pre-restore-$ts.sql.gz";
    do_dump($preRestore); @chmod($preRestore, 0600);
    $start = microtime(true);
    $cnf = '/root/.secrets/mysql_dump.cnf';
    $cmd = is_file($cnf)
        ? "gunzip -c " . escapeshellarg($full) . " | mysql --defaults-extra-file=" . escapeshellarg($cnf) . " 2>&1"
        : "gunzip -c " . escapeshellarg($full) . " | mysql -u" . escapeshellarg(DB_USER) . " -p" . escapeshellarg(DB_PASS) . " 2>&1";
    $rc = 0; $out = []; @exec($cmd, $out, $rc);
    $duration_ms = (int) round((microtime(true)-$start)*1000);
    if ($rc !== 0) {
        reset_log((int)$user['id'], 'restore_backup_failed', ['file'=>$filename,'rc'=>$rc,'output'=>array_slice($out,-5)]);
        rout(['ok'=>false,'error'=>'Restore mysql failed','rc'=>$rc,'output'=>array_slice($out,-5)], 500);
    }
    reset_log((int)$user['id'], 'restore_backup_ok', ['file'=>$filename,'duration_ms'=>$duration_ms,'pre_restore_backup'=>basename($preRestore)]);
    reset_telegram('restore_backup', ['file'=>$filename,'duration_ms'=>$duration_ms,'by'=>$user['email']??'?']);
    rout(['ok'=>true, 'file_restored'=>$filename, 'duration_ms'=>$duration_ms, 'pre_restore_backup'=>basename($preRestore)]);
}

default:
    rout(['ok'=>false,'error'=>'action inconnue (backup_now|list_backups|reset_dossiers|reset_tenant|reset_total|restore_backup)'], 400);
}
