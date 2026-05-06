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
$activationToken = bin2hex(random_bytes(32));
$cguVersion = '1.0';

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
                        cgu_accepted_at = NOW(), cgu_version = ?, cgu_version_accepted = ?, cgu_accepted_ip = ?
                  WHERE id = ?"
            );
            $upd->execute([
                $prenom, $nomUpper, $displayName,
                $pwdHash, $tel, ($whatsapp ?: null),
                $siretRaw, $siren, ($cartePro ?: null),
                ($agence ?: null), $ville, ($cp ?: null),
                $sensibility, $prefsJson,
                $activationToken,
                $cguVersion, $cguVersion, $ip,
                (int)$existing['id'],
            ]);
            $userId = (int)$existing['id'];

            $body = $prenom . ' ' . $nomUpper . ' . ' . $email . ' . token regenere (pending)';
            @shell_exec('/root/bin/notify --project ocre --priority normal --title ' . escapeshellarg('Inscription token regenere') . ' --body ' . escapeshellarg($body) . ' >/dev/null 2>&1 &');

            http_response_code(200);
            echo json_encode([
                'ok' => true,
                'user_id' => $userId,
                'resent' => true,
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
             cgu_accepted_at, cgu_version, cgu_version_accepted, cgu_accepted_ip,
             telegram_notifs_enabled, email_notifs_enabled,
             created_at)
         VALUES (?, ?, ?, ?, ?,
                 'agent', 'trial', 'decouverte', 'pending_activation',
                 ?, ?, ?, ?, 'FR',
                 ?, ?, ?, ?,
                 ?, ?,
                 ?, DATE_ADD(NOW(), INTERVAL 7 DAY),
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
        $cguVersion, $cguVersion, $ip,
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

http_response_code(201);
echo json_encode([
    'ok' => true,
    'user_id' => $userId,
    'redirect' => '/inscription/confirmee/?prenom=' . rawurlencode($prenom) . '&email=' . rawurlencode($email),
]);
