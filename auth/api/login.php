<?php
// M97 — POST /api/login.php
// Body : {email}. Génère magic token + envoie email. Anti-enumeration : retour 200 toujours.

require_once __DIR__ . '/../lib/auth_db.php';
require_once __DIR__ . '/../lib/email.php';

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    auth_send_json(['ok' => false, 'error' => 'method'], 405);
}

auth_ensure_schema();

$ip = auth_client_ip();
if (!auth_rate_limit_check($ip, 'login', 10, 3600)) {
    auth_send_json(['ok' => false, 'error' => 'rate_limit'], 429);
}
auth_rate_limit_record($ip, 'login');

$raw = file_get_contents('php://input');
$data = json_decode($raw, true);
if (!is_array($data)) {
    parse_str($raw, $data);
    if (!is_array($data) || !isset($data['email'])) $data = $_POST;
}
$email = strtolower(trim($data['email'] ?? ''));

if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    auth_send_json(['ok' => false, 'error' => 'email_invalid'], 400);
}

// Anti-enumeration : on génère/répond pareil que l'email existe ou non.
try {
    $userId = auth_get_or_create_user($email);
    $token = bin2hex(random_bytes(32));
    $st = auth_db()->prepare(
        "INSERT INTO auth_magic_tokens (user_id, token, expires_at, ip)
         VALUES (?, ?, DATE_ADD(NOW(), INTERVAL 15 MINUTE), ?)"
    );
    $st->execute([$userId, $token, $ip]);

    $url = 'https://auth.ocre.immo/api/magic-link/validate.php?token=' . $token;
    $html = '<!DOCTYPE html><html lang="fr"><body style="font-family:-apple-system,sans-serif;background:#f5f1eb;padding:32px">'
        . '<div style="max-width:480px;margin:0 auto;background:#fff;padding:32px;border-radius:8px">'
        . '<h1 style="font-family:Georgia,serif;font-style:italic;color:#8b6f3a;margin-top:0">Connexion à Ocre Immo</h1>'
        . '<p>Bonjour,</p>'
        . '<p>Voici ton lien de connexion magique. Il expire dans <strong>15 minutes</strong>.</p>'
        . '<p style="text-align:center;margin:32px 0">'
        . '<a href="' . htmlspecialchars($url) . '" style="background:#8b6f3a;color:#fff;text-decoration:none;padding:14px 28px;border-radius:6px;font-weight:600">Me connecter</a>'
        . '</p>'
        . '<p style="font-size:13px;color:#666">Ou copie ce lien dans ton navigateur :<br><span style="word-break:break-all;color:#8b6f3a">' . htmlspecialchars($url) . '</span></p>'
        . '<hr style="border:0;border-top:1px solid #e5dac6;margin:32px 0">'
        . '<p style="font-size:12px;color:#999">Si tu n\'as pas demandé ce lien, ignore cet email. Ton compte reste sécurisé.</p>'
        . '<p style="font-size:12px;color:#999">— L\'équipe Ocre Immo</p>'
        . '</div></body></html>';
    $text = "Connexion à Ocre Immo\n\nVoici ton lien de connexion magique (15 min) :\n$url\n\nSi tu n'as pas demandé ce lien, ignore cet email.\n— Ocre Immo";

    @email_send($email, 'Connectez-vous à Ocre Immo', $html, $text);
} catch (Exception $e) {
    error_log('login: ' . $e->getMessage());
}

auth_send_json(['ok' => true, 'message' => 'Email envoyé']);
