<?php
// M/2026/05/14/8 — signup public IDEMPOTENT + ATOMIQUE (refonte M/14/75).
// POST { email, prenom, nom, societe?, telephone, password, cgu, rgpd }
//
// CAS GERES (anti-orphelins) :
//   1. email inconnu   -> INSERT pending + provision-tenant.sh + mail confirmation
//   2. email existe + status='active'                 -> 409 ACCOUNT_EXISTS (front oriente login)
//   3. email existe + status='pending_activation'     -> regenere activation_token,
//                                                         RESEND mail vers MEME slug,
//                                                         pas de nouveau INSERT, pas de provision
//
// Sur echec provision-tenant.sh : ROLLBACK DELETE user + DROP DATABASE partielle +
// notif Telegram Philippe + 500 PROVISION_FAILED. Aucun user laisse a moitie.

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
$prenom = trim((string)($input['prenom'] ?? ''));
$nom = trim((string)($input['nom'] ?? ''));
$societe = trim((string)($input['societe'] ?? ''));
$telephone = preg_replace('/[^\d+]/', '', (string)($input['telephone'] ?? ''));
$password = (string)($input['password'] ?? '');
$cgu = !empty($input['cgu']);
$rgpd = !empty($input['rgpd']);
$ip = explode(',', $_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['REMOTE_ADDR'] ?? '?')[0];
$ua = $_SERVER['HTTP_USER_AGENT'] ?? '?';

if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    http_response_code(400); echo json_encode(['ok' => false, 'error' => 'Email invalide']); exit;
}
if ($prenom === '' || $nom === '' || $telephone === '') {
    http_response_code(400); echo json_encode(['ok' => false, 'error' => 'Champs obligatoires manquants']); exit;
}
if (!$cgu || !$rgpd) {
    http_response_code(400); echo json_encode(['ok' => false, 'error' => 'CGU et RGPD requis']); exit;
}
$strengthErr = password_auth_validate_strength($password);
if ($strengthErr !== null) {
    http_response_code(400); echo json_encode(['ok' => false, 'error' => $strengthErr]); exit;
}

$pdo = new PDO('mysql:host=' . DB_HOST . ';dbname=ocre_meta;charset=utf8mb4', DB_USER, DB_PASS, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
password_auth_rate_limit_init($pdo);

// Rate-limit signup configurable + whitelist IP dev (M/15/5).
$rateMax = defined('RATE_LIMIT_SIGNUP_MAX') ? RATE_LIMIT_SIGNUP_MAX : 5;
$rateWin = defined('RATE_LIMIT_SIGNUP_WINDOW_SEC') ? RATE_LIMIT_SIGNUP_WINDOW_SEC : 600;
$whitelist = defined('RATE_LIMIT_WHITELIST') ? RATE_LIMIT_WHITELIST : [];
if (!in_array($ip, $whitelist, true)) {
    $st = $pdo->prepare("SELECT COUNT(*) FROM auth_attempts WHERE scope='signup_public' AND ip=? AND ts > NOW() - INTERVAL ? SECOND");
    $st->execute([$ip, $rateWin]);
    if ((int)$st->fetchColumn() >= $rateMax) {
        http_response_code(429);
        echo json_encode(['ok' => false, 'error' => 'Trop d\'inscriptions depuis ton reseau, attends ' . (int)($rateWin/60) . ' minutes']);
        exit;
    }
}

$signupLog = '/var/log/ocre-signup.log';

// CAS 2 + 3 : email deja en base ?
$st = $pdo->prepare("SELECT id, email, slug, status FROM users WHERE email=? LIMIT 1");
$st->execute([$email]);
$existing = $st->fetch(PDO::FETCH_ASSOC);

if ($existing) {
    $uid = (int)$existing['id'];
    $slug = (string)$existing['slug'];
    $status = (string)$existing['status'];

    if ($status === 'active') {
        // CAS 2 : compte deja actif -> oriente login.
        password_auth_rate_log($pdo, 'signup_public', $email, $ip, false, $ua);
        http_response_code(409);
        echo json_encode([
            'ok' => false,
            'error' => 'ACCOUNT_EXISTS',
            'message' => 'Ton compte existe deja. Connecte-toi.'
        ]);
        exit;
    }

    if ($status === 'pending_activation') {
        // CAS 3 : retry signup pending -> regenere token, resend MEME slug.
        // Update password_hash aussi (user a peut-etre saisi un nouveau MDP).
        $newToken = bin2hex(random_bytes(32));
        $newHash = password_auth_hash($password);
        try {
            $pdo->prepare("UPDATE users SET activation_token=?, activation_token_expires_at=DATE_ADD(NOW(), INTERVAL 24 HOUR), password_hash=?, password_set_at=NOW(), prenom=?, nom=?, display_name=?, telephone=?, societe=? WHERE id=?")
                ->execute([$newToken, $newHash, $prenom, $nom, trim($prenom . ' ' . $nom), $telephone, $societe, $uid]);
        } catch (Throwable $e) {
            http_response_code(500);
            echo json_encode(['ok' => false, 'error' => 'UPDATE pending fail: ' . substr($e->getMessage(), 0, 120)]);
            exit;
        }
        password_auth_rate_log($pdo, 'signup_public', $email, $ip, true, $ua);
        @file_put_contents($signupLog, '[' . date('c') . "] signup-public RESEND uid=$uid slug=$slug email=$email ip=$ip\n", FILE_APPEND);

        $activationUrl = "https://auth.ocre.immo/confirm-signup.html?token={$newToken}";
        $subject = 'Renvoi de ton lien d\'activation Ocre Immo';
        $html = ocre_signup_welcome_email_html(
            $prenom,
            $activationUrl,
            'Activer mon compte',
            'Renvoi de ton lien d\'activation',
            'Voici un nouveau lien pour activer ton compte. Le precedent a ete invalide.<br><span style="font-size:13px;color:#6B5642">Lien valide 24 heures.</span>'
        );
        @ocre_send_email($email, $subject, $html);

        echo json_encode([
            'ok' => true,
            'resent' => true,
            'message' => 'Un nouveau mail d\'activation vient d\'etre envoye. Verifie ta boite.',
            'slug' => $slug,
        ]);
        exit;
    }

    // Tout autre status (suspended, deleted...) -> on bloque par securite.
    http_response_code(409);
    echo json_encode(['ok' => false, 'error' => 'ACCOUNT_LOCKED', 'message' => 'Statut compte incompatible. Contacte le support.']);
    exit;
}

// CAS 1 : nouveau compte.
$baseSlug = preg_replace('/[^a-z0-9-]/', '', strtolower(preg_replace('/\s+/', '-', $prenom . '-' . $nom))) ?: 'agent';
$baseSlug = substr($baseSlug, 0, 30);
$slug = $baseSlug . '-' . substr(bin2hex(random_bytes(2)), 0, 4);
$activationToken = bin2hex(random_bytes(32));
$passwordHash = password_auth_hash($password);

try {
    $ins = $pdo->prepare(
        "INSERT INTO users (email, prenom, nom, display_name, slug, telephone, role, status, activation_token, activation_token_expires_at, password_hash, password_set_at, cgu_accepted_at, cgu_version_accepted, created_at)
         VALUES (?, ?, ?, ?, ?, ?, 'agent', 'pending_activation', ?, DATE_ADD(NOW(), INTERVAL 24 HOUR), ?, NOW(), NOW(), '1.0', NOW())"
    );
    $ins->execute([$email, $prenom, $nom, trim($prenom . ' ' . $nom), $slug, $telephone, $activationToken, $passwordHash]);
    $newUid = (int)$pdo->lastInsertId();
    if ($societe !== '') {
        $pdo->prepare("UPDATE users SET societe=? WHERE id=?")->execute([$societe, $newUid]);
    }
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'INSERT user fail: ' . substr($e->getMessage(), 0, 120)]);
    exit;
}

password_auth_rate_log($pdo, 'signup_public', $email, $ip, true, $ua);

// Provision tenant SYNC (rollback total si rc!=0)
$cmd = sprintf('sudo /opt/ocre-app/scripts/provision-tenant.sh %s %d 2>&1', escapeshellarg($slug), $newUid);
$provisionOut = []; $provisionRc = 0;
@exec($cmd, $provisionOut, $provisionRc);
@file_put_contents($signupLog, '[' . date('c') . "] signup-public NEW slug=$slug uid=$newUid rc=$provisionRc\n" . implode("\n", $provisionOut) . "\n\n", FILE_APPEND);

if ($provisionRc !== 0) {
    // ROLLBACK ATOMIQUE : DROP DB partielle + DELETE meta + notif Philippe.
    try {
        $dbName = 'ocre_wsp_' . $slug;
        $rootPwd = trim(@file_get_contents('/root/.secrets/mysql-root.pwd') ?: '');
        if ($rootPwd !== '') {
            $safeDb = preg_replace('/[^a-z0-9_-]/i', '', $dbName);
            $dropCmd = sprintf('mysql -uroot -p%s -e %s 2>&1',
                escapeshellarg($rootPwd),
                escapeshellarg("DROP DATABASE IF EXISTS `{$safeDb}`")
            );
            @exec($dropCmd);
        }
    } catch (Throwable $e) {}
    try {
        $pdo->prepare("DELETE wm FROM workspace_members wm JOIN workspaces w ON wm.workspace_id = w.id WHERE w.slug = ?")->execute([$slug]);
        $pdo->prepare("DELETE FROM workspaces WHERE slug = ?")->execute([$slug]);
        $pdo->prepare("DELETE FROM users WHERE id = ?")->execute([$newUid]);
    } catch (Throwable $e) {}

    // Notif Philippe (best-effort, ne pas bloquer la reponse user).
    $notifBody = "Provision failed slug=$slug uid=$newUid rc=$provisionRc tail=" . implode(' | ', array_slice($provisionOut, -3));
    @exec(sprintf('/root/bin/notify --project ocre --priority high --title %s --body %s 2>/dev/null',
        escapeshellarg('PROVISION_FAILED signup public'),
        escapeshellarg(substr($notifBody, 0, 500))
    ));

    http_response_code(500);
    echo json_encode([
        'ok' => false,
        'error' => 'PROVISION_FAILED',
        'message' => 'Impossible de creer ton espace pour le moment. Reessaie dans 1 minute.',
    ]);
    exit;
}

// Email confirmation (lien magic-link 24h)
$activationUrl = "https://auth.ocre.immo/confirm-signup.html?token={$activationToken}";
$subject = 'Confirme ton inscription Ocre Immo';
$html = ocre_signup_welcome_email_html(
    $prenom,
    $activationUrl,
    'Activer mon compte',
    'Bienvenue sur Ocre Immo',
    'Confirme ton email pour activer ton compte et acceder a ton espace Oi Agent.<br><span style="font-size:13px;color:#6B5642">Lien valide 24 heures.</span>'
);
@ocre_send_email($email, $subject, $html);

echo json_encode([
    'ok' => true,
    'message' => 'Verifie ta boite mail pour activer ton compte.',
    'slug' => $slug,
]);
