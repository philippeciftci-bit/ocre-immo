<?php
// M/2026/05/13/17 — 2FA TOTP : enroll + verify + disable. 3 actions sur 1 endpoint pour simplifier.
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/lib/totp.php';
setCorsHeaders();

$user = requireAuth();
$uid = (int)$user['id'];

$meta = new PDO('mysql:host=' . DB_HOST . ';dbname=ocre_meta;charset=utf8mb4', DB_USER, DB_PASS, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
]);

$action = $_GET['action'] ?? '';
$input = getInput();

if ($action === 'status') {
    $st = $meta->prepare("SELECT totp_enabled FROM users WHERE id = ?");
    $st->execute([$uid]);
    $r = $st->fetch();
    jsonOk(['enabled' => !empty($r['totp_enabled']) && (int)$r['totp_enabled'] === 1]);
}

if ($action === 'enroll') {
    // Generate secret + INSERT (totp_enabled reste 0 jusqu a verify confirmation).
    $secret = totp_random_secret(20);
    $meta->prepare("UPDATE users SET totp_secret = ?, totp_enabled = 0, totp_backup_codes = NULL, totp_last_used_window = NULL WHERE id = ?")
        ->execute([$secret, $uid]);
    $email = $user['email'] ?? ('user-' . $uid);
    $otpauth = totp_otpauth_url($secret, $email, 'Oi Agent');
    jsonOk(['secret_text' => $secret, 'otpauth_url' => $otpauth]);
}

if ($action === 'verify') {
    $code = preg_replace('/\s+/', '', (string)($input['code'] ?? ''));
    if (!preg_match('/^\d{6}$/', $code)) jsonError('Code invalide (6 chiffres requis)', 400);

    $st = $meta->prepare("SELECT totp_secret, totp_last_used_window, totp_enabled FROM users WHERE id = ?");
    $st->execute([$uid]);
    $r = $st->fetch();
    if (!$r || empty($r['totp_secret'])) jsonError('Pas de secret 2FA en attente. Relance enroll.', 400);

    $window = totp_verify($r['totp_secret'], $code, $r['totp_last_used_window'] ? (int)$r['totp_last_used_window'] : null);
    if ($window === false) jsonError('Code invalide', 401);

    // Activation + generation backup codes (10 codes UUID v4 short hashes bcrypt).
    $backupPlain = [];
    $backupHashed = [];
    for ($i = 0; $i < 10; $i++) {
        $c = strtoupper(bin2hex(random_bytes(4))); // 8 hex chars
        $backupPlain[] = $c;
        $backupHashed[] = password_hash($c, PASSWORD_BCRYPT, ['cost' => 12]);
    }
    $meta->prepare("UPDATE users SET totp_enabled = 1, totp_backup_codes = ?, totp_last_used_window = ? WHERE id = ?")
        ->execute([json_encode($backupHashed), $window, $uid]);
    jsonOk(['enabled' => true, 'backup_codes' => $backupPlain]);
}

if ($action === 'disable') {
    // Confirmation par mot de passe.
    $password = (string)($input['password'] ?? '');
    if (!$password) jsonError('password requis', 400);
    $st = $meta->prepare("SELECT password_hash FROM users WHERE id = ?");
    $st->execute([$uid]);
    $r = $st->fetch();
    if (!$r || !$r['password_hash'] || !password_verify($password, $r['password_hash'])) {
        // Si pas de password_hash (magic-link only), accepte sans password verification.
        if ($r && !$r['password_hash']) {
            // Magic-link only : pas de password donc accept silencieux apres re-auth session.
        } else {
            jsonError('Mot de passe incorrect', 401);
        }
    }
    $meta->prepare("UPDATE users SET totp_enabled = 0, totp_secret = NULL, totp_backup_codes = NULL, totp_last_used_window = NULL WHERE id = ?")
        ->execute([$uid]);
    jsonOk(['enabled' => false]);
}

if ($action === 'verify_backup') {
    $code = strtoupper(preg_replace('/\s+/', '', (string)($input['code'] ?? '')));
    if (!preg_match('/^[A-F0-9]{8}$/', $code)) jsonError('Code backup invalide (8 hex)', 400);
    $st = $meta->prepare("SELECT totp_backup_codes FROM users WHERE id = ?");
    $st->execute([$uid]);
    $r = $st->fetch();
    $codes = ($r && $r['totp_backup_codes']) ? json_decode($r['totp_backup_codes'], true) : [];
    if (!is_array($codes)) $codes = [];
    foreach ($codes as $idx => $h) {
        if (password_verify($code, $h)) {
            // Consomme : remove de la liste.
            array_splice($codes, $idx, 1);
            $meta->prepare("UPDATE users SET totp_backup_codes = ? WHERE id = ?")
                ->execute([json_encode($codes), $uid]);
            jsonOk(['ok' => true, 'remaining_backup_codes' => count($codes)]);
        }
    }
    jsonError('Code backup invalide ou deja utilise', 401);
}

jsonError('Action inconnue (status|enroll|verify|disable|verify_backup)', 404);
