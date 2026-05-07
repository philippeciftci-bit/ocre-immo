<?php
// M/2026/05/07/114.1 — Helper notify_agent($user_id, $event_type, $payload).
// Dispatch sur 3 canaux selon prefs user : telegram (stub si bot absent), email (SMTP OVH M83.2.4),
// in-app (placeholder log). Idempotent via event_id optionnel pour eviter doublons.

require_once __DIR__ . '/telegram_sender.php';

/**
 * @param int $user_id
 * @param string $event_type 'match' | 'pdf_opened' | 'reminder' | 'test'
 * @param array $payload {title, body, html?, deep_link?, event_id?, buttons?: [{text, url}]}
 * @return array {ok, channels: [{name, status, error?}]}
 */
function notify_agent(int $user_id, string $event_type, array $payload): array {
    $pdo = pdo_meta();
    $st = $pdo->prepare("SELECT id, email, prenom, nom, telegram_chat_id, telegram_notifs_enabled FROM users WHERE id = ? AND archived_at IS NULL LIMIT 1");
    $st->execute([$user_id]);
    $u = $st->fetch();
    if (!$u) return ['ok' => false, 'error' => 'user_not_found'];

    // Idempotence : insertion log avant envoi pour eviter doublons en cas de retry.
    $event_id = (string)($payload['event_id'] ?? '');
    if ($event_id) {
        try {
            $chk = $pdo->prepare("SELECT id FROM notif_events WHERE event_id = ? LIMIT 1");
            $chk->execute([$event_id]);
            if ($chk->fetch()) return ['ok' => true, 'dedup' => true];
        } catch (Throwable $_) { /* table possiblement absente, continue best-effort */ }
    }

    $channels = [];
    $title = (string)($payload['title'] ?? 'Notification');
    $body = (string)($payload['body'] ?? '');
    $html = (string)($payload['html'] ?? ($title . "\n\n" . $body));

    // Canal 1 : Telegram (si chat_id + notifs enabled)
    if (!empty($u['telegram_chat_id']) && (int)$u['telegram_notifs_enabled'] === 1) {
        $opts = [];
        if (!empty($payload['buttons']) && is_array($payload['buttons'])) {
            $opts['reply_markup'] = ['inline_keyboard' => [array_map(function ($b) {
                return ['text' => (string)($b['text'] ?? 'Voir'), 'url' => (string)($b['url'] ?? '#')];
            }, $payload['buttons'])]];
        }
        $r = tg_send_message((string)$u['telegram_chat_id'], $html, $opts);
        $channels[] = ['name' => 'telegram'] + $r;
    } else {
        $channels[] = ['name' => 'telegram', 'ok' => false, 'skip' => true, 'reason' => 'not_linked_or_disabled'];
    }

    // Canal 2 : Email (best-effort, helper existant si dispo)
    if (!empty($u['email']) && function_exists('ocre_send_email')) {
        try {
            ocre_send_email((string)$u['email'], $title, $body, ['html' => $html]);
            $channels[] = ['name' => 'email', 'ok' => true];
        } catch (Throwable $e) {
            $channels[] = ['name' => 'email', 'ok' => false, 'error' => $e->getMessage()];
        }
    } else {
        $channels[] = ['name' => 'email', 'ok' => false, 'skip' => true, 'reason' => 'no_email_or_helper'];
    }

    // Canal 3 : In-app (log table notif_events)
    try {
        $pdo->prepare("INSERT INTO notif_events (event_id, user_id, event_type, payload_json, created_at) VALUES (?, ?, ?, ?, NOW())")
            ->execute([$event_id ?: bin2hex(random_bytes(8)), $user_id, $event_type, json_encode($payload, JSON_UNESCAPED_UNICODE)]);
        $channels[] = ['name' => 'in_app', 'ok' => true];
    } catch (Throwable $e) {
        // Table possiblement absente -> log fichier en fallback.
        @error_log('[notify_agent] log_fail user=' . $user_id . ' event=' . $event_type . ' : ' . $e->getMessage());
        $channels[] = ['name' => 'in_app', 'ok' => false, 'error' => $e->getMessage()];
    }

    return ['ok' => true, 'channels' => $channels];
}
