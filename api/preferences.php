<?php
// M/2026/04/28/54 — Préférences agent (notif + match perso + apparence).
// Stockage : ocre_meta.users.preferences JSON (idempotent).
require_once __DIR__ . '/db.php';
setCorsHeaders();

$user = requireAuth();
$action = $_GET['action'] ?? ($_POST['action'] ?? 'get');
$uid = (int) ($user['_origin_user_id'] ?? $user['id']);
$input = getInput();

function ensurePrefsColumn(): PDO {
    static $meta = null;
    if ($meta) return $meta;
    $dsn = 'mysql:host=' . DB_HOST . ';dbname=ocre_meta;charset=utf8mb4';
    $meta = new PDO($dsn, DB_USER, DB_PASS, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
    try {
        $cols = $meta->query("SHOW COLUMNS FROM users")->fetchAll(PDO::FETCH_COLUMN);
        if (!in_array('preferences', $cols, true)) {
            $meta->exec("ALTER TABLE users ADD COLUMN preferences LONGTEXT NULL");
        }
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
    return $meta;
}

function defaultPrefs(): array {
    return [
        'notif' => [
            'telegram' => ['enabled' => true, 'chat_id' => ''],
            'email' => ['enabled' => true, 'offline_threshold_min' => 30],
            'in_app' => ['enabled' => true],
        ],
        'notif_types' => ['new_match_85', 'edit_pending', 'edit_approved', 'edit_rejected', 'event_reminder_1h'],
        'match_pref' => [
            'seuil_min_pct' => 70,
            'tolerances' => ['budget_pct' => 10, 'surface_hab_pct' => 25, 'surface_terrain_pct' => 50, 'chambres' => 1],
            'critere_order' => ['pays','ville','type_bien','budget','quartier','surface_hab','chambres','equipements','surface_terrain','etat'],
            'cross_profile_pairs' => [['acheteur','vendeur'],['locataire','bailleur']],
        ],
        'appearance' => ['theme' => 'light', 'density' => 'normal', 'lang' => 'fr'],
    ];
}

$meta = ensurePrefsColumn();

switch ($action) {

case 'get': {
    $st = $meta->prepare("SELECT preferences, telegram_chat_id, email FROM users WHERE id = ?");
    $st->execute([$uid]);
    $row = $st->fetch();
    $prefs = defaultPrefs();
    if ($row && !empty($row['preferences'])) {
        $stored = json_decode($row['preferences'], true);
        if (is_array($stored)) {
            $prefs = array_replace_recursive($prefs, $stored);
        }
    }
    if (!empty($row['telegram_chat_id'])) $prefs['notif']['telegram']['chat_id'] = (string) $row['telegram_chat_id'];
    $prefs['_email'] = $row['email'] ?? '';
    jsonOk(['preferences' => $prefs]);
}

case 'update': {
    $body = $input['preferences'] ?? null;
    if (!is_array($body)) jsonError('preferences (array) requis', 400);
    $merged = array_replace_recursive(defaultPrefs(), $body);
    $tgChat = isset($merged['notif']['telegram']['chat_id']) ? trim((string) $merged['notif']['telegram']['chat_id']) : '';
    $tgEnabled = !empty($merged['notif']['telegram']['enabled']) ? 1 : 0;
    $emailEnabled = !empty($merged['notif']['email']['enabled']) ? 1 : 0;
    $st = $meta->prepare(
        "UPDATE users SET preferences = ?, telegram_chat_id = ?, telegram_notifs_enabled = ?, email_notifs_enabled = ? WHERE id = ?"
    );
    $st->execute([json_encode($merged, JSON_UNESCAPED_UNICODE), $tgChat ?: null, $tgEnabled, $emailEnabled, $uid]);
    jsonOk(['preferences' => $merged]);
}

case 'test_telegram': {
    $chatId = trim((string) ($input['chat_id'] ?? $_GET['chat_id'] ?? ''));
    if (!$chatId || !ctype_digit(ltrim($chatId, '-'))) jsonError('chat_id numérique requis', 400);
    $tokenFile = '/root/.secrets/telegram_atelier';
    if (!is_readable($tokenFile)) jsonError('Bot Telegram non configuré', 500);
    $tok = trim((string) file_get_contents($tokenFile));
    $url = "https://api.telegram.org/bot{$tok}/sendMessage";
    $payload = ['chat_id' => $chatId, 'text' => '✓ Test notif Ocre Immo OK · ' . date('H:i'), 'parse_mode' => 'HTML'];
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => http_build_query($payload),
        CURLOPT_TIMEOUT => 6,
    ]);
    $resp = curl_exec($ch);
    $err = curl_error($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($err || $code !== 200) jsonError('Échec envoi : HTTP ' . $code . ' ' . substr($err . ' ' . $resp, 0, 200), 500);
    $j = json_decode($resp, true) ?: [];
    if (empty($j['ok'])) jsonError('Telegram a répondu KO : ' . substr($resp, 0, 200), 500);
    jsonOk(['sent' => true]);
}

default:
    jsonError('Action inconnue (get | update | test_telegram)', 400);
}
