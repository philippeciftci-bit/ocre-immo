<?php
// M/2026/05/07/96 — Reset password super_admin (request).
// POST {email} -> 200 toujours (anti-enumeration OWASP). Si email = super_admin, genere
// token + envoie email avec lien /reset-password.html?token=...
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/lib/email_sender.php';
header('Content-Type: application/json; charset=utf-8');

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'method_not_allowed']);
    exit;
}

$body = json_decode(file_get_contents('php://input'), true) ?: [];
$email = strtolower(trim((string)($body['email'] ?? '')));
if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    echo json_encode(['ok' => true]); // anti-enumeration : reponse generique
    exit;
}

try {
    $meta = new PDO('mysql:host=' . DB_HOST . ';dbname=ocre_meta;charset=utf8mb4', DB_USER, DB_PASS, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    ]);
    $st = $meta->prepare("SELECT id, prenom, role FROM users WHERE email = ? AND archived_at IS NULL LIMIT 1");
    $st->execute([$email]);
    $user = $st->fetch(PDO::FETCH_ASSOC);
    if (!$user || ($user['role'] ?? '') !== 'super_admin') {
        echo json_encode(['ok' => true]); // anti-enumeration
        exit;
    }
    $token = bin2hex(random_bytes(32));
    $upd = $meta->prepare("UPDATE users SET password_reset_token = ?, password_reset_expires = DATE_ADD(NOW(), INTERVAL 1 HOUR) WHERE id = ?");
    $upd->execute([$token, $user['id']]);

    $url = 'https://superadmin.ocre.immo/reset-password.html?token=' . $token;
    $prenom = htmlspecialchars($user['prenom'] ?? 'Super-admin', ENT_QUOTES, 'UTF-8');
    $html = '<html><body style="font-family:-apple-system,BlinkMacSystemFont,sans-serif;color:#3a2e22;background:#FAF6EC;">'
        . '<div style="max-width:560px;margin:0 auto;padding:32px 24px;background:#fff;border-radius:14px;box-shadow:0 2px 10px rgba(60,40,20,0.08);">'
        . '<h1 style="font-family:\'Cormorant Garamond\',Georgia,serif;font-style:italic;color:#8B5E3C;font-weight:500;margin:0 0 12px;font-size:28px;">Réinitialisation mot de passe super-admin</h1>'
        . '<p style="font-size:15px;line-height:1.5;">Bonjour <b>' . $prenom . '</b>,</p>'
        . '<p style="font-size:15px;line-height:1.5;">Cliquez sur le bouton ci-dessous pour définir un nouveau mot de passe (lien valide 1 heure).</p>'
        // M/2026/05/14/64 — bouton spec canonical M/14/63 (Philippe).
        . '<p style="text-align:center;margin:24px 0"><a href="' . $url . '" style="display:inline-block;padding:14px 24px;background:#8B5A3C;color:#ffffff;text-decoration:none;border-radius:10px;font-family:\'DM Sans\',-apple-system,BlinkMacSystemFont,Helvetica,Arial,sans-serif;font-size:15px;font-weight:700;line-height:1.2">Réinitialiser mon mot de passe</a></p>'
        . '<p style="font-size:12px;color:#999;line-height:1.5;">Si vous n\'avez pas demandé cette réinitialisation, ignorez cet email — votre mot de passe actuel reste inchangé.</p>'
        . '<p style="font-size:11px;color:#999;margin-top:32px;border-top:1px solid #eee;padding-top:16px;">Ocre Immo · contact@ocre.immo</p>'
        . '</div></body></html>';
    if (function_exists('ocre_send_email')) {
        ocre_send_email($email, 'Réinitialisation mot de passe super-admin Ocre Immo', $html);
    }
    echo json_encode(['ok' => true]);
} catch (Throwable $e) {
    error_log('superadmin_password_reset: ' . $e->getMessage());
    echo json_encode(['ok' => true]); // jamais leak
}
