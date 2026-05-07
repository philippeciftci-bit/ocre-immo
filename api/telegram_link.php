<?php
// M/2026/05/07/114.1 — Endpoint onboarding Telegram bot @ocreimmo_bot.
// POST ?action=generate_token   auth user, genere/refresh telegram_link_token + retourne deep links.
// POST ?action=test_notify      auth user, envoie notif test via notify_agent.
// POST ?action=disconnect       auth user, clear telegram_chat_id et notifs.
// GET  ?action=status           auth user, retourne {linked: bool, chat_id, username, linked_at}.
// Mode stub par defaut si /etc/ocre/telegram-bot.env absent.

require_once __DIR__ . '/lib/router.php';
require_once __DIR__ . '/lib/telegram_sender.php';
require_once __DIR__ . '/lib/notify_agent.php';
header('Content-Type: application/json; charset=utf-8');

function jout(array $d, int $code = 200) {
    http_response_code($code);
    echo json_encode($d, JSON_UNESCAPED_UNICODE);
    exit;
}

$action = (string)($_GET['action'] ?? $_POST['action'] ?? '');

// M/2026/05/07/114.1.1 — webhook public (Telegram POST direct, pas de session). Traite avant
// current_user_or_401 sinon 401. Hardening secret_token : a configurer en M114.2 via setWebhook
// avec param secret_token, validation header X-Telegram-Bot-Api-Secret-Token cote PHP.
if ($action === 'webhook') {
    $body = file_get_contents('php://input');
    @file_put_contents('/var/log/ocre-telegram-webhook.log', date('c') . ' ' . substr($body, 0, 2000) . "\n", FILE_APPEND);
    $upd = json_decode($body ?: '', true) ?: [];
    $msg = $upd['message'] ?? null;
    if ($msg && isset($msg['text']) && strpos($msg['text'], '/start') === 0) {
        $parts = explode(' ', $msg['text'], 2);
        $start_token = isset($parts[1]) ? trim($parts[1]) : '';
        $chat_id = (string)($msg['chat']['id'] ?? '');
        $username = (string)($msg['from']['username'] ?? '');
        $first_name = (string)($msg['from']['first_name'] ?? 'agent');
        if ($start_token && $chat_id) {
            try {
                $pdo = pdo_meta();
                $st = $pdo->prepare("SELECT id, prenom, nom FROM users WHERE telegram_link_token = ? AND archived_at IS NULL LIMIT 1");
                $st->execute([$start_token]);
                $u = $st->fetch();
                if ($u) {
                    $pdo->prepare("UPDATE users SET telegram_chat_id = ?, telegram_username = ?, telegram_linked_at = NOW(), telegram_link_token = NULL WHERE id = ?")
                        ->execute([$chat_id, $username, $u['id']]);
                    $welcome = "✅ <b>Notifications activées</b>, " . htmlspecialchars($u['prenom'] ?: $first_name) . " !\n\nTu vas recevoir tes matches, ouvertures PDF et rappels en temps réel.";
                    tg_send_message($chat_id, $welcome);
                } else {
                    tg_send_message($chat_id, "⚠️ Lien invalide ou expiré. Réessaye depuis l'app Ocre Immo.");
                }
            } catch (Throwable $e) {
                @error_log('[tg-webhook] ' . $e->getMessage());
            }
        } elseif ($chat_id) {
            tg_send_message($chat_id, "Bienvenue sur Ocre Immo. Active tes notifications depuis l'app : Réglages → Canaux de notification → Activer Telegram.");
        }
    }
    jout(['ok' => true]);
}

$user = current_user_or_401();
$pdo = pdo_meta();

if ($action === 'status') {
    $st = $pdo->prepare("SELECT telegram_chat_id, telegram_username, telegram_linked_at, telegram_notifs_enabled, telegram_link_token FROM users WHERE id = ? LIMIT 1");
    $st->execute([$user['id']]);
    $u = $st->fetch();
    $linked = !empty($u['telegram_chat_id']);
    jout([
        'ok' => true,
        'linked' => $linked,
        'chat_id' => $linked ? (string)$u['telegram_chat_id'] : null,
        'username' => $u['telegram_username'] ?? null,
        'linked_at' => $u['telegram_linked_at'] ?? null,
        'notifs_enabled' => (int)($u['telegram_notifs_enabled'] ?? 1) === 1,
        'has_pending_token' => !empty($u['telegram_link_token']),
        'bot_username' => tg_bot_username(),
        'stub_mode' => tg_bot_token() === '',
    ]);
}

if ($action === 'generate_token') {
    $token = bin2hex(random_bytes(16)); // 32 chars hex
    $pdo->prepare("UPDATE users SET telegram_link_token = ? WHERE id = ?")->execute([$token, $user['id']]);
    $links = tg_deep_link($token);
    jout([
        'ok' => true,
        'token' => $token,
        'expires_in_days' => 7,
        'deep_links' => $links,
        'stub_mode' => tg_bot_token() === '',
    ]);
}

if ($action === 'test_notify') {
    $r = notify_agent((int)$user['id'], 'test', [
        'title' => 'Test Ocre Immo',
        'body' => 'Si tu reçois ce message, ton canal Telegram est bien connecté.',
        'html' => "🧪 <b>Test Ocre Immo</b>\n\nSi tu reçois ce message, ton canal Telegram est bien connecté.",
        'event_id' => 'test-' . $user['id'] . '-' . time(),
    ]);
    jout($r);
}

if ($action === 'disconnect') {
    $pdo->prepare("UPDATE users SET telegram_chat_id = NULL, telegram_username = NULL, telegram_linked_at = NULL, telegram_link_token = NULL WHERE id = ?")
        ->execute([$user['id']]);
    jout(['ok' => true, 'disconnected' => true]);
}

// (action=webhook deja traite en haut, avant current_user_or_401, car Telegram appelle sans session)

jout(['ok' => false, 'error' => 'action_unknown', 'allowed' => ['status', 'generate_token', 'test_notify', 'disconnect']], 400);
