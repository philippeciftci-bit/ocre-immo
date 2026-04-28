#!/usr/bin/env php
<?php
// M/2026/04/28/56 — Scan multi-tenant des events reminders dûs.
// Tourné toutes les 5 min via ocre-reminders.timer.
// Anti-double-envoi : reminder_sent flag mis à 1 immédiatement avant l'envoi.

set_error_handler(function ($e, $msg) { fwrite(STDERR, "[err] $msg\n"); });
$envFile = '/root/.secrets/ocre-db.env';
if (!is_readable($envFile)) { fwrite(STDERR, "missing $envFile\n"); exit(2); }
foreach (file($envFile) as $l) {
    if (preg_match('/^([A-Z_]+)=(.*)$/', trim($l), $m)) putenv("{$m[1]}={$m[2]}");
}
$DB_HOST = getenv('DB_HOST') ?: '127.0.0.1';
$DB_USER = getenv('DB_USER') ?: 'ocre_app';
$DB_PASS = getenv('DB_PASS') ?: '';
$telegramTokenFile = '/root/.secrets/telegram_atelier';
$telegramTok = is_readable($telegramTokenFile) ? trim((string) file_get_contents($telegramTokenFile)) : '';

function logger(string $line): void {
    printf("[%s] %s\n", date('c'), $line);
}

function tg_send(string $tok, string $chatId, string $text): bool {
    if (!$tok || !$chatId) return false;
    $ch = curl_init("https://api.telegram.org/bot{$tok}/sendMessage");
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => http_build_query([
            'chat_id' => $chatId, 'text' => $text, 'parse_mode' => 'HTML',
        ]),
        CURLOPT_TIMEOUT => 6,
    ]);
    $resp = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return $code === 200;
}

function fetch_user(PDO $meta, int $uid): ?array {
    $st = $meta->prepare("SELECT id, email, display_name, telegram_chat_id, telegram_notifs_enabled, email_notifs_enabled, preferences FROM users WHERE id = ?");
    $st->execute([$uid]);
    $r = $st->fetch(PDO::FETCH_ASSOC);
    return $r ?: null;
}

function notify_event_reminder(PDO $meta, PDO $tenant, array $event, array $client, string $tgTok): void {
    $owner = fetch_user($meta, (int) $event['owner_user_id']);
    if (!$owner) { logger("no owner uid=" . $event['owner_user_id']); return; }

    $prefs = [];
    if (!empty($owner['preferences'])) {
        $prefs = json_decode($owner['preferences'], true) ?: [];
    }
    $types = $prefs['notif_types'] ?? ['event_reminder_1h'];
    if (!in_array('event_reminder_1h', $types, true) && !in_array('event_reminder', $types, true)) {
        logger("uid={$owner['id']} disabled event_reminder pref → skip");
        return;
    }

    $clientLabel = trim(($client['prenom'] ?? '') . ' ' . ($client['nom'] ?? ''));
    if (!$clientLabel) $clientLabel = $client['societe_nom'] ?? ('Dossier #' . ($client['id'] ?? '?'));
    $typeIcon = ['appel'=>'📞','rdv'=>'📅','document'=>'📄','note'=>'📝'][$event['type']] ?? '⏰';

    $sched = strtotime($event['scheduled_at']);
    $diff = $sched - time();
    if ($diff > 3600) $rel = 'dans ' . round($diff/3600) . 'h';
    elseif ($diff > 60) $rel = 'dans ' . round($diff/60) . ' min';
    elseif ($diff > -3600) $rel = 'maintenant';
    else $rel = 'en retard';

    $title = "⏰ Rappel : {$typeIcon} " . htmlspecialchars($event['title'], ENT_QUOTES, 'UTF-8');
    $body = "Sur le dossier <b>" . htmlspecialchars($clientLabel, ENT_QUOTES, 'UTF-8') . "</b> ({$client['projet']})\n";
    $body .= "Prévu {$rel} (" . date('H:i', $sched) . ")";
    if (!empty($event['description'])) {
        $body .= "\n\n" . htmlspecialchars(mb_substr($event['description'], 0, 500), ENT_QUOTES, 'UTF-8');
    }
    $msg = $title . "\n" . $body;

    // Telegram
    $tgEnabled = !empty($prefs['notif']['telegram']['enabled']) || !empty($owner['telegram_notifs_enabled']);
    $chatId = $prefs['notif']['telegram']['chat_id'] ?? ($owner['telegram_chat_id'] ?? '');
    if ($tgEnabled && $chatId) {
        if (tg_send($tgTok, (string) $chatId, $msg)) {
            logger("tg sent uid={$owner['id']} event={$event['id']}");
        } else {
            logger("tg FAIL uid={$owner['id']} event={$event['id']}");
        }
    }

    // In-app : insérer dans tenant.notifications
    try {
        $tenant->prepare(
            "INSERT INTO notifications (user_id, type, title, body, link_path, ref_type, ref_id) VALUES (?, 'event_reminder', ?, ?, ?, 'event', ?)"
        )->execute([
            $owner['id'],
            "Rappel : {$event['title']}",
            "Sur {$clientLabel} · {$rel}",
            "/calendrier",
            $event['id'],
        ]);
    } catch (Throwable $e) { logger("inapp fail: " . $e->getMessage()); }
}

try {
    $sysDsn = "mysql:host={$DB_HOST};charset=utf8mb4";
    $sys = new PDO($sysDsn, $DB_USER, $DB_PASS, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $meta = new PDO("mysql:host={$DB_HOST};dbname=ocre_meta;charset=utf8mb4", $DB_USER, $DB_PASS, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $dbs = $sys->query("SHOW DATABASES LIKE 'ocre_wsp_%'")->fetchAll(PDO::FETCH_COLUMN);
} catch (Throwable $e) {
    logger("FATAL DB: " . $e->getMessage());
    exit(1);
}

$totalSent = 0;
foreach ($dbs as $dbName) {
    try {
        $td = new PDO("mysql:host={$DB_HOST};dbname={$dbName};charset=utf8mb4", $DB_USER, $DB_PASS, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
    } catch (Throwable $e) { continue; }

    $sql = "SELECT id, client_id, owner_user_id, type, title, description, scheduled_at, reminder_offset_minutes
            FROM events
            WHERE reminder_sent = 0
              AND scheduled_at IS NOT NULL
              AND reminder_offset_minutes IS NOT NULL
              AND DATE_SUB(scheduled_at, INTERVAL reminder_offset_minutes MINUTE) <= NOW()
              AND scheduled_at > NOW() - INTERVAL 1 DAY
              AND status NOT IN ('annule', 'fait')
            LIMIT 50";
    $rows = [];
    try { $rows = $td->query($sql)->fetchAll(); } catch (Throwable $e) { continue; }
    if (!$rows) continue;

    foreach ($rows as $ev) {
        // Anti-double : flag immédiatement.
        try {
            $upd = $td->prepare("UPDATE events SET reminder_sent = 1 WHERE id = ? AND reminder_sent = 0");
            $upd->execute([$ev['id']]);
            if ($upd->rowCount() === 0) continue; // déjà traité par concurrent
        } catch (Throwable $e) { continue; }

        $cli = null;
        try {
            $cs = $td->prepare("SELECT id, prenom, nom, societe_nom, projet FROM clients WHERE id = ?");
            $cs->execute([$ev['client_id']]);
            $cli = $cs->fetch();
        } catch (Throwable $e) {}
        if (!$cli) { logger("client not found id={$ev['client_id']}"); continue; }

        notify_event_reminder($meta, $td, $ev, $cli, $telegramTok);
        $totalSent++;
    }
}
logger("scan ok db_count=" . count($dbs) . " sent={$totalSent}");
exit(0);
