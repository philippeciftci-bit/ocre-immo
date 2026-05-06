<?php
// M/2026/05/06/83.2.1 — Endpoint inscription publique agent.
// Cible : ocre_meta.users (M83.1 superseded, plus de table agents).
// POST JSON {prenom, nom, email, password, siret, agence, ville, cp, carte_pro, tel, whatsapp, sensibility_preset, channels_enabled}
// Retours :
//   201 {ok:true, user_id, redirect}      (insertion neuve)
//   200 {ok:true, user_id, redirect, resent:true}  (idempotence pending_activation -> regen token)
//   422 {ok:false, errors:{champ:msg}}
//   409 {ok:false, error:"Email deja utilise"}     (status='active' ou autre)

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/lib/email_sender.php';
setCorsHeaders();

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    jsonError('Methode non autorisee', 405);
}

$input = getInput();
$errors = [];

function _trim_str($v, $max = 255) {
    if ($v === null) return '';
    $s = trim((string)$v);
    if (strlen($s) > $max) $s = substr($s, 0, $max);
    return $s;
}
function _validate_siret($siret) {
    $cleaned = preg_replace('/\D/', '', (string)$siret);
    if (strlen($cleaned) !== 14) return false;
    $sum = 0;
    for ($i = 0; $i < 14; $i++) {
        $d = (int) $cleaned[$i];
        if ($i % 2 === 0) {
            $d *= 2;
            if ($d > 9) $d -= 9;
        }
        $sum += $d;
    }
    return $sum % 10 === 0;
}
function _send_activation_email(string $email, string $prenom, string $token): bool {
    $url = 'https://signup.ocre.immo/api/activate.php?activation_token=' . $token;
    $subject = 'Bienvenue sur Ocre Immo — Activez votre compte';
    $safePrenom = htmlspecialchars($prenom, ENT_QUOTES, 'UTF-8');
    $html = '<html><body style="font-family:-apple-system,BlinkMacSystemFont,sans-serif;color:#3a2e22;background:#FAF6EC;">'
        . '<div style="max-width:560px;margin:0 auto;padding:32px 24px;background:#fff;border-radius:14px;box-shadow:0 2px 10px rgba(60,40,20,0.08);">'
        . '<h1 style="font-family:\'Cormorant Garamond\',Georgia,serif;font-style:italic;color:#8B5E3C;font-weight:500;margin:0 0 12px;font-size:28px;">Bienvenue sur Ocre Immo</h1>'
        . '<p style="font-size:15px;line-height:1.5;">Bonjour <b>' . $safePrenom . '</b>,</p>'
        . '<p style="font-size:15px;line-height:1.5;">Votre dossier d\'inscription a bien été reçu. Pour activer votre compte et choisir votre mot de passe, cliquez sur le bouton ci-dessous (lien valide 7 jours) :</p>'
        . '<p style="text-align:center;margin:28px 0;"><a href="' . $url . '" style="display:inline-block;padding:14px 32px;background:#2D7A3E;color:#fff;text-decoration:none;border-radius:10px;font-weight:700;font-size:14px;">Activer mon compte</a></p>'
        . '<p style="font-size:12px;color:#999;line-height:1.5;">Si le bouton ne fonctionne pas, copiez-collez ce lien :<br><span style="word-break:break-all;">' . $url . '</span></p>'
        . '<p style="font-size:11px;color:#999;margin-top:32px;border-top:1px solid #eee;padding-top:16px;">Ocre Immo · contact@ocre.immo</p>'
        . '</div></body></html>';
    if (function_exists('ocre_send_email')) {
        return ocre_send_email($email, $subject, $html);
    }
    return false;
}

function _meta_pdo() {
    $dsn = 'mysql:host=' . DB_HOST . ';dbname=ocre_meta;charset=utf8mb4';
    return new PDO($dsn, DB_USER, DB_PASS, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);
}

$prenom = _trim_str($input['prenom'] ?? '', 100);
$nom    = _trim_str($input['nom'] ?? '', 100);
$email  = strtolower(_trim_str($input['email'] ?? '', 190));
$pwd    = (string)($input['password'] ?? '');
$siretRaw = preg_replace('/\D/', '', (string)($input['siret'] ?? ''));
$agence = _trim_str($input['agence'] ?? '', 150);
$ville  = _trim_str($input['ville'] ?? '', 100);
$cp     = _trim_str($input['cp'] ?? '', 10);
$cartePro = _trim_str($input['carte_pro'] ?? '', 50);
$tel    = _trim_str($input['tel'] ?? '', 30);
$whatsapp = _trim_str($input['whatsapp'] ?? '', 30);
$sensibility = (string)($input['sensibility_preset'] ?? 'equilibre');
$channels = is_array($input['channels_enabled'] ?? null) ? $input['channels_enabled'] : ['email' => true, 'whatsapp' => false];

if ($prenom === '') $errors['prenom'] = 'Prenom requis';
if ($nom === '')    $errors['nom']    = 'Nom requis';
if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) $errors['email'] = 'Email invalide';
if (strlen($pwd) < 10) $errors['password'] = 'Mot de passe trop court (min 10)';
elseif (!preg_match('/[A-Z]/', $pwd)) $errors['password'] = 'Mot de passe doit contenir 1 majuscule';
elseif (!preg_match('/[0-9]/', $pwd)) $errors['password'] = 'Mot de passe doit contenir 1 chiffre';
if (!_validate_siret($siretRaw)) $errors['siret'] = 'SIRET invalide (Luhn)';
if ($ville === '') $errors['ville'] = 'Ville requise';
if ($tel === '')   $errors['tel']   = 'Telephone requis';
if (!in_array($sensibility, ['strict','equilibre','large','tres_large'], true)) $sensibility = 'equilibre';

// M86 — validation backend stricte CGU + RGPD (cf audit M85.1, conformite RGPD art.7 + CNIL SAN-2019-001).
$cguAccepted  = filter_var($input['cgu_accepted']  ?? false, FILTER_VALIDATE_BOOLEAN);
$rgpdAccepted = filter_var($input['rgpd_accepted'] ?? false, FILTER_VALIDATE_BOOLEAN);
if (!$cguAccepted)  $errors['cgu_accepted']  = 'Acceptation des CGU obligatoire';
if (!$rgpdAccepted) $errors['rgpd_accepted'] = 'Acceptation du traitement RGPD obligatoire';

if (!empty($errors)) {
    http_response_code(422);
    echo json_encode(['ok' => false, 'errors' => $errors]);
    exit;
}

$siren = substr($siretRaw, 0, 9);
$pwdHash = password_hash($pwd, PASSWORD_BCRYPT, ['cost' => 12]);
$nomUpper = mb_strtoupper($nom, 'UTF-8');
$displayName = trim($prenom . ' ' . $nomUpper);
$prefsJson = json_encode([
    'channels_enabled' => [
        'telegram' => false,
        'email'    => !empty($channels['email']),
        'whatsapp' => !empty($channels['whatsapp']) && $whatsapp !== '',
    ],
], JSON_UNESCAPED_UNICODE);
$ip = $_SERVER['REMOTE_ADDR'] ?? '';
$userAgent = substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 500);
$activationToken = bin2hex(random_bytes(32));
$cguVersion = '1.0';
$rgpdVersion = '1.0';

try {
    $meta = _meta_pdo();

    $chk = $meta->prepare("SELECT id, status FROM users WHERE email = ? AND archived_at IS NULL LIMIT 1");
    $chk->execute([$email]);
    $existing = $chk->fetch();

    if ($existing) {
        if ($existing['status'] === 'pending_activation') {
            $upd = $meta->prepare(
                "UPDATE users
                    SET prenom = ?, nom = ?, display_name = ?,
                        password_hash = ?, telephone = ?, whatsapp = ?,
                        siret = ?, siren = ?, pro_card_number = ?,
                        societe = ?, ville = ?, cp = ?, country_code = 'FR',
                        sensibility_preset = ?, preferences = ?,
                        activation_token = ?, activation_token_expires_at = DATE_ADD(NOW(), INTERVAL 7 DAY),
                        cgu_accepted_at = NOW(), cgu_version = ?, cgu_version_accepted = ?,
                        cgu_accepted_ip = ?, cgu_accepted_user_agent = ?,
                        rgpd_accepted_at = NOW(), rgpd_version = ?, rgpd_accepted_ip = ?, rgpd_accepted_user_agent = ?
                  WHERE id = ?"
            );
            $upd->execute([
                $prenom, $nomUpper, $displayName,
                $pwdHash, $tel, ($whatsapp ?: null),
                $siretRaw, $siren, ($cartePro ?: null),
                ($agence ?: null), $ville, ($cp ?: null),
                $sensibility, $prefsJson,
                $activationToken,
                $cguVersion, $cguVersion, $ip, $userAgent,
                $rgpdVersion, $ip, $userAgent,
                (int)$existing['id'],
            ]);
            $userId = (int)$existing['id'];

            $body = $prenom . ' ' . $nomUpper . ' . ' . $email . ' . token regenere (pending)';
            @shell_exec('/root/bin/notify --project ocre --priority normal --title ' . escapeshellarg('Inscription token regenere') . ' --body ' . escapeshellarg($body) . ' >/dev/null 2>&1 &');

            $emailSent = _send_activation_email($email, $prenom, $activationToken);

            http_response_code(200);
            echo json_encode([
                'ok' => true,
                'user_id' => $userId,
                'resent' => true,
                'email_sent' => $emailSent,
                'redirect' => '/inscription/confirmee/?prenom=' . rawurlencode($prenom) . '&email=' . rawurlencode($email),
            ]);
            exit;
        }
        http_response_code(409);
        echo json_encode(['ok' => false, 'error' => 'Cet email est deja utilise']);
        exit;
    }

    $stmt = $meta->prepare(
        "INSERT INTO users
            (email, password_hash, display_name, prenom, nom,
             role, subscription_status, billing_plan, status,
             telephone, whatsapp, ville, cp, country_code,
             pro_card_number, siret, siren, societe,
             sensibility_preset, preferences,
             activation_token, activation_token_expires_at,
             cgu_accepted_at, cgu_version, cgu_version_accepted,
             cgu_accepted_ip, cgu_accepted_user_agent,
             rgpd_accepted_at, rgpd_version, rgpd_accepted_ip, rgpd_accepted_user_agent,
             telegram_notifs_enabled, email_notifs_enabled,
             created_at)
         VALUES (?, ?, ?, ?, ?,
                 'agent', 'trial', 'decouverte', 'pending_activation',
                 ?, ?, ?, ?, 'FR',
                 ?, ?, ?, ?,
                 ?, ?,
                 ?, DATE_ADD(NOW(), INTERVAL 7 DAY),
                 NOW(), ?, ?,
                 ?, ?,
                 NOW(), ?, ?, ?,
                 0, ?,
                 NOW())"
    );
    $stmt->execute([
        $email, $pwdHash, $displayName, $prenom, $nomUpper,
        $tel, ($whatsapp ?: null), $ville, ($cp ?: null),
        ($cartePro ?: null), $siretRaw, $siren, ($agence ?: null),
        $sensibility, $prefsJson,
        $activationToken,
        $cguVersion, $cguVersion,
        $ip, $userAgent,
        $rgpdVersion, $ip, $userAgent,
        !empty($channels['email']) ? 1 : 0,
    ]);
    $userId = (int) $meta->lastInsertId();
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Erreur serveur', 'detail' => $e->getMessage()]);
    exit;
}

$body = $prenom . ' ' . $nomUpper . ' . ' . $email . ' . ' . $ville . ' . SIRET ' . $siretRaw;
@shell_exec('/root/bin/notify --project ocre --priority normal --title ' . escapeshellarg('Nouvelle inscription agent') . ' --body ' . escapeshellarg($body) . ' >/dev/null 2>&1 &');

$emailSent = _send_activation_email($email, $prenom, $activationToken);

http_response_code(201);
echo json_encode([
    'ok' => true,
    'user_id' => $userId,
    'email_sent' => $emailSent,
    'redirect' => '/inscription/confirmee/?prenom=' . rawurlencode($prenom) . '&email=' . rawurlencode($email),
]);
