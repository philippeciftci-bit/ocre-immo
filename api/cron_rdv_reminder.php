<?php
// M/2026/05/09/43 — M89 : cron RDV reminder PWA push.
// Scanne suivi_events pour les RDV dont when_at - reminder_min_before tombe dans la fenêtre des 5 dernières minutes
// (à ajuster selon la fréquence du timer systemd : toutes les 5 minutes).
//
// Usage CLI : php /opt/ocre-app/api/cron_rdv_reminder.php
// Mode tenant-iteration : pour chaque tenant slug actif, se connecter au schema ocre_wsp_<slug>
// et scanner les suivi_events en attente de notification.
//
// Env : si DRY_RUN=1, log seulement sans envoyer.

if (php_sapi_name() !== 'cli') { http_response_code(403); exit('CLI only'); }

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/lib/push_notify.php';

$dryRun = (getenv('DRY_RUN') === '1');
$logFile = '/var/log/ocre-rdv-reminder.log';

function logr($msg) {
    global $logFile;
    @file_put_contents($logFile, date('c') . ' ' . $msg . "\n", FILE_APPEND);
}

logr("=== START dryRun=" . ($dryRun ? '1' : '0'));

// Récupère tous les tenants actifs depuis ocre_meta
try {
    $meta = new PDO('mysql:host=' . DB_HOST . ';dbname=ocre_meta;charset=utf8mb4', DB_USER, DB_PASS, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
    $tenants = $meta->query("SELECT id, slug FROM users WHERE slug IS NOT NULL AND archived_at IS NULL")->fetchAll();
} catch (Throwable $e) {
    logr("ERR meta_connect: " . $e->getMessage());
    exit(1);
}

$totalSent = 0;
$totalScanned = 0;

foreach ($tenants as $tenant) {
    $slug = $tenant['slug'];
    $userId = (int) $tenant['id'];
    if (!preg_match('/^[a-z0-9-]{3,50}$/', $slug)) continue;
    $dbName = 'ocre_wsp_' . $slug;
    try {
        $pdo = new PDO("mysql:host=" . DB_HOST . ";dbname=" . $dbName . ";charset=utf8mb4", DB_USER, DB_PASS, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
    } catch (Throwable $e) { logr("WARN tenant=$slug skip db connect: " . $e->getMessage()); continue; }

    // Cherche les events dont reminder doit tomber dans la fenêtre [now-5min, now]
    // Table tenant : events (schéma M/2026/04/28/31), colonnes scheduled_at + reminder_offset_minutes + reminder_sent
    try {
        $st = $pdo->prepare("
            SELECT id, client_id, owner_user_id, type, title, scheduled_at, reminder_offset_minutes
            FROM events
            WHERE reminder_sent = 0
              AND status NOT IN ('annule','fait')
              AND scheduled_at IS NOT NULL
              AND reminder_offset_minutes IS NOT NULL
              AND DATE_SUB(scheduled_at, INTERVAL reminder_offset_minutes MINUTE) <= NOW()
              AND DATE_SUB(scheduled_at, INTERVAL reminder_offset_minutes MINUTE) > DATE_SUB(NOW(), INTERVAL 6 MINUTE)
              AND owner_user_id = ?
            ORDER BY scheduled_at ASC
            LIMIT 50
        ");
        $st->execute([$userId]);
        $rows = $st->fetchAll();
    } catch (Throwable $e) { logr("WARN tenant=$slug query failed: " . $e->getMessage()); continue; }

    $totalScanned += count($rows);
    foreach ($rows as $ev) {
        $clientName = '';
        if ($ev['client_id']) {
            try {
                $cs = $pdo->prepare("SELECT prenom, nom, societe_nom FROM clients WHERE id = ? LIMIT 1");
                $cs->execute([(int) $ev['client_id']]);
                $cr = $cs->fetch();
                if ($cr) {
                    $clientName = trim(($cr['prenom'] ?? '') . ' ' . ($cr['nom'] ?? ''));
                    if ($clientName === '' && !empty($cr['societe_nom'])) $clientName = $cr['societe_nom'];
                }
            } catch (Throwable $_) {}
        }
        $when = date('H\hi', strtotime($ev['scheduled_at']));
        $title = '🗓 RDV à ' . $when;
        $body = !empty($ev['title']) ? $ev['title'] : ($ev['type'] ?: 'rdv');
        if ($clientName !== '') $body .= ' — ' . $clientName;

        if ($dryRun) {
            logr("DRY tenant=$slug uid=$userId event_id={$ev['id']} title=$title");
        } else {
            try {
                $ok = ocre_push_notify((int)$ev['owner_user_id'], 'reminder', $title, $body, '/dossier/' . ($ev['client_id'] ?? ''));
                if ($ok) {
                    $pdo->prepare("UPDATE events SET reminder_sent = 1 WHERE id = ?")->execute([(int) $ev['id']]);
                    $totalSent++;
                    logr("SENT tenant=$slug event_id={$ev['id']} uid={$ev['owner_user_id']}");
                } else {
                    logr("FAIL tenant=$slug event_id={$ev['id']} uid={$ev['owner_user_id']} (push_notify returned false)");
                }
            } catch (Throwable $e) {
                logr("ERR tenant=$slug event_id={$ev['id']}: " . $e->getMessage());
            }
        }
    }
}

logr("=== END scanned=$totalScanned sent=$totalSent");
echo json_encode(['ok' => true, 'scanned' => $totalScanned, 'sent' => $totalSent]) . "\n";
