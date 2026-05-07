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

$user = current_user_or_401();
$action = (string)($_GET['action'] ?? $_POST['action'] ?? '');
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

// POST ?action=webhook : reception update Telegram (a appeler via setWebhook BotFather).
// Endpoint public protege par secret URL path. TODO M114.2 : routing FastAPI plutot que PHP direct.
if ($action === 'webhook') {
    // M114.1 stub : reception minimale pour debug. Implementation complete = M114.2.
    $body = file_get_contents('php://input');
    @file_put_contents('/var/log/ocre-telegram-webhook.log', date('c') . ' ' . $body . "\n", FILE_APPEND);
    jout(['ok' => true, 'stub' => true]);
}

jout(['ok' => false, 'error' => 'action_unknown', 'allowed' => ['status', 'generate_token', 'test_notify', 'disconnect']], 400);
