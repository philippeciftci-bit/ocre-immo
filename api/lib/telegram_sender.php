<?php
// M/2026/05/07/114.1 — Helper Telegram bot @ocreimmo_bot.
// Mode stub si /etc/ocre/telegram-bot.env absent ou TELEGRAM_BOT_TOKEN vide.
// Mode reel : POST sendMessage avec parse_mode HTML + reply_markup pour boutons inline.

const TG_BOT_ENV = '/etc/ocre/telegram-bot.env';

function tg_bot_token(): string {
    static $cached = null;
    if ($cached !== null) return $cached;
    if (!is_readable(TG_BOT_ENV)) { $cached = ''; return ''; }
    $content = @file_get_contents(TG_BOT_ENV) ?: '';
    if (preg_match('/TELEGRAM_BOT_TOKEN=([^\s\n]+)/', $content, $m)) {
        $cached = trim($m[1]);
        return $cached;
    }
    $cached = '';
    return '';
}

function tg_bot_username(): string {
    static $cached = null;
    if ($cached !== null) return $cached;
    if (!is_readable(TG_BOT_ENV)) { $cached = 'ocreimmo_bot'; return $cached; }
    $content = @file_get_contents(TG_BOT_ENV) ?: '';
    if (preg_match('/TELEGRAM_BOT_USERNAME=([^\s\n]+)/', $content, $m)) {
        $cached = trim($m[1]);
        return $cached;
    }
    $cached = 'ocreimmo_bot';
    return $cached;
}

/**
 * Envoie un message Telegram a un chat_id.
 * @return array {ok: bool, stub?: bool, error?: string, message_id?: int}
 */
function tg_send_message(string $chat_id, string $text, array $opts = []): array {
    if (!$chat_id) return ['ok' => false, 'error' => 'chat_id manquant'];
    $token = tg_bot_token();
    if (!$token) {
        // Mode stub : log + retour ok pour ne pas casser le caller.
        @error_log('[tg-stub] chat=' . $chat_id . ' text=' . substr($text, 0, 80));
        return ['ok' => true, 'stub' => true, 'message_id' => 0];
    }
    $payload = [
        'chat_id' => $chat_id,
        'text' => $text,
        'parse_mode' => $opts['parse_mode'] ?? 'HTML',
        'disable_web_page_preview' => true,
    ];
    if (!empty($opts['reply_markup'])) $payload['reply_markup'] = json_encode($opts['reply_markup']);
    $url = 'https://api.telegram.org/bot' . $token . '/sendMessage';
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => http_build_query($payload),
        CURLOPT_TIMEOUT => 8,
    ]);
    $res = curl_exec($ch);
    $http = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    $j = json_decode($res ?: '', true) ?: [];
    if ($http !== 200 || empty($j['ok'])) {
        return ['ok' => false, 'error' => $j['description'] ?? ('HTTP ' . $http)];
    }
    return ['ok' => true, 'message_id' => $j['result']['message_id'] ?? 0];
}

/**
 * Genere un deep-link tg://resolve?domain=ocreimmo_bot&start=<token> + URL https fallback.
 */
function tg_deep_link(string $start_token): array {
    $bot = tg_bot_username();
    return [
        'tg' => 'tg://resolve?domain=' . urlencode($bot) . '&start=' . urlencode($start_token),
        'https' => 'https://t.me/' . urlencode($bot) . '?start=' . urlencode($start_token),
    ];
}
