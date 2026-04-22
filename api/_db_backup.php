<?php
// Ocre — endpoint backup DB (IP whitelist VPS atelier, streaming gzip).
// Utilise mysqldump si shell_exec autorisé, sinon fallback PHP natif.
// Permanent (appelé par cron VPS quotidien), PAS self-destruct.
require_once __DIR__ . '/db.php';
$allowed = ['46.225.215.148'];
$remote = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? ($_SERVER['REMOTE_ADDR'] ?? '');
$remote_ip = trim(explode(',', $remote)[0]);
if (!in_array($remote_ip, $allowed, true)) {
    http_response_code(403);
    exit(json_encode(['ok' => false, 'error' => 'Forbidden']));
}

// Essai mysqldump via exec si dispo.
$mysqldump_cmd = sprintf(
    'mysqldump --no-tablespaces --single-transaction --default-character-set=utf8mb4 -h %s -u %s -p%s %s 2>&1',
    escapeshellarg(DB_HOST),
    escapeshellarg(DB_USER),
    escapeshellarg(DB_PASS),
    escapeshellarg(DB_NAME)
);

$use_exec = function_exists('shell_exec');
$dump = '';

if ($use_exec) {
    $dump = @shell_exec($mysqldump_cmd);
    if (!$dump || strpos($dump, 'ERROR') !== false || strlen($dump) < 100) {
        $use_exec = false; // fallback PHP
    }
}

if (!$use_exec) {
    // Fallback PHP natif : SHOW CREATE TABLE + INSERTs.
    $lines = [];
    $lines[] = "-- Ocre Immo backup (PHP native, " . date('c') . ")";
    $lines[] = "SET FOREIGN_KEY_CHECKS=0;";
    $tables = db()->query("SHOW TABLES")->fetchAll(PDO::FETCH_COLUMN);
    foreach ($tables as $t) {
        $lines[] = "\n-- Table $t";
        $lines[] = "DROP TABLE IF EXISTS `$t`;";
        $create = db()->query("SHOW CREATE TABLE `$t`")->fetch(PDO::FETCH_ASSOC);
        $lines[] = $create['Create Table'] . ';';
        $rows = db()->query("SELECT * FROM `$t`")->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as $r) {
            $cols = '`' . implode('`,`', array_keys($r)) . '`';
            $vals = array_map(fn($v) => $v === null ? 'NULL' : db()->quote((string)$v), array_values($r));
            $lines[] = "INSERT INTO `$t` ($cols) VALUES (" . implode(',', $vals) . ");";
        }
    }
    $lines[] = "SET FOREIGN_KEY_CHECKS=1;";
    $dump = implode("\n", $lines);
}

// Gzip stream direct.
header('Content-Type: application/gzip');
header('Content-Disposition: attachment; filename="ocre-' . date('Ymd-His') . '.sql.gz"');
echo gzencode($dump, 6);
