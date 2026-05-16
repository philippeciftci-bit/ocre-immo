<?php
declare(strict_types=1);
// Ocre error-log endpoint — M/2026/05/16/5
// Recoit les payloads de /assets/error-logger.js, persiste en ocre_meta.client_errors,
// notifie Philippe via Telegram (throttle DB 60 s par slug+type).
// N'inclut PAS db.php/_session.php : pas de check schema-drift wsp, pas d'effet de bord CORS.

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

// CORS : sous-domaines <slug>.ocre.immo (sendBeacon/XHR same-origin, mais defensif).
$origin = $_SERVER['HTTP_ORIGIN'] ?? '';
if (preg_match('#^https://(?:[a-z0-9-]+\.)?ocre\.immo$#', (string)$origin)) {
    header('Access-Control-Allow-Origin: ' . $origin);
    header('Access-Control-Allow-Credentials: true');
}
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, X-Session-Token');
header('Vary: Origin');

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') {
    http_response_code(204);
    exit;
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'method']);
    exit;
}

require_once __DIR__ . '/config.php'; // DB_HOST/DB_USER/DB_PASS uniquement.

$raw = file_get_contents('php://input');
$body = json_decode($raw ?: '', true);
if (!is_array($body)) {
    // Payload invalide : 200 silencieux, ne pas generer d'erreur cote client.
    http_response_code(200);
    echo json_encode(['ok' => true, 'ignored' => 'bad_payload']);
    exit;
}

$ALLOWED = ['JS_ERROR', 'PROMISE_REJECTION', 'CONSOLE_ERROR', 'NAV_LOOP', 'HTTP_FAIL'];
$type = (string)($body['type'] ?? 'UNKNOWN');
if (!in_array($type, $ALLOWED, true)) $type = 'UNKNOWN';

$message    = mb_substr((string)($body['message'] ?? ''), 0, 2000);
$stack      = mb_substr((string)($body['stack'] ?? ''), 0, 4000);
$url        = mb_substr((string)($body['url'] ?? ''), 0, 2000);
$meta       = (isset($body['meta']) && is_array($body['meta'])) ? $body['meta'] : null;
$userAgent  = mb_substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 512);
$ip         = $_SERVER['REMOTE_ADDR'] ?? null;

// Slug depuis le Host (<slug>.ocre.immo).
$host = $_SERVER['HTTP_HOST'] ?? '';
$slug = null;
if (preg_match('/^([a-z0-9][a-z0-9-]*)\.ocre\.immo$/i', $host, $m)) {
    $slug = strtolower($m[1]);
}

// PDO meta inline (meme pattern que db.php::currentUser, sans le check schema-drift wsp).
try {
    $pdo = new PDO(
        'mysql:host=' . DB_HOST . ';dbname=ocre_meta;charset=utf8mb4',
        DB_USER, DB_PASS,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
    );
} catch (Throwable $e) {
    http_response_code(200); // ne jamais renvoyer 5xx au logger (anti-boucle)
    echo json_encode(['ok' => false, 'error' => 'db']);
    exit;
}

// user_id best-effort : token X-Session-Token (header) ou body.token -> ocre_meta.sessions.
$user_id = null;
$token = $_SERVER['HTTP_X_SESSION_TOKEN'] ?? (string)($body['token'] ?? '');
if ($token) {
    try {
        $st = $pdo->prepare("SELECT user_id FROM sessions WHERE token = ? AND expires_at > NOW() LIMIT 1");
        $st->execute([$token]);
        $r = $st->fetch();
        if ($r && isset($r['user_id'])) $user_id = (int)$r['user_id'];
    } catch (Throwable $e) { /* best-effort, reste null */ }
}

try {
    $ins = $pdo->prepare(
        "INSERT INTO client_errors (slug, user_id, url, error_type, message, stack, user_agent, ip, meta)
         VALUES (?,?,?,?,?,?,?,?,?)"
    );
    $ins->execute([
        $slug, $user_id, $url, $type, $message, $stack, $userAgent, $ip,
        $meta ? json_encode($meta, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : null,
    ]);
    $err_id = (int)$pdo->lastInsertId();
} catch (Throwable $e) {
    http_response_code(200);
    echo json_encode(['ok' => false, 'error' => 'insert']);
    exit;
}

// Throttle DB : notifier seulement si aucune notif pour ce slug+type depuis 60 s.
$notified = false;
try {
    $thr = $pdo->prepare(
        "SELECT COUNT(*) FROM client_errors
         WHERE slug <=> ? AND error_type = ? AND notified = 1
           AND ts > DATE_SUB(NOW(), INTERVAL 60 SECOND) AND id <> ?"
    );
    $thr->execute([$slug, $type, $err_id]);
    $recently = (int)$thr->fetchColumn();

    if ($recently === 0) {
        $pdo->prepare("UPDATE client_errors SET notified = 1 WHERE id = ?")->execute([$err_id]);
        $notified = true;

        $title = 'Erreur client Ocre — ' . $type;
        $bodyMsg = 'Slug: ' . ($slug ?: '?')
                 . ' | URL: ' . mb_substr($url, 0, 120)
                 . ' | ' . mb_substr($message, 0, 220)
                 . ' | UA: ' . mb_substr($userAgent, 0, 80);

        // M/2026/05/16/5 — www-ocre (user PHP) ne peut PAS lire /root/.secrets/telegram_*
        // (mode 600 root:root) -> exec(notify) echouerait en silence. Fallback prescrit
        // par la mission : ecrire un job en spool, draine par ocre-telegram-drain.timer
        // (root, lit le token). Ecriture atomique (.tmp -> rename).
        $job = [
            'project'  => 'ocre',
            'priority' => 'warning', // alerte technique sonore (regle Philippe 15)
            'title'    => $title,
            'body'     => $bodyMsg,
            'err_id'   => $err_id,
            'ts'       => date('c'),
        ];
        $spool = '/var/lib/atelier/telegram_queue';
        $base  = $spool . '/' . date('Ymd-His') . '-' . $err_id . '-' . bin2hex(random_bytes(3));
        if (@file_put_contents($base . '.tmp',
                json_encode($job, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) !== false) {
            @rename($base . '.tmp', $base . '.json');
        }
    }
} catch (Throwable $e) { /* notif non bloquante */ }

echo json_encode(['ok' => true, 'id' => $err_id, 'notified' => $notified]);
