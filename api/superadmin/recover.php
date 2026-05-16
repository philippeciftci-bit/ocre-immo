<?php
// M/2026/05/16/12 — Recuperation self-service du code super-admin par email.
// POST { email } -> si email whitelist super-admin : envoie le code admin par mail.
// Anti-enumeration OWASP : reponse generique identique que l'email soit autorise ou non.
// Throttle 1 demande / email / 10 min via table ocre_meta.superadmin_recovery.
// Toutes tentatives (autorisees ou non) loggees en DB + notif Telegram a Philippe.

declare(strict_types=1);
header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../lib/email_sender.php';

const RECOVER_VAGUE_MSG = 'Si cet email est autorisé, le code arrive sous peu.';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'method']);
    exit;
}

$input = json_decode(file_get_contents('php://input') ?: '{}', true);
$email = strtolower(trim((string)($input['email'] ?? '')));
$ip = explode(',', $_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['REMOTE_ADDR'] ?? '?')[0];
$ua = substr((string)($_SERVER['HTTP_USER_AGENT'] ?? '?'), 0, 500);

if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Email invalide']);
    exit;
}

$pdo = new PDO('mysql:host=' . DB_HOST . ';dbname=ocre_meta;charset=utf8mb4', DB_USER, DB_PASS, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
]);
$pdo->exec("CREATE TABLE IF NOT EXISTS superadmin_recovery (
    id INT AUTO_INCREMENT PRIMARY KEY,
    email VARCHAR(255) NOT NULL,
    requested_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    ip VARCHAR(45) DEFAULT NULL,
    ua VARCHAR(500) DEFAULT NULL,
    sent_at DATETIME DEFAULT NULL,
    expires_at DATETIME DEFAULT NULL,
    INDEX idx_email_req (email, requested_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

// Notif Telegram a Philippe pour CHAQUE tentative (legitime ou non).
@shell_exec('/root/bin/notify --project ocre --priority high '
    . '--title ' . escapeshellarg('Demande récup code superadmin')
    . ' --body ' . escapeshellarg("$email IP $ip")
    . ' >/dev/null 2>&1 &');

// Whitelist : users role=super_admin actifs OU env SUPERADMIN_EMAILS (CSV).
// Defaut si liste vide : philippe.ciftci@gmail.com (unique super-admin legitime).
$whitelist = [];
try {
    $rows = $pdo->query("SELECT LOWER(email) AS email FROM users WHERE role='super_admin' AND (archived_at IS NULL OR archived_at=0)")
        ->fetchAll(PDO::FETCH_COLUMN);
    foreach ($rows as $e) {
        $e = trim((string)$e);
        if ($e !== '') $whitelist[] = $e;
    }
} catch (Throwable $e) { /* table users absente : on retombe sur env/defaut */ }
$envList = (string)(getenv('SUPERADMIN_EMAILS') ?: '');
foreach (explode(',', $envList) as $e) {
    $e = strtolower(trim($e));
    if ($e !== '') $whitelist[] = $e;
}
if (!$whitelist) $whitelist = ['philippe.ciftci@gmail.com'];
$whitelist = array_values(array_unique($whitelist));
$isAuthorized = in_array($email, $whitelist, true);

// Throttle : 1 demande / email / 10 min (sur les demandes deja envoyees).
$st = $pdo->prepare("SELECT COUNT(*) FROM superadmin_recovery WHERE email = ? AND sent_at IS NOT NULL AND sent_at > NOW() - INTERVAL 10 MINUTE");
$st->execute([$email]);
$throttled = ((int)$st->fetchColumn()) >= 1;

$sent = false;
if ($isAuthorized && !$throttled) {
    $code = defined('ADMIN_CODE') ? (string)ADMIN_CODE : '';
    if ($code !== '') {
        $html = '<!DOCTYPE html><html lang="fr"><head><meta charset="UTF-8"></head>'
            . '<body style="font-family:-apple-system,BlinkMacSystemFont,Helvetica,Arial,sans-serif;color:#3a2e22;background:#FAF6EC;margin:0;padding:32px">'
            . '<div style="max-width:480px;margin:0 auto;padding:36px 30px;background:#fff;border-radius:14px;border:1px solid #E5DAC6">'
            . '<h1 style="font-family:\'Cormorant Garamond\',Georgia,serif;color:#8B5A3C;font-style:italic;font-weight:600;margin:0 0 18px;font-size:26px;text-align:center">Ocre Immo · Code super-admin</h1>'
            . '<p style="font-size:15px;line-height:1.5">Voici ton code d\'accès super-administrateur :</p>'
            . '<p style="text-align:center;margin:28px 0">'
            . '<span style="display:inline-block;font-family:\'DM Mono\',\'Courier New\',monospace;font-size:26px;font-weight:700;letter-spacing:2px;color:#2A1810;background:#FAF3E6;border:1px solid #E5DAC6;border-radius:10px;padding:16px 28px">'
            . htmlspecialchars($code) . '</span></p>'
            . '<p style="font-size:14px;line-height:1.5;color:#8B5A3C;font-weight:600">⚠ Ne partage ce code avec personne.</p>'
            . '<p style="font-size:12px;color:#999;line-height:1.5">Si tu n\'es pas à l\'origine de cette demande, ignore cet email et préviens-nous à contact@ocre.immo.</p>'
            . '<p style="font-size:11px;color:#999;margin-top:32px;border-top:1px solid #eee;padding-top:16px">Ocre Immo · contact@ocre.immo</p>'
            . '</div></body></html>';
        $sent = @ocre_send_email($email, 'Ton code super-admin Ocre Immo', $html);
    }
}

// Log de la tentative (toutes : autorisees, non-autorisees, throttlees).
$ins = $pdo->prepare("INSERT INTO superadmin_recovery (email, ip, ua, sent_at, expires_at) VALUES (?, ?, ?, ?, ?)");
$ins->execute([
    $email,
    $ip,
    $ua,
    $sent ? date('Y-m-d H:i:s') : null,
    $sent ? date('Y-m-d H:i:s', time() + 600) : null,
]);

echo json_encode(['ok' => true, 'msg' => RECOVER_VAGUE_MSG], JSON_UNESCAPED_UNICODE);
