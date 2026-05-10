<?php
// M104 — Worker Channel manager v4.
// Boucle infinite : SELECT jobs queued, dispatch vers driver, gestion retry exponentiel.
//
// Usage : php channel_worker.php [--once]   (--once = process puis exit pour debug/cron)
// Systemd : ocre-channel-worker.service Restart=always

declare(strict_types=1);

$BASE = '/opt/ocre-app/api';
require_once $BASE . '/lib/channels/registry.php';

// Backoff retry exponentiel (secondes).
const RETRY_BACKOFF = [60, 300, 900, 3600, 21600, 86400]; // 1m, 5m, 15m, 1h, 6h, 24h
const MAX_ATTEMPTS = 6;

$once = in_array('--once', $argv ?? [], true);
$workerId = gethostname() . '_' . getmypid() . '_' . substr(bin2hex(random_bytes(4)), 0, 8);

function wlog(string $level, string $msg): void {
    global $workerId;
    $line = '[' . date('c') . '] [worker ' . $workerId . '] [' . $level . '] ' . $msg;
    echo $line . "\n";
    @file_put_contents('/var/log/ocre-channel-worker.log', $line . "\n", FILE_APPEND);
}

channel_ensure_schema();
$db = channel_meta_pdo();

wlog('INFO', 'start mode=' . ($once ? 'once' : 'loop'));

function process_one_job(PDO $db, string $workerId): bool {
    // Pick 1 job atomically (UPDATE ... LIMIT then SELECT picked).
    $db->beginTransaction();
    try {
        $sel = $db->prepare(
            "SELECT id FROM channel_queue
             WHERE status = 'queued' AND scheduled_at <= NOW()
             ORDER BY priority ASC, scheduled_at ASC
             LIMIT 1 FOR UPDATE SKIP LOCKED"
        );
        $sel->execute();
        $row = $sel->fetch();
        if (!$row) {
            $db->commit();
            return false;
        }
        $jobId = (int) $row['id'];
        $db->prepare("UPDATE channel_queue SET status='processing', picked_at=NOW(), worker_id=? WHERE id=?")
           ->execute([$workerId, $jobId]);
        $db->commit();
    } catch (Throwable $e) {
        $db->rollBack();
        wlog('ERROR', 'pick fail: ' . $e->getMessage());
        return false;
    }

    $job = $db->prepare("SELECT * FROM channel_queue WHERE id=? LIMIT 1");
    $job->execute([$jobId]);
    $job = $job->fetch();
    $mp = $db->prepare("SELECT * FROM channel_mappings WHERE id=? LIMIT 1");
    $mp->execute([(int) $job['mapping_id']]);
    $mapping = $mp->fetch();

    if (!$mapping) {
        $db->prepare("UPDATE channel_queue SET status='failed', last_error='mapping not found' WHERE id=?")->execute([$jobId]);
        wlog('ERROR', "job=$jobId no mapping");
        return true;
    }

    wlog('INFO', "job=$jobId mapping=" . $mapping['id'] . " channel=" . $mapping['channel_name'] . " action=" . $job['job_type']);

    $driver = channel_driver($mapping['channel_name']);
    if (!$driver) {
        $db->prepare("UPDATE channel_queue SET status='failed', last_error='driver not found' WHERE id=?")->execute([$jobId]);
        $db->prepare("UPDATE channel_mappings SET status='refused', error_message=? WHERE id=?")
           ->execute(['driver indisponible: ' . $mapping['channel_name'], (int) $mapping['id']]);
        return true;
    }

    // Recupere subscription (credentials)
    $sub = $db->prepare("SELECT * FROM channel_subscriptions WHERE tenant_slug=? AND channel_name=? LIMIT 1");
    $sub->execute([$mapping['tenant_slug'], $mapping['channel_name']]);
    $sub = $sub->fetch();
    $credentials = $sub && $sub['credentials'] ? json_decode($sub['credentials'], true) : [];

    // Recupere dossier source
    $dossier = channel_get_dossier($mapping['tenant_slug'], (int) $mapping['dossier_id']);
    if (!$dossier && $job['job_type'] !== 'delete') {
        $db->prepare("UPDATE channel_queue SET status='failed', last_error='dossier not found' WHERE id=?")->execute([$jobId]);
        $db->prepare("UPDATE channel_mappings SET status='refused', error_message='dossier source introuvable' WHERE id=?")
           ->execute([(int) $mapping['id']]);
        return true;
    }
    $listing = $dossier ? channel_dossier_to_listing($dossier) : [];
    // M105 — enrich listing avec metadata tenant/dossier/locale pour drivers internationaux.
    if ($listing) {
        $listing['_tenant_slug'] = $mapping['tenant_slug'];
        $listing['_dossier_id'] = (int) $mapping['dossier_id'];
        $listing['locale'] = $listing['locale'] ?? 'fr';
    }

    // Validate
    if (in_array($job['job_type'], ['publish', 'update'])) {
        $validation = $driver->validateListing($listing);
        if (!$validation['ok']) {
            $err = 'Champs manquants: ' . implode(', ', $validation['missing_fields']);
            $db->prepare("UPDATE channel_queue SET status='failed', last_error=? WHERE id=?")->execute([$err, $jobId]);
            $db->prepare("UPDATE channel_mappings SET status='refused', error_message=? WHERE id=?")
               ->execute([$err, (int) $mapping['id']]);
            log_action($db, (int) $mapping['id'], $job['job_type'] . '_validation_fail', $listing, ['missing' => $validation['missing_fields']], 422, 0);
            wlog('WARN', "job=$jobId validation fail: $err");
            return true;
        }
    }

    // Dispatch
    $db->prepare("UPDATE channel_mappings SET status='syncing' WHERE id=?")->execute([(int) $mapping['id']]);
    try {
        switch ($job['job_type']) {
            case 'publish':
                $r = $driver->publish($listing, $credentials);
                break;
            case 'update':
                $r = $driver->update($mapping['external_listing_id'], $listing, $credentials);
                break;
            case 'delete':
                $r = $driver->delete($mapping['external_listing_id'] ?? '', $credentials);
                break;
            case 'status_check':
                $r = $driver->getStatus($mapping['external_listing_id'] ?? '', $credentials);
                break;
            default:
                $r = ['ok' => false, 'error' => 'unknown job_type'];
        }
    } catch (Throwable $e) {
        $r = ['ok' => false, 'error' => 'driver exception: ' . $e->getMessage(), 'duration_ms' => 0];
    }

    log_action($db, (int) $mapping['id'], $job['job_type'], $listing, $r, $r['status_code'] ?? null, $r['duration_ms'] ?? null);

    if ($r['ok']) {
        if ($job['job_type'] === 'publish' && !empty($r['external_id'])) {
            $db->prepare("UPDATE channel_mappings SET status='published', external_listing_id=?, last_synced_at=NOW(), retry_count=0, error_message=NULL WHERE id=?")
               ->execute([$r['external_id'], (int) $mapping['id']]);
        } elseif ($job['job_type'] === 'update') {
            $db->prepare("UPDATE channel_mappings SET status='published', last_synced_at=NOW(), retry_count=0, error_message=NULL WHERE id=?")
               ->execute([(int) $mapping['id']]);
        } elseif ($job['job_type'] === 'delete') {
            $db->prepare("UPDATE channel_mappings SET status='deleted', last_synced_at=NOW() WHERE id=?")->execute([(int) $mapping['id']]);
        } elseif ($job['job_type'] === 'status_check') {
            $views = (int) ($r['views'] ?? 0);
            $db->prepare("UPDATE channel_mappings SET views=?, last_synced_at=NOW() WHERE id=?")->execute([$views, (int) $mapping['id']]);
        }
        $db->prepare("UPDATE channel_queue SET status='done', last_error=NULL WHERE id=?")->execute([$jobId]);
        wlog('INFO', "job=$jobId DONE");
    } else {
        $attempts = ((int) $job['attempts']) + 1;
        $err = $r['error'] ?? 'unknown';
        if ($attempts >= MAX_ATTEMPTS) {
            $db->prepare("UPDATE channel_queue SET status='failed', attempts=?, last_error=? WHERE id=?")
               ->execute([$attempts, $err, $jobId]);
            $db->prepare("UPDATE channel_mappings SET status='refused', error_message=?, retry_count=? WHERE id=?")
               ->execute(['Echec definitif apres ' . MAX_ATTEMPTS . ' tentatives: ' . $err, $attempts, (int) $mapping['id']]);
            wlog('ERROR', "job=$jobId FAILED definitive: $err");
        } else {
            $backoff = RETRY_BACKOFF[$attempts - 1] ?? 86400;
            $db->prepare("UPDATE channel_queue SET status='queued', attempts=?, last_error=?, scheduled_at=DATE_ADD(NOW(), INTERVAL ? SECOND), picked_at=NULL, worker_id=NULL WHERE id=?")
               ->execute([$attempts, $err, $backoff, $jobId]);
            $db->prepare("UPDATE channel_mappings SET retry_count=?, error_message=? WHERE id=?")
               ->execute([$attempts, $err, (int) $mapping['id']]);
            wlog('WARN', "job=$jobId retry in {$backoff}s (attempt $attempts/" . MAX_ATTEMPTS . "): $err");
        }
    }
    return true;
}

function log_action(PDO $db, int $mappingId, string $action, $payload, $response, ?int $code, ?int $duration): void {
    try {
        $st = $db->prepare(
            "INSERT INTO channel_logs (mapping_id, action, payload, response, status_code, duration_ms)
             VALUES (?, ?, ?, ?, ?, ?)"
        );
        $st->execute([
            $mappingId, $action,
            $payload ? json_encode($payload) : null,
            $response ? json_encode($response) : null,
            $code, $duration,
        ]);
    } catch (Throwable $e) {
        wlog('ERROR', 'log fail: ' . $e->getMessage());
    }
}

if ($once) {
    $processed = process_one_job($db, $workerId);
    wlog('INFO', 'once mode done processed=' . ($processed ? '1' : '0'));
    exit($processed ? 0 : 0);
}

$idleTicks = 0;
while (true) {
    $processed = process_one_job($db, $workerId);
    if ($processed) {
        $idleTicks = 0;
    } else {
        $idleTicks++;
        if ($idleTicks % 60 === 0) wlog('INFO', 'heartbeat idle');
        sleep(5);
    }
}
