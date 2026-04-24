<?php
// V18.46 — Dump DB en streaming gzip. IP whitelist + bearer token constant-time.
// OVH shared host : shell_exec/exec désactivés → mysqldump natif PHP via PDO + information_schema.
// Sortie : application/gzip, .sql.gz directement envoyé au curl client.
require_once __DIR__ . '/db.php';

// Auth
$ip = $_SERVER['REMOTE_ADDR'] ?? '';
if ($ip !== '46.225.215.148') { http_response_code(403); exit('Forbidden: IP'); }
$expected = 'c6d03880875e6c406c06ad57e24ca5789f0b41c11ad889843ccf772f2f917b84';
$provided = $_SERVER['HTTP_X_DUMP_TOKEN'] ?? '';
if (!$provided || !hash_equals($expected, $provided)) { http_response_code(403); exit('Forbidden: token'); }

@set_time_limit(300);
@ini_set('memory_limit', '512M');

$dbName = DB_NAME;
$pdo = db();

// Stream headers
header('Content-Type: application/gzip');
header('Content-Disposition: attachment; filename="' . $dbName . '-' . date('Y-m-d-His') . '.sql.gz"');
header('X-Accel-Buffering: no');
while (ob_get_level()) ob_end_clean();

$gz = deflate_init(ZLIB_ENCODING_GZIP, ['level' => 6]);

function emit(string $chunk, bool $flush = false): void {
    global $gz;
    $out = deflate_add($gz, $chunk, $flush ? ZLIB_FINISH : ZLIB_NO_FLUSH);
    if ($out !== false && $out !== '') { echo $out; @flush(); }
}

// Header SQL
emit("-- OCRE immo DB dump v18.46\n");
emit("-- Generated: " . date('c') . "\n");
emit("-- Database: " . $dbName . "\n");
emit("SET NAMES utf8mb4;\n");
emit("SET FOREIGN_KEY_CHECKS=0;\n\n");

// Liste des tables
$tables = [];
foreach ($pdo->query("SHOW TABLES") as $r) { $tables[] = array_values($r)[0]; }

foreach ($tables as $t) {
    $tQ = '`' . str_replace('`', '``', $t) . '`';
    emit("-- ==========================================\n");
    emit("-- Table: $t\n");
    emit("-- ==========================================\n");
    emit("DROP TABLE IF EXISTS $tQ;\n");
    $create = $pdo->query("SHOW CREATE TABLE $tQ")->fetch(PDO::FETCH_ASSOC);
    emit(($create['Create Table'] ?? $create['Create View'] ?? '') . ";\n\n");

    // Skip data pour les views
    if (isset($create['Create View'])) continue;

    // Rows en batches de 500 pour éviter OOM
    $offset = 0;
    $batch = 500;
    while (true) {
        $rows = $pdo->query("SELECT * FROM $tQ LIMIT $batch OFFSET $offset")->fetchAll(PDO::FETCH_ASSOC);
        if (!$rows) break;
        foreach ($rows as $row) {
            $vals = [];
            foreach ($row as $v) {
                if ($v === null) $vals[] = 'NULL';
                elseif (is_int($v) || is_float($v)) $vals[] = (string) $v;
                else $vals[] = $pdo->quote((string) $v);
            }
            $cols = '`' . implode('`,`', array_keys($row)) . '`';
            emit("INSERT INTO $tQ ($cols) VALUES (" . implode(',', $vals) . ");\n");
        }
        $offset += $batch;
        if (count($rows) < $batch) break;
    }
    emit("\n");
}

emit("SET FOREIGN_KEY_CHECKS=1;\n");
emit("-- End of dump\n", true);
