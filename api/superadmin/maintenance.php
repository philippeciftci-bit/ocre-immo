<?php
// M_SUPERADMIN_RESET — Routeur unique maintenance superadmin
// Endpoints (toutes super_admin requises) :
//   POST ?action=backup_create        body {type?: 'manual'} → mysqldump → gzip → /var/backups/ocre-meta/manual-<ts>.sql.gz
//   GET  ?action=backups_list         retourne 20 derniers backups DESC mtime
//   POST ?action=reset_with_backup    body {level: 1|2|3, confirmation_word: 'RESET', tenant_slug?}
//                                     → backup auto pre-reset + delegation a superadmin_cleanup logique reset
//   POST ?action=restore              body {backup_file, confirmation_word: 'RESTORE'}
//                                     → gunzip + mysql restore
require_once __DIR__ . '/../lib/router.php';
header('Content-Type: application/json; charset=utf-8');

function mout(array $d, int $code = 200) { http_response_code($code); echo json_encode($d, JSON_UNESCAPED_UNICODE); exit; }

$user = current_user_or_401();
if (($user['role'] ?? '') !== 'super_admin') mout(['ok'=>false,'error'=>'super_admin only'], 403);

$action = $_GET['action'] ?? ($_POST['action'] ?? '');
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$raw = file_get_contents('php://input');
$input = json_decode($raw, true) ?: [];

const BACKUP_DIR = '/var/backups/ocre-meta';
const AUDIT_LOG = '/var/log/ocre-superadmin-maintenance.log';

@touch(AUDIT_LOG); @chmod(AUDIT_LOG, 0664);
if (!is_dir(BACKUP_DIR)) @mkdir(BACKUP_DIR, 0750, true);

function maintenance_audit_log(int $sid, string $action, array $detail): void {
    require_once __DIR__ . '/../lib/audit_logs.php';
    audit_log_insert($sid, 'maintenance.' . $action, $detail, $_SERVER['REMOTE_ADDR'] ?? '', $_SERVER['HTTP_USER_AGENT'] ?? '');
    $line = "[" . date('c') . "] sa#" . $sid . " maintenance.$action " . json_encode($detail, JSON_UNESCAPED_UNICODE) . "\n";
    @file_put_contents(AUDIT_LOG, $line, FILE_APPEND);
}

function maintenance_telegram(string $action, array $detail): void {
    $body = "[$action] " . json_encode($detail, JSON_UNESCAPED_UNICODE);
    @shell_exec('/root/bin/notify --project ocre --priority high --phase warn '
        . '--mission-id ' . escapeshellarg('MAINTENANCE/' . time())
        . ' --title ' . escapeshellarg('[OCRE] Maintenance super-admin')
        . ' --body ' . escapeshellarg(substr($body, 0, 1000))
        . ' >/dev/null 2>&1 &');
}

function do_mysqldump(string $outFile): array {
    // Utilise --defaults-extra-file=/root/.secrets/mysql_dump.cnf si present, sinon DB_USER/DB_PASS env
    $cnf = '/root/.secrets/mysql_dump.cnf';
    $start = microtime(true);
    $cmd = is_file($cnf)
        ? "mysqldump --defaults-extra-file=" . escapeshellarg($cnf) . " --all-databases --single-transaction --triggers --routines --quick 2>&1 | gzip > " . escapeshellarg($outFile)
        : "mysqldump -u" . escapeshellarg(DB_USER) . " -p" . escapeshellarg(DB_PASS) . " --all-databases --single-transaction --triggers --routines --quick 2>&1 | gzip > " . escapeshellarg($outFile);
    $rc = 0; $out = []; @exec($cmd, $out, $rc);
    $duration_ms = (int) round((microtime(true) - $start) * 1000);
    $size = is_file($outFile) ? filesize($outFile) : 0;
    return ['ok' => ($rc === 0 && $size > 1024), 'rc' => $rc, 'size' => $size, 'duration_ms' => $duration_ms, 'output' => implode("\n", array_slice($out, -5))];
}

switch ($action) {

case 'backup_create': {
    if ($method !== 'POST') mout(['ok'=>false,'error'=>'method'], 405);
    $type = preg_replace('/[^a-z]/', '', strtolower((string)($input['type'] ?? 'manual')));
    if (!in_array($type, ['manual', 'scheduled'], true)) $type = 'manual';
    $ts = date('Ymd-His');
    $file = BACKUP_DIR . '/' . $type . '-' . $ts . '.sql.gz';
    $r = do_mysqldump($file);
    if (!$r['ok']) {
        maintenance_audit_log((int)$user['id'], 'backup_failed', ['file'=>$file, 'rc'=>$r['rc'], 'output'=>$r['output']]);
        mout(['ok'=>false,'error'=>'mysqldump failed', 'detail'=>$r], 500);
    }
    maintenance_audit_log((int)$user['id'], 'backup_create', ['file'=>$file, 'size'=>$r['size'], 'duration_ms'=>$r['duration_ms'], 'type'=>$type]);
    mout(['ok'=>true, 'file_path'=>$file, 'size_bytes'=>$r['size'], 'duration_ms'=>$r['duration_ms'], 'type'=>$type, 'timestamp'=>$ts]);
}

case 'backups_list': {
    $files = glob(BACKUP_DIR . '/*.sql.gz') ?: [];
    usort($files, function($a,$b){ return @filemtime($b) - @filemtime($a); });
    $files = array_slice($files, 0, 20);
    $rows = [];
    foreach ($files as $f) {
        $base = basename($f);
        $type = preg_match('/^([a-z-]+)-/', $base, $m) ? $m[1] : 'unknown';
        $rows[] = ['file' => $base, 'size' => @filesize($f), 'mtime' => @filemtime($f), 'iso' => date('c', (int)@filemtime($f)), 'type' => $type];
    }
    mout(['ok'=>true, 'backups'=>$rows, 'dir'=>BACKUP_DIR]);
}

case 'reset_with_backup': {
    if ($method !== 'POST') mout(['ok'=>false,'error'=>'method'], 405);
    $level = (int)($input['level'] ?? 0);
    $confirm = (string)($input['confirmation_word'] ?? '');
    $slug = isset($input['tenant_slug']) ? preg_replace('/[^a-z0-9_-]/i', '', (string)$input['tenant_slug']) : null;
    if (!in_array($level, [1,2,3], true)) mout(['ok'=>false,'error'=>'level requis (1|2|3)'], 400);
    if ($confirm !== 'RESET') mout(['ok'=>false,'error'=>'confirmation_word doit etre "RESET" exactement'], 400);
    if ($level === 1 && !$slug) mout(['ok'=>false,'error'=>'tenant_slug requis pour level 1'], 400);

    // Etape 1 : backup auto avant reset
    $ts = date('Ymd-His');
    $backupFile = BACKUP_DIR . "/pre-reset-L{$level}-" . ($slug ? $slug . '-' : '') . "$ts.sql.gz";
    $r = do_mysqldump($backupFile);
    if (!$r['ok']) {
        maintenance_audit_log((int)$user['id'], 'reset_aborted_backup_failed', ['level'=>$level,'slug'=>$slug,'detail'=>$r]);
        mout(['ok'=>false,'error'=>'Backup pre-reset echoue, RESET ANNULE par securite', 'detail'=>$r], 500);
    }
    maintenance_audit_log((int)$user['id'], 'reset_backup_ok', ['file'=>$backupFile,'size'=>$r['size'],'duration_ms'=>$r['duration_ms'],'level'=>$level]);

    // Etape 2 : execution reset selon niveau
    $startReset = microtime(true);
    $resetResult = ['level'=>$level, 'tables_truncated'=>0, 'dbs_dropped'=>0];
    try {
        if ($level === 1) {
            // Reset tenant unique : DROP DATABASE ocre_wsp_<slug>
            $dbName = 'ocre_wsp_' . preg_replace('/[^a-z0-9_]/', '_', strtolower($slug));
            $meta = pdo_meta();
            $meta->exec("DROP DATABASE IF EXISTS `$dbName`");
            $resetResult['dbs_dropped'] = 1;
            $resetResult['tenant_dropped'] = $dbName;
            // Conserve auth_users du tenant : on drop juste la DB des dossiers/photos/etc
            // (pour conserver auth on ne touche pas a meta tables auth_*)
        } elseif ($level === 2) {
            // Reset complet sauf super_admin : DROP toutes ocre_wsp_* + TRUNCATE app tables meta sauf super_admin/feature_flags/system_settings
            $meta = pdo_meta();
            $dbs = $meta->query("SHOW DATABASES LIKE 'ocre_wsp_%'")->fetchAll(PDO::FETCH_COLUMN);
            foreach ($dbs as $d) {
                $meta->exec("DROP DATABASE IF EXISTS `$d`");
                $resetResult['dbs_dropped']++;
            }
            // Tables meta a truncate (preserver super_admin + feature_flags + settings)
            $preserveTables = ['super_admin', 'super_admin_events', 'feature_flags', 'settings', 'system_settings', 'audit_logs'];
            $allTables = $meta->query("SHOW TABLES")->fetchAll(PDO::FETCH_COLUMN);
            foreach ($allTables as $t) {
                if (in_array($t, $preserveTables, true)) continue;
                try { $meta->exec("TRUNCATE TABLE `$t`"); $resetResult['tables_truncated']++; }
                catch (Throwable $e) { /* skip */ }
            }
        } elseif ($level === 3) {
            // DESTRUCTIF : DROP DATABASE meta + tous tenants. NECESSITE CONFIG SPECIALE (refusé par defaut sauf flag)
            if (empty($input['allow_factory_wipe']) || $input['allow_factory_wipe'] !== 'YES_I_KNOW_WHAT_IM_DOING') {
                mout(['ok'=>false,'error'=>'Level 3 necessite allow_factory_wipe=YES_I_KNOW_WHAT_IM_DOING en plus de confirmation_word'], 400);
            }
            // Refus explicite : level 3 trop destructif pour endpoint web. Forcer SSH manuel.
            maintenance_audit_log((int)$user['id'], 'reset_level3_blocked_endpoint', ['level'=>3]);
            mout(['ok'=>false,'error'=>'Level 3 (factory wipe) interdit via endpoint web. Executer manuellement en SSH avec script /opt/ocre-app/scripts/factory-wipe.sh apres lecture explicite du runbook'], 403);
        }
    } catch (Throwable $e) {
        maintenance_audit_log((int)$user['id'], 'reset_failed', ['level'=>$level,'error'=>$e->getMessage()]);
        mout(['ok'=>false,'error'=>'Reset echoue : ' . $e->getMessage(), 'backup_file'=>$backupFile], 500);
    }
    $resetResult['duration_ms'] = (int) round((microtime(true) - $startReset) * 1000);
    $resetResult['backup_file'] = $backupFile;

    maintenance_audit_log((int)$user['id'], 'reset_completed', $resetResult);
    maintenance_telegram('reset_completed', ['level'=>$level, 'by'=>$user['email']??'?', 'backup'=>$backupFile, 'dbs_dropped'=>$resetResult['dbs_dropped'], 'tables'=>$resetResult['tables_truncated']]);
    mout(['ok'=>true] + $resetResult);
}

case 'restore': {
    if ($method !== 'POST') mout(['ok'=>false,'error'=>'method'], 405);
    $file = preg_replace('/[^a-zA-Z0-9_.-]/', '', (string)($input['backup_file'] ?? ''));
    $confirm = (string)($input['confirmation_word'] ?? '');
    if (!$file) mout(['ok'=>false,'error'=>'backup_file requis'], 400);
    if ($confirm !== 'RESTORE') mout(['ok'=>false,'error'=>'confirmation_word doit etre "RESTORE" exactement'], 400);
    $full = BACKUP_DIR . '/' . $file;
    if (!is_file($full)) mout(['ok'=>false,'error'=>'Backup file introuvable: ' . $file], 404);

    // Backup pre-restore par securite
    $ts = date('Ymd-His');
    $preRestore = BACKUP_DIR . "/pre-restore-$ts.sql.gz";
    $r = do_mysqldump($preRestore);
    if (!$r['ok']) maintenance_audit_log((int)$user['id'], 'restore_aborted_prebackup_failed', ['detail'=>$r]);

    $start = microtime(true);
    $cnf = '/root/.secrets/mysql_dump.cnf';
    $cmd = is_file($cnf)
        ? "gunzip -c " . escapeshellarg($full) . " | mysql --defaults-extra-file=" . escapeshellarg($cnf) . " 2>&1"
        : "gunzip -c " . escapeshellarg($full) . " | mysql -u" . escapeshellarg(DB_USER) . " -p" . escapeshellarg(DB_PASS) . " 2>&1";
    $rc = 0; $out = []; @exec($cmd, $out, $rc);
    $duration_ms = (int) round((microtime(true) - $start) * 1000);
    if ($rc !== 0) {
        maintenance_audit_log((int)$user['id'], 'restore_failed', ['file'=>$file,'rc'=>$rc,'output'=>implode("\n", array_slice($out,-5))]);
        mout(['ok'=>false,'error'=>'Restore mysql failed','rc'=>$rc,'output'=>array_slice($out,-5)], 500);
    }
    maintenance_audit_log((int)$user['id'], 'restore_ok', ['file'=>$file,'duration_ms'=>$duration_ms,'pre_restore_backup'=>$preRestore]);
    maintenance_telegram('restore_completed', ['file'=>$file, 'by'=>$user['email']??'?', 'duration_ms'=>$duration_ms]);
    mout(['ok'=>true, 'file_restored'=>$file, 'duration_ms'=>$duration_ms, 'pre_restore_backup'=>basename($preRestore)]);
}

default:
    mout(['ok'=>false,'error'=>'action inconnue (backup_create|backups_list|reset_with_backup|restore)'], 400);
}
