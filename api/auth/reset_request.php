<?php
// M/2026/05/14/71 — Phase B AUTH-PERENNE : request reset password via magic-link 1h.
// POST { email } -> envoie magic-link reset. Rate-limit 3/heure/email + 10/heure/IP.
// Reponse generique (ne fuit pas l'existence du compte) : toujours 200 ok=true.

declare(strict_types=1);
header('Content-Type: application/json');

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../lib/email_sender.php';
require_once __DIR__ . '/../lib/mailer.php';
require_once __DIR__ . '/../lib/password_auth.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'method']);
    exit;
}

$input = json_decode(file_get_contents('php://input') ?: '{}', true);
$email = strtolower(trim((string)($input['email'] ?? '')));
$ip = explode(',', $_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['REMOTE_ADDR'] ?? '?')[0];

if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Email invalide']);
    exit;
}

$pdo = new PDO('mysql:host=' . DB_HOST . ';dbname=ocre_meta;charset=utf8mb4', DB_USER, DB_PASS, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
password_auth_rate_limit_init($pdo);

// Rate-limit
$st = $pdo->prepare("SELECT COUNT(*) FROM auth_attempts WHERE scope='reset_request' AND identifier=? AND ts > NOW() - INTERVAL 1 HOUR");
$st->execute([$email]);
if ((int)$st->fetchColumn() >= 3) {
    // Reponse generique pour ne pas fuiter rate-limit anti-enum
    echo json_encode(['ok' => true, 'message' => 'Si ce compte existe, un email a ete envoye.']);
    exit;
}
$st = $pdo->prepare("SELECT COUNT(*) FROM auth_attempts WHERE scope='reset_request' AND ip=? AND ts > NOW() - INTERVAL 1 HOUR");
$st->execute([$ip]);
if ((int)$st->fetchColumn() >= 10) {
    echo json_encode(['ok' => true, 'message' => 'Si ce compte existe, un email a ete envoye.']);
    exit;
}

// Lookup user
$st = $pdo->prepare("SELECT id, email, prenom, slug FROM users WHERE email = ? AND archived_at IS NULL LIMIT 1");
$st->execute([$email]);
$user = $st->fetch(PDO::FETCH_ASSOC);

password_auth_rate_log($pdo, 'reset_request', $email, $ip, true, $_SERVER['HTTP_USER_AGENT'] ?? null);

if ($user) {
    $token = password_auth_generate_token();
    $tokenHash = password_auth_hash_token($token);
    $pdo->prepare("UPDATE users SET password_reset_token = ?, password_reset_expires = DATE_ADD(NOW(), INTERVAL 1 HOUR) WHERE id = ?")
        ->execute([$tokenHash, $user['id']]);
    $slug = (string)($user['slug'] ?? '');
    $base = $slug !== '' ? "https://{$slug}.ocre.immo" : 'https://app.ocre.immo';
    $resetUrl = $base . '/reset-password.html?token=' . urlencode($token);
    $html = ocre_signup_welcome_email_html(
        (string)($user['prenom'] ?? ''),
        $resetUrl,
        'Choisir un nouveau mot de passe',
        'Réinitialise ton mot de passe Ocre',
        'Tu as demande la reinitialisation de ton mot de passe.<br><span style="font-size:13px;color:#6B5642">Lien valide 1 heure. Si tu n\'es pas a l\'origine de cette demande, ignore cet email.</span>'
    );
    @ocre_send_email((string)$user['email'], 'Reinitialise ton mot de passe Ocre', $html);
}

// Reponse generique
echo json_encode(['ok' => true, 'message' => 'Si ce compte existe, un email a ete envoye.']);
