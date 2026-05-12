<?php
// M/2026/05/13/17 — Migration idempotente : ajoute colonnes 2FA TOTP sur tous users (ocre_meta + tenants).
require_once __DIR__ . '/../../api/db.php';
$LOG = '/var/log/ocre-migrations.log';
function mlog($msg, $log) { $line = '[' . date('Y-m-d H:i:s') . '] ' . $msg; echo $line . "\n"; @file_put_contents($log, $line . "\n", FILE_APPEND); }

mlog('=== add_2fa_columns START ===', $LOG);

$admin = new PDO('mysql:host=' . DB_HOST . ';charset=utf8mb4', DB_USER, DB_PASS, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);

function ensureCols($admin, $db, $log) {
    $cols = ['totp_secret VARCHAR(64) NULL DEFAULT NULL', 'totp_enabled TINYINT(1) NOT NULL DEFAULT 0',
             'totp_backup_codes TEXT NULL DEFAULT NULL', 'totp_last_used_window INT NULL DEFAULT NULL'];
    foreach ($cols as $colDef) {
        $colName = explode(' ', $colDef)[0];
        $stmt = $admin->query("SHOW COLUMNS FROM `$db`.`users` LIKE '$colName'");
        if ($stmt->fetch()) { mlog("  $db.users.$colName : SKIP", $log); continue; }
        $admin->exec("ALTER TABLE `$db`.`users` ADD COLUMN $colDef");
        mlog("  $db.users.$colName : ADDED", $log);
    }
}

try { ensureCols($admin, 'ocre_meta', $LOG); } catch (Throwable $e) { mlog('ocre_meta ECHEC : ' . $e->getMessage(), $LOG); }

$dbs = $admin->query("SHOW DATABASES LIKE 'ocre_wsp_%'")->fetchAll(PDO::FETCH_COLUMN);
foreach ($dbs as $db) {
    try { ensureCols($admin, $db, $LOG); } catch (Throwable $e) { mlog("$db ECHEC : " . $e->getMessage(), $LOG); }
}

mlog('=== END ===', $LOG);
