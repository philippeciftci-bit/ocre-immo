<?php
// M/2026/05/09/75 — Endpoint magic link login super-admin.
// POST JSON {email}
// Sequence :
//   1. Valider email format
//   2. Lookup user dans ocre_meta.users WHERE email=? AND role='super_admin' AND archived_at IS NULL
//   3. Si trouve : generer activation_token + expires 24h, sauvegarder, envoyer email
//   4. Si pas trouve : retour 200 ok=true (pas de leak existence)
// Retour : 200 {ok:true, sent: <bool>}

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

$st = $pdo->prepare("SELECT id, email, prenom FROM users WHERE email = ? AND role = 'super_admin' AND archived_at IS NULL LIMIT 1");
$st->execute([$email]);
$user = $st->fetch();

if (!$user) {
    @file_put_contents('/var/log/ocre-signup-errors.log',
        '[' . date('c') . '] INFO superadmin_magic_link unknown_or_not_admin email=' . $email . "\n", FILE_APPEND);
    http_response_code(200);
    echo json_encode(['ok' => true, 'sent' => false]);
    exit;
}

// Reuse colonnes activation_token (deja existantes table users) avec expiration 24h.
$token = bin2hex(random_bytes(32));
try {
    $upd = $pdo->prepare("UPDATE users SET activation_token = ?, activation_token_expires_at = DATE_ADD(NOW(), INTERVAL 24 HOUR), last_activation_attempt_at = NOW() WHERE id = ?");
    $upd->execute([$token, (int)$user['id']]);
} catch (Throwable $e) {
    @error_log('[superadmin_magic_link] update_token_failed user_id=' . $user['id'] . ' err=' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'SERVER_ERROR']);
    exit;
}

$url = 'https://superadmin.ocre.immo/?activate=' . $token;
$prenom = (string)($user['prenom'] ?: '');
$safePrenom = htmlspecialchars($prenom, ENT_QUOTES, 'UTF-8');
$subject = 'Lien d\'accès super-admin Ocre Immo';
$html = '<html><body style="font-family:-apple-system,sans-serif;color:#3a2e22;background:#FAF6EC;">'
    . '<div style="max-width:560px;margin:0 auto;padding:32px 24px;background:#fff;border-radius:14px;">'
    . '<h1 style="font-family:Cormorant Garamond,serif;font-style:italic;color:#8B5E3C;font-weight:500;font-size:28px;">Accès super-admin</h1>'
    . '<p>Bonjour' . ($safePrenom !== '' ? ' <b>' . $safePrenom . '</b>' : '') . ',</p>'
    . '<p>Voici votre lien d\'accès au dashboard super-admin Ocre Immo.</p>'
    . '<p style="font-style:italic;color:#6B5E4A;">Lien valide 24 heures.</p>'
    . '<p style="margin:28px 0;text-align:center;">'
    . '<a href="' . $url . '" style="display:inline-block;padding:14px 24px;background:#8B5E3C;color:#fff;text-decoration:none;border-radius:10px;font-weight:700;">Accéder au dashboard</a></p>'
    . '<p style="font-size:12px;color:#999;">Si le bouton ne fonctionne pas : <span style="word-break:break-all;">' . $url . '</span></p>'
    . '</div></body></html>';

$sent = false;
try {
    if (function_exists('ocre_send_email')) $sent = ocre_send_email($email, $subject, $html);
} catch (Throwable $e) {
    @error_log('[superadmin_magic_link] send_failed email=' . $email . ' err=' . $e->getMessage());
}

http_response_code(200);
echo json_encode(['ok' => true, 'sent' => (bool)$sent]);
