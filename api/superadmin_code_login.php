<?php
// M/2026/05/14/69 — Login superadmin via code admin direct (remplace magic-link).
// Compare hash_equals(input, ADMIN_CODE) puis cree session 30j via _session.php.
// Rate limit 5 essais/min/IP + 10 echecs sur 1h = blocage IP via table superadmin_auth_attempts.

declare(strict_types=1);
header('Content-Type: application/json');

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/_session.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'method']);
    exit;
}

$input = json_decode(file_get_contents('php://input') ?: '{}', true);
$code = isset($input['code']) ? (string)$input['code'] : '';
$ip = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['REMOTE_ADDR'] ?? '?';
$ip = explode(',', $ip)[0];
$ua = $_SERVER['HTTP_USER_AGENT'] ?? '?';

function _sa_attempts_pdo(): PDO {
    static $pdo = null;
    if ($pdo) return $pdo;
    $pdo = new PDO('mysql:host=' . DB_HOST . ';dbname=ocre_meta;charset=utf8mb4', DB_USER, DB_PASS, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    ]);
    $pdo->exec("CREATE TABLE IF NOT EXISTS superadmin_auth_attempts (
        id INT AUTO_INCREMENT PRIMARY KEY,
        ip VARCHAR(45) NOT NULL,
        ts DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        success TINYINT(1) NOT NULL DEFAULT 0,
        user_agent VARCHAR(500) DEFAULT NULL,
        INDEX idx_ip_ts (ip, ts)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    return $pdo;
}

$pdo = _sa_attempts_pdo();

// Rate limit : 5 essais/min/IP
$st = $pdo->prepare("SELECT COUNT(*) FROM superadmin_auth_attempts WHERE ip = ? AND ts > NOW() - INTERVAL 1 MINUTE");
$st->execute([$ip]);
if ((int)$st->fetchColumn() >= 5) {
    http_response_code(429);
    echo json_encode(['ok' => false, 'error' => 'Trop d\'essais, attends 1 minute']);
    exit;
}
// Blocage : 10 echecs/heure
$st = $pdo->prepare("SELECT COUNT(*) FROM superadmin_auth_attempts WHERE ip = ? AND ts > NOW() - INTERVAL 1 HOUR AND success = 0");
$st->execute([$ip]);
if ((int)$st->fetchColumn() >= 10) {
    http_response_code(429);
    echo json_encode(['ok' => false, 'error' => 'IP temporairement bloquee']);
    exit;
}

// Verif code (hash_equals constant time)
$expected = defined('ADMIN_CODE') ? ADMIN_CODE : '';
if ($expected === '' || !hash_equals($expected, $code)) {
    // Log echec + delai anti-brute-force
    $pdo->prepare("INSERT INTO superadmin_auth_attempts (ip, success, user_agent) VALUES (?, 0, ?)")->execute([$ip, substr($ua, 0, 500)]);
    usleep(500000); // 500ms
    // Notif Telegram urgente
    @exec('/root/bin/notify --project ocre --priority warning --title ' . escapeshellarg('Tentative login superadmin echouee')
        . ' --body ' . escapeshellarg("IP=$ip UA=" . substr($ua, 0, 80)) . ' > /dev/null 2>&1 &');
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'Code invalide']);
    exit;
}

// Succes : log + crée session + cookie
$pdo->prepare("INSERT INTO superadmin_auth_attempts (ip, success, user_agent) VALUES (?, 1, ?)")->execute([$ip, substr($ua, 0, 500)]);

// super_admin user_id : query ocre_meta.users WHERE role='super_admin' LIMIT 1
$metaPdo = new PDO('mysql:host=' . DB_HOST . ';dbname=ocre_meta;charset=utf8mb4', DB_USER, DB_PASS, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$row = $metaPdo->query("SELECT id FROM users WHERE role='super_admin' AND (archived_at IS NULL OR archived_at=0) ORDER BY id LIMIT 1")->fetch(PDO::FETCH_ASSOC);
if (!$row || !isset($row['id'])) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Aucun super_admin en DB']);
    exit;
}
$userId = (int)$row['id'];

$token = createSession($userId, $ua, $ip);
setSessionCookie($token);

echo json_encode([
    'ok' => true,
    'redirect' => '/superadmin/',
    'session_token' => $token, // M/14/57 — bridge legacy X-Session-Token (compat helper api())
]);
