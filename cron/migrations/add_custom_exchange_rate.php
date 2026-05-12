<?php
// M/2026/05/13/8 — Migration idempotente : ajoute custom_exchange_rate sur table clients
// dans chaque DB tenant ocre_wsp_*. Decision ROADMAP L205.
//
// Usage : php /opt/ocre-app/cron/migrations/add_custom_exchange_rate.php
//         (idempotent — re-exec safe via IF NOT EXISTS)

require_once __DIR__ . '/../../api/db.php';

$LOG_FILE = '/var/log/ocre-migrations.log';
function mlog($msg, $log) {
    $line = '[' . date('Y-m-d H:i:s') . '] ' . $msg;
    echo $line . "\n";
    @file_put_contents($log, $line . "\n", FILE_APPEND);
}

mlog('=== add_custom_exchange_rate START ===', $LOG_FILE);

try {
    $admin = new PDO('mysql:host=' . DB_HOST . ';charset=utf8mb4', DB_USER, DB_PASS, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
} catch (Throwable $e) {
    mlog('FATAL connexion admin : ' . $e->getMessage(), $LOG_FILE);
    exit(1);
}

$dbs = $admin->query("SHOW DATABASES LIKE 'ocre_wsp_%'")->fetchAll(PDO::FETCH_COLUMN);
mlog('Tenants detectes : ' . count($dbs), $LOG_FILE);

$ok = 0; $skip = 0; $fail = 0;
foreach ($dbs as $db) {
    try {
        // Backtick safe pour noms de DB avec tirets (ocre_wsp_exbattat-a312).
        $stmt = $admin->query("SHOW COLUMNS FROM `$db`.`clients` LIKE 'custom_exchange_rate'");
        if ($stmt->fetch()) {
            mlog("  $db : colonne deja presente -> SKIP", $LOG_FILE);
            $skip++;
            continue;
        }
        $admin->exec("ALTER TABLE `$db`.`clients` ADD COLUMN custom_exchange_rate DECIMAL(10,6) NULL DEFAULT NULL AFTER currency_rates");
        mlog("  $db : ALTER OK -> custom_exchange_rate ajoutee", $LOG_FILE);
        $ok++;
    } catch (Throwable $e) {
        mlog("  $db : ECHEC : " . $e->getMessage(), $LOG_FILE);
        $fail++;
    }
}

mlog("Resume : $ok ajoutes, $skip deja presents, $fail echecs sur " . count($dbs) . " tenants.", $LOG_FILE);
mlog('=== END ===', $LOG_FILE);
exit($fail > 0 ? 2 : 0);
