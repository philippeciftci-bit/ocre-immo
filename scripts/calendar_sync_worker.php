<?php
// M118c — Worker Google Calendar 2-way sync (V1 minimal pull-only).
// Loop : SELECT users active oauth → PULL events Google → INSERT/UPDATE rendez_vous Ocre + map.
// Push Ocre→Google reporte M118d.

declare(strict_types=1);
$BASE = '/opt/ocre-app/api';
require_once $BASE . '/db.php';
require_once $BASE . '/calendar/_lib.php';

const SYNC_LOCK = '/tmp/ocre-calendar-sync.lock';
const SYNC_FORCE = '/tmp/ocre-calendar-force-sync';

function wlog(string $msg): void {
    @file_put_contents('/var/log/ocre-calendar-sync.log', '[' . date('c') . '] ' . $msg . "\n", FILE_APPEND);
    echo "[" . date('c') . "] $msg\n";
}

function meta_pdo(): PDO {
    static $pdo = null;
    if ($pdo === null) {
        $pdo = new PDO('mysql:host=' . DB_HOST . ';dbname=ocre_meta;charset=utf8mb4', DB_USER, DB_PASS,
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
    }
    return $pdo;
}

function ensure_map_schema(): void {
    static $done = false;
    if ($done) return;
    meta_pdo()->exec("CREATE TABLE IF NOT EXISTS google_calendar_events_map (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        tenant_slug VARCHAR(64) NOT NULL,
        user_id INT UNSIGNED NOT NULL,
        ocre_rdv_id INT UNSIGNED NULL,
        google_event_id VARCHAR(256) NULL,
        last_modified_ocre DATETIME NULL,
        last_modified_google DATETIME NULL,
        sync_status ENUM('synced','pending_push','pending_pull','conflict') DEFAULT 'synced',
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY uniq_ocre_event (tenant_slug, ocre_rdv_id),
        UNIQUE KEY uniq_google_event (tenant_slug, google_event_id),
        INDEX idx_user_status (user_id, sync_status)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $done = true;
}

function fetch_google_events(string $accessToken): array {
    // STUB MODE : appel mock /mock/google-calendar/events
    $ch = curl_init('http://127.0.0.1:8892/mock/google-calendar/events');
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 10, CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $accessToken]]);
    $resp = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($code !== 200) return [];
    $d = json_decode($resp, true) ?: [];
    return $d['items'] ?? [];
}

function sync_one_user(array $oauth): array {
    $tenant = $oauth['tenant_slug'];
    $userId = (int) $oauth['user_id'];
    wlog("sync user_id=$userId tenant=$tenant");

    // Token check (futur : refresh si expired)
    if (strtotime($oauth['token_expires_at']) < time()) {
        wlog("token expired user=$userId — TODO refresh_token");
        // V1 stub : skip
        return ['ok' => false, 'reason' => 'token_expired'];
    }

    $events = fetch_google_events($oauth['access_token']);
    wlog("pulled " . count($events) . " events");

    ensure_map_schema();
    $tenantPdo = cal_tenant_pdo($tenant);
    cal_ensure_schema($tenant);

    $created = 0; $updated = 0;
    foreach ($events as $ev) {
        $gid = $ev['id'] ?? null;
        if (!$gid) continue;
        $title = $ev['summary'] ?? '(sans titre)';
        $start = $ev['start']['dateTime'] ?? null;
        $end = $ev['end']['dateTime'] ?? null;
        $location = $ev['location'] ?? '';
        if (!$start || !$end) continue;

        // Map exists ?
        $st = meta_pdo()->prepare("SELECT * FROM google_calendar_events_map WHERE tenant_slug=? AND google_event_id=? LIMIT 1");
        $st->execute([$tenant, $gid]);
        $map = $st->fetch();

        if (!$map) {
            // INSERT new rendez_vous + map
            $ins = $tenantPdo->prepare("INSERT INTO rendez_vous (type, titre, lieu, start_at, end_at, created_by_user_id) VALUES ('autre', ?, ?, ?, ?, ?)");
            $ins->execute([$title, $location, str_replace('T', ' ', substr($start, 0, 19)), str_replace('T', ' ', substr($end, 0, 19)), $userId]);
            $rdvId = (int) $tenantPdo->lastInsertId();
            meta_pdo()->prepare(
                "INSERT INTO google_calendar_events_map (tenant_slug, user_id, ocre_rdv_id, google_event_id, last_modified_google, sync_status)
                 VALUES (?, ?, ?, ?, NOW(), 'synced')"
            )->execute([$tenant, $userId, $rdvId, $gid]);
            $created++;
        } else {
            // UPDATE existing rendez_vous
            $tenantPdo->prepare("UPDATE rendez_vous SET titre=?, lieu=?, start_at=?, end_at=? WHERE id=?")
                ->execute([$title, $location, str_replace('T', ' ', substr($start, 0, 19)), str_replace('T', ' ', substr($end, 0, 19)), (int) $map['ocre_rdv_id']]);
            meta_pdo()->prepare("UPDATE google_calendar_events_map SET last_modified_google=NOW(), sync_status='synced' WHERE id=?")
                ->execute([(int) $map['id']]);
            $updated++;
        }
    }

    meta_pdo()->prepare("UPDATE google_calendar_oauth SET last_sync_at=NOW() WHERE id=?")->execute([(int) $oauth['id']]);
    wlog("user=$userId created=$created updated=$updated");
    return ['ok' => true, 'created' => $created, 'updated' => $updated];
}

function main(): void {
    if (file_exists(SYNC_LOCK)) {
        $age = time() - filemtime(SYNC_LOCK);
        if ($age < 300) { wlog('locked age=' . $age . 's, skip'); return; }
        wlog('stale lock, removing');
        @unlink(SYNC_LOCK);
    }
    @file_put_contents(SYNC_LOCK, getmypid());
    @unlink(SYNC_FORCE);

    try {
        $st = meta_pdo()->prepare("SELECT * FROM google_calendar_oauth WHERE status='active'");
        $st->execute();
        foreach ($st->fetchAll() as $oauth) {
            sync_one_user($oauth);
        }
    } finally {
        @unlink(SYNC_LOCK);
    }
}

main();
