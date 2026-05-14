<?php
// M/2026/05/14/71 — Phase B AUTH-PERENNE : complete reset password.
// POST { token, password, confirmation } -> valide token (SHA-256 hash compare) + hash new
// + INVALIDATE all sessions existantes (rotation) + set cookie 30j.

declare(strict_types=1);
header('Content-Type: application/json');

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../_session.php';
require_once __DIR__ . '/../lib/email_sender.php';
require_once __DIR__ . '/../lib/mailer.php';
require_once __DIR__ . '/../lib/password_auth.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'method']);
    exit;
}

$input = json_decode(file_get_contents('php://input') ?: '{}', true);
$token = trim((string)($input['token'] ?? ''));
$password = (string)($input['password'] ?? '');
$confirmation = (string)($input['confirmation'] ?? '');
$ip = explode(',', $_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['REMOTE_ADDR'] ?? '?')[0];
$ua = $_SERVER['HTTP_USER_AGENT'] ?? '?';

if ($token === '' || $password === '') {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Champs requis manquants']);
    exit;
}
if ($password !== $confirmation) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Les mots de passe ne correspondent pas']);
    exit;
}
$strengthErr = password_auth_validate_strength($password);
if ($strengthErr !== null) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => $strengthErr]);
    exit;
}

$pdo = new PDO('mysql:host=' . DB_HOST . ';dbname=ocre_meta;charset=utf8mb4', DB_USER, DB_PASS, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);

// Valider token (stocke en SHA-256)
$tokenHash = password_auth_hash_token($token);
$st = $pdo->prepare("SELECT id, email, prenom FROM users WHERE password_reset_token = ? AND password_reset_expires > NOW() AND archived_at IS NULL LIMIT 1");
$st->execute([$tokenHash]);
$user = $st->fetch(PDO::FETCH_ASSOC);

if (!$user) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Lien invalide ou expire']);
    exit;
}

// Hash nouveau + clear reset_token + reset failed_login + last_login
$hash = password_auth_hash($password);
$pdo->prepare("UPDATE users SET password_hash = ?, password_set_at = NOW(),
    password_reset_token = NULL, password_reset_expires = NULL,
    failed_login_count = 0, locked_until = NULL, last_login = NOW()
    WHERE id = ?")->execute([$hash, $user['id']]);

// Rotation : revoke toutes les sessions existantes (force re-login sur autres devices).
revokeAllSessions((int)$user['id']);

// Nouvelle session 30j
$newToken = createSession((int)$user['id'], $ua, $ip);
setSessionCookie($newToken);

password_auth_rate_log($pdo, 'reset_complete', (string)$user['email'], $ip, true, $ua);

// Notif email confirmation (info, pas de bouton car aucune action a faire)
$infoHtml = '<!DOCTYPE html><html><body style="font-family:-apple-system,sans-serif;color:#3a2e22;background:#FAF6EC;padding:32px;margin:0">'
    . '<div style="max-width:560px;margin:0 auto;padding:36px 30px;background:#fff;border-radius:14px;border:1px solid #E5DAC6">'
    . '<h1 style="font-family:\'Cormorant Garamond\',Georgia,serif;color:#8B5A3C;font-style:italic;font-weight:600;margin:0 0 16px;font-size:24px;text-align:center">Mot de passe modifie</h1>'
    . '<p style="font-size:15px;line-height:1.5">Bonjour ' . htmlspecialchars((string)($user['prenom'] ?? ''), ENT_QUOTES) . ',</p>'
    . '<p style="font-size:15px;line-height:1.5">Ton mot de passe Ocre Immo vient d\'etre modifie a ' . date('H:i') . ' (heure serveur).</p>'
    . '<p style="font-size:13px;color:#6B5642;line-height:1.5">Tu peux maintenant te reconnecter avec ton nouveau mot de passe. Toutes tes sessions actives sur d\'autres appareils ont ete fermees par securite.</p>'
    . '<p style="font-size:13px;color:#6B5642;line-height:1.5">Si tu n\'es pas a l\'origine de cette modification : <a href="mailto:contact@ocre.immo" style="color:#8B5A3C">contact@ocre.immo</a></p>'
    . '<p style="font-size:11px;color:#999;margin-top:32px;border-top:1px solid #eee;padding-top:16px">Ocre Immo · contact@ocre.immo</p>'
    . '</div></body></html>';
@ocre_send_email((string)$user['email'], 'Ton mot de passe Ocre a ete modifie', $infoHtml);

echo json_encode([
    'ok' => true,
    'redirect' => '/',
    'session_token' => $newToken,
]);
