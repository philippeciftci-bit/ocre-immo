<?php
// M/2026/05/06/83.2 — Endpoint inscription publique agent.
// POST JSON {prenom, nom, email, password, siret, agence, ville, cp, carte_pro, tel, whatsapp, sensibility_preset, channels_enabled}
// Retours :
//   201 {ok:true, agent_id, redirect}
//   422 {ok:false, errors:{champ:msg}}
//   409 {ok:false, error:"Email deja utilise"}

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

// Verif email pas deja utilise.
$chk = db()->prepare("SELECT id FROM agents WHERE email = ? AND deleted_at IS NULL LIMIT 1");
$chk->execute([$email]);
if ($chk->fetch()) {
    http_response_code(409);
    echo json_encode(['ok' => false, 'error' => 'Cet email est deja utilise']);
    exit;
}

$siren = substr($siretRaw, 0, 9);
$pwdHash = password_hash($pwd, PASSWORD_BCRYPT);
$nomUpper = mb_strtoupper($nom, 'UTF-8');
$channelsJson = json_encode([
    'telegram' => false,
    'email'    => !empty($channels['email']),
    'whatsapp' => !empty($channels['whatsapp']) && $whatsapp !== '',
], JSON_UNESCAPED_UNICODE);

try {
    $stmt = db()->prepare(
        "INSERT INTO agents
            (email, password_hash, prenom, nom, tel, whatsapp,
             siret, siren, carte_pro, agence, ville, cp, pays,
             role, verified_at, channels_enabled, sensibility_preset)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'France',
                 'agent', NULL, ?, ?)"
    );
    $stmt->execute([$email, $pwdHash, $prenom, $nomUpper, $tel, ($whatsapp ?: null),
        $siretRaw, $siren, ($cartePro ?: null), ($agence ?: null), $ville, ($cp ?: null),
        $channelsJson, $sensibility]);
    $agentId = (int) db()->lastInsertId();
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Erreur serveur', 'detail' => $e->getMessage()]);
    exit;
}

// Notif Telegram a Philippe.
$body = $prenom . ' ' . $nomUpper . ' . ' . $email . ' . ' . $ville . ' . SIRET ' . $siretRaw;
@shell_exec('/root/bin/notify --project ocre --priority normal --title ' . escapeshellarg('Nouvelle inscription agent') . ' --body ' . escapeshellarg($body) . ' >/dev/null 2>&1 &');

http_response_code(201);
echo json_encode([
    'ok' => true,
    'agent_id' => $agentId,
    'redirect' => '/inscription/confirmee/?prenom=' . rawurlencode($prenom) . '&email=' . rawurlencode($email),
]);
