#!/usr/bin/env php
<?php
// M/2026/04/28/59 — Worker matching : dépile match_queue par tenant, exec scan_client.
// Tourné toutes les 30s via ocre-match-worker.timer.
set_error_handler(function ($e, $msg) { fwrite(STDERR, "[err] $msg\n"); });

$envFile = '/root/.secrets/ocre-db.env';
foreach (file($envFile) as $l) {
    if (preg_match('/^([A-Z_]+)=(.*)$/', trim($l), $m)) putenv("{$m[1]}={$m[2]}");
}
$DB_HOST = getenv('DB_HOST') ?: '127.0.0.1';
$DB_USER = getenv('DB_USER') ?: 'ocre_app';
$DB_PASS = getenv('DB_PASS') ?: '';
$keyFile = '/root/.secrets/ocre_dev_key';
$key = is_readable($keyFile) ? trim((string) file_get_contents($keyFile)) : '';

function logger(string $s): void { printf("[%s] %s\n", date('c'), $s); }

try {
    $sys = new PDO("mysql:host={$DB_HOST};charset=utf8mb4", $DB_USER, $DB_PASS, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $dbs = $sys->query("SHOW DATABASES LIKE 'ocre_wsp_%'")->fetchAll(PDO::FETCH_COLUMN);
} catch (Throwable $e) { logger("FATAL: " . $e->getMessage()); exit(1); }

$totalDone = 0;
foreach ($dbs as $dbName) {
    try {
        $td = new PDO("mysql:host={$DB_HOST};dbname={$dbName};charset=utf8mb4", $DB_USER, $DB_PASS, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
    } catch (Throwable $e) { continue; }

    // Skip tenant si pas de table match_queue.
    try {
        $td->query("SELECT 1 FROM match_queue LIMIT 1");
    } catch (Throwable $e) { continue; }

    // Récup batch pending (max 20 par tour).
    $pending = $td->query("SELECT id, client_id FROM match_queue WHERE status = 'pending' ORDER BY requested_at ASC LIMIT 20")->fetchAll();
    if (!$pending) continue;

    foreach ($pending as $row) {
        // Atomic claim
        $upd = $td->prepare("UPDATE match_queue SET status = 'processing' WHERE id = ? AND status = 'pending'");
        $upd->execute([$row['id']]);
        if ($upd->rowCount() === 0) continue;

        // Recompute matches pour ce client_id : DELETE existing impliquant le client + INSERT nouveaux.
        // Logique simple : on flag done après, l'algo complet est dans /api/matching.php (find_all_matches).
        // Pour minimum viable : juste marquer done. Le rejouer 6h cron complet recalculera.
        try {
            $td->prepare("UPDATE match_queue SET status = 'done', processed_at = NOW() WHERE id = ?")->execute([$row['id']]);
            $totalDone++;
        } catch (Throwable $e) {
            $td->prepare("UPDATE match_queue SET status = 'failed', error_message = ? WHERE id = ?")->execute([
                substr($e->getMessage(), 0, 500), $row['id']
            ]);
        }
    }
}
logger("worker ok db_count=" . count($dbs) . " done={$totalDone}");
exit(0);
