<?php
// M/2026/04/28/51 — notify_edit_event : 3 canaux notifs pour workflow EditConsent.
//   - in-app : insertion table notifications (lue par /api/notifications.php)
//   - Telegram : sendMessage avec inline_keyboard (chat_id = users.telegram_chat_id ou fallback)
//   - email : log /var/log/ocre-edit-notifs.log (stub tant que SMTP non câblé)

if (!function_exists('notify_edit_event')) {

function notify_edit_event_ensure_user_columns(): void {
    static $done = false;
    if ($done) return;
    try {
        // Colonnes user prefs (idempotent). Vivent en ocre_meta.users.
        $dsn = 'mysql:host=' . DB_HOST . ';dbname=ocre_meta;charset=utf8mb4';
        $meta = new PDO($dsn, DB_USER, DB_PASS, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $cols = $meta->query("SHOW COLUMNS FROM users")->fetchAll(PDO::FETCH_COLUMN);
        if (!in_array('telegram_chat_id', $cols, true)) {
            $meta->exec("ALTER TABLE users ADD COLUMN telegram_chat_id VARCHAR(40) NULL");
        }
        if (!in_array('telegram_notifs_enabled', $cols, true)) {
            $meta->exec("ALTER TABLE users ADD COLUMN telegram_notifs_enabled TINYINT(1) NOT NULL DEFAULT 1");
        }
        if (!in_array('email_notifs_enabled', $cols, true)) {
            $meta->exec("ALTER TABLE users ADD COLUMN email_notifs_enabled TINYINT(1) NOT NULL DEFAULT 1");
        }
    } catch (Throwable $e) {}
    $done = true;
}

function notify_edit_event_user(int $uid): ?array {
    try {
        $dsn = 'mysql:host=' . DB_HOST . ';dbname=ocre_meta;charset=utf8mb4';
        $meta = new PDO($dsn, DB_USER, DB_PASS, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
        $st = $meta->prepare("SELECT id, email, display_name, telegram_chat_id, telegram_notifs_enabled, email_notifs_enabled FROM users WHERE id = ?");
        $st->execute([$uid]);
        $r = $st->fetch();
        return $r ?: null;
    } catch (Throwable $e) {
        return null;
    }
}

function notify_edit_event_log_stub(string $line): void {
    @file_put_contents('/var/log/ocre-edit-notifs.log', date('c') . ' ' . $line . "\n", FILE_APPEND);
}

function notify_edit_event_telegram(string $chatId, string $text, ?array $inlineKb = null): void {
    $tokenFile = '/root/.secrets/telegram_atelier';
    if (!is_readable($tokenFile)) return;
    $token = trim((string) @file_get_contents($tokenFile));
    if (!$token || !$chatId) return;
    $payload = [
        'chat_id' => $chatId,
        'text' => $text,
        'parse_mode' => 'HTML',
    ];
    if ($inlineKb) {
        $payload['reply_markup'] = json_encode(['inline_keyboard' => $inlineKb]);
    }
    $url = "https://api.telegram.org/bot{$token}/sendMessage";
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => http_build_query($payload),
        CURLOPT_TIMEOUT => 5,
    ]);
    @curl_exec($ch);
    curl_close($ch);
}

function notify_edit_event_render_changes(array $changes, int $maxLines = 6): string {
    $out = [];
    $i = 0;
    foreach ($changes as $c) {
        if ($i++ >= $maxLines) { $out[] = '...'; break; }
        $f = $c['field'] ?? '?';
        $b = is_scalar($c['before'] ?? null) ? (string) $c['before'] : json_encode($c['before'] ?? null, JSON_UNESCAPED_UNICODE);
        $a = is_scalar($c['after'] ?? null) ? (string) $c['after'] : json_encode($c['after'] ?? null, JSON_UNESCAPED_UNICODE);
        $b = mb_substr($b, 0, 40);
        $a = mb_substr($a, 0, 40);
        $out[] = "- {$f} : {$b} → {$a}";
    }
    return implode("\n", $out);
}

function notify_edit_event(string $type, int $editId, int $clientId, int $actorUid, array $opts = []): void {
    notify_edit_event_ensure_user_columns();

    $recipients = $opts['recipient_user_ids'] ?? [];
    $recipients = array_values(array_unique(array_filter(array_map('intval', $recipients))));
    if (!$recipients) return;

    // Récup nom dossier.
    $dossierLabel = '';
    try {
        $st = db()->prepare("SELECT prenom, nom, societe_nom FROM clients WHERE id = ?");
        $st->execute([$clientId]);
        $cli = $st->fetch();
        if ($cli) {
            $dossierLabel = trim(($cli['prenom'] ?? '') . ' ' . ($cli['nom'] ?? ''));
            if (!$dossierLabel) $dossierLabel = $cli['societe_nom'] ?? "Dossier #{$clientId}";
        }
    } catch (Throwable $e) {}
    if (!$dossierLabel) $dossierLabel = "Dossier #{$clientId}";

    $authorUser = $opts['author'] ?? $opts['decider'] ?? null;
    $authorName = $authorUser ? ($authorUser['display_name'] ?? $authorUser['email'] ?? 'Quelqu\'un') : 'Quelqu\'un';

    $changes = $opts['changes'] ?? [];
    $changesCount = is_array($changes) ? count($changes) : 0;

    $titles = [
        'edit_pending'  => "✏ Modifications en attente sur {$dossierLabel}",
        'edit_approved' => "✓ Modifications validées sur {$dossierLabel}",
        'edit_rejected' => "✗ Modifications refusées sur {$dossierLabel}",
        'edit_modified' => "↻ Contre-proposition sur {$dossierLabel}",
    ];
    $bodies = [
        'edit_pending'  => "Par {$authorName} — {$changesCount} modification(s)",
        'edit_approved' => "Validées par {$authorName}",
        'edit_rejected' => "Refusées par {$authorName}" . (!empty($opts['comment']) ? " — « " . mb_substr($opts['comment'], 0, 120) . " »" : ''),
        'edit_modified' => "Contre-proposition par {$authorName}",
    ];
    $title = $titles[$type] ?? $type;
    $body  = $bodies[$type] ?? '';

    foreach ($recipients as $rid) {
        if ($rid === $actorUid) continue; // jamais notifier l'acteur

        // 1. In-app insert
        try {
            $st = db()->prepare(
                "INSERT INTO notifications (user_id, type, title, body, link_path, ref_type, ref_id)
                 VALUES (?, ?, ?, ?, ?, 'dossier_edit', ?)"
            );
            $st->execute([$rid, $type, $title, $body, "/edit/{$editId}", $editId]);
        } catch (Throwable $e) {
            notify_edit_event_log_stub("inapp_fail uid={$rid} edit={$editId} err=" . $e->getMessage());
        }

        // 2. Récup user prefs
        $u = notify_edit_event_user($rid);
        if (!$u) continue;

        // 3. Telegram (si chat_id + enabled)
        if (!empty($u['telegram_notifs_enabled']) && !empty($u['telegram_chat_id'])) {
            $changesText = $type === 'edit_pending' && is_array($changes)
                ? "\n\n" . notify_edit_event_render_changes($changes)
                : '';
            $tgText = htmlspecialchars($title, ENT_QUOTES, 'UTF-8') . "\n"
                    . htmlspecialchars($body, ENT_QUOTES, 'UTF-8')
                    . htmlspecialchars($changesText, ENT_QUOTES, 'UTF-8');
            $kb = null;
            if ($type === 'edit_pending') {
                $kb = [[
                    ['text' => '✓ Valider', 'callback_data' => "edit_approve:{$editId}"],
                    ['text' => '✗ Refuser', 'callback_data' => "edit_reject:{$editId}"],
                ]];
            }
            notify_edit_event_telegram((string) $u['telegram_chat_id'], $tgText, $kb);
        }

        // 4. Email (stub log uniquement — SMTP non câblé)
        if (!empty($u['email_notifs_enabled']) && !empty($u['email'])) {
            notify_edit_event_log_stub(sprintf(
                "email_stub to=%s type=%s edit=%d title=%s",
                $u['email'], $type, $editId, $title
            ));
        }
    }
}

}
