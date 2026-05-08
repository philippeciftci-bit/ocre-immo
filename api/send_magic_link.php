<?php
// M/2026/05/08/69 — Endpoint magic link login (auth magic-link-only).
// POST JSON {email}
//
// Sequence :
//   1. Valider email format
//   2. Lookup user dans ocre_meta.users (active OU pending_activation, archived_at IS NULL)
//   3. Si user trouve : generer nouveau activation_token + expires 48h, sauvegarder, envoyer email
//   4. Si user pas trouve : retourner 200 OK sans envoyer (pas de leak existence email cf OWASP)
//   5. Reponse uniforme {ok:true, sent: <bool>}
//
// Reponses :
//   200 {ok:true, sent:true|false}
//   400 {ok:false, error:'EMAIL_INVALID'}
//   500 {ok:false, error:'SERVER_ERROR'}

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/lib/email_sender.php';
setCorsHeaders();

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'METHOD_NOT_ALLOWED']);
    exit;
}

$input = getInput();
$email = strtolower(trim((string)($input['email'] ?? '')));

if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'EMAIL_INVALID']);
    exit;
}

try {
    $pdo = new PDO(
        'mysql:host=' . DB_HOST . ';dbname=ocre_meta;charset=utf8mb4',
        DB_USER, DB_PASS,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
    );
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'SERVER_ERROR', 'detail' => 'db connect']);
    exit;
}

$st = $pdo->prepare("SELECT id, email, prenom, slug FROM users WHERE email = ? AND archived_at IS NULL LIMIT 1");
$st->execute([$email]);
$user = $st->fetch();

if (!$user) {
    // Anti-leak : reponse uniforme. On retourne ok=true mais sent=false.
    @file_put_contents('/var/log/ocre-signup-errors.log',
        '[' . date('c') . '] INFO send_magic_link unknown email=' . $email . "\n", FILE_APPEND);
    http_response_code(200);
    echo json_encode(['ok' => true, 'sent' => false]);
    exit;
}

$token = bin2hex(random_bytes(32));
try {
    $upd = $pdo->prepare("UPDATE users SET activation_token = ?, activation_token_expires_at = DATE_ADD(NOW(), INTERVAL 48 HOUR), last_activation_attempt_at = NOW() WHERE id = ?");
    $upd->execute([$token, (int)$user['id']]);
} catch (Throwable $e) {
    @error_log('[send_magic_link] update_token_failed user_id=' . $user['id'] . ' err=' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'SERVER_ERROR', 'detail' => 'update token']);
    exit;
}

$slug = (string)$user['slug'];
$prenom = (string)($user['prenom'] ?: '');
$url = ($slug !== '' && preg_match('/^[a-z0-9-]+$/', $slug))
    ? 'https://' . $slug . '.ocre.immo/?activate=' . $token
    : 'https://app.ocre.immo/?activate=' . $token;

$subject = 'Votre lien de connexion à Oi Agent';
$safePrenom = htmlspecialchars($prenom, ENT_QUOTES, 'UTF-8');
$html = '<html><body style="font-family:-apple-system,BlinkMacSystemFont,sans-serif;color:#3a2e22;background:#FAF6EC;margin:0;padding:0;">'
    . '<div style="max-width:560px;margin:0 auto;padding:32px 24px;background:#fff;border-radius:14px;box-shadow:0 2px 10px rgba(60,40,20,0.08);">'
    . '<h1 style="font-family:\'Cormorant Garamond\',Georgia,serif;font-style:italic;color:#8B5E3C;font-weight:500;margin:0 0 12px;font-size:28px;">Voici votre lien de connexion</h1>'
    . '<p style="font-size:15px;line-height:1.5;">Bonjour' . ($safePrenom !== '' ? ' <b>' . $safePrenom . '</b>' : '') . ',</p>'
    . '<p style="font-size:15px;line-height:1.5;">Cliquez sur le bouton ci-dessous pour accéder directement à votre espace de travail.</p>'
    . '<p style="font-size:13px;color:#6B5E4A;line-height:1.5;font-style:italic;">Lien valide 48 heures.</p>'
    . '<table border="0" cellpadding="0" cellspacing="0" role="presentation" align="center" style="margin:28px auto;">'
    . '<tr><td bgcolor="#10B981" style="border-radius:10px;background-color:#10B981;mso-padding-alt:14px 24px;">'
    . '<a href="' . $url . '" target="_blank" style="display:inline-block;padding:14px 24px;font-family:\'DM Sans\',-apple-system,BlinkMacSystemFont,sans-serif;font-size:15px;font-weight:700;color:#ffffff;text-decoration:none;border-radius:10px;border:1px solid #10B981;line-height:1.2;">Accéder à mon espace</a>'
    . '</td></tr></table>'
    . '<p style="font-size:12px;color:#999;line-height:1.5;">Si le bouton ne fonctionne pas, copiez-collez ce lien :<br><span style="word-break:break-all;">' . $url . '</span></p>'
    . '<p style="font-size:11px;color:#999;margin-top:32px;border-top:1px solid #eee;padding-top:16px;">Oi Agent — un produit Ocre · contact@ocre.immo</p>'
    . '</div></body></html>';

$sent = false;
try {
    if (function_exists('ocre_send_email')) {
        $sent = ocre_send_email($email, $subject, $html);
    }
} catch (Throwable $e) {
    @error_log('[send_magic_link] send_failed email=' . $email . ' err=' . $e->getMessage());
}

http_response_code(200);
echo json_encode(['ok' => true, 'sent' => (bool)$sent]);
