<?php
// M/2026/05/14/8-v2 — signup public IDEMPOTENT + TRANSACTIONNEL + TOKEN-VERSIONED.
// POST { email, prenom, nom, societe?, telephone, password, cgu, rgpd }
//
// GARANTIES (audit ChatGPT-5) :
//   - UNIQUE(email) côté SQL (index uniq_email existant) -> doublon impossible.
//   - activation_token_version INT incremente a chaque token (rend l ancien token invalide).
//   - Slug IMMUTABLE : calcule UNE fois a la creation, jamais re-genere.
//   - SELECT FOR UPDATE dans transaction -> race condition double-click protege.
//   - Rollback atomique sur provision fail : DELETE user + DROP DB partielle + notif.
//
// FLOW :
//   1. nouveau email      -> INSERT pending_activation + COMMIT + provision-tenant.sh + mail
//   2. email + active     -> 409 ACCOUNT_EXISTS (front oriente login)
//   3. email + pending    -> regen token + version++ + RESEND mail vers MEME slug (pas d INSERT)

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

// Rate-limit signup configurable + whitelist IP dev.
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

// === Transaction + SELECT FOR UPDATE (anti race condition) ===
$pdo->beginTransaction();
try {
    $st = $pdo->prepare("SELECT id, email, slug, status FROM users WHERE email=? LIMIT 1 FOR UPDATE");
    $st->execute([$email]);
    $existing = $st->fetch(PDO::FETCH_ASSOC);

    if ($existing) {
        $uid = (int)$existing['id'];
        $slug = (string)$existing['slug']; // IMMUTABLE — on garde toujours.
        $status = (string)$existing['status'];

        if ($status === 'active') {
            $pdo->rollBack();
            password_auth_rate_log($pdo, 'signup_public', $email, $ip, false, $ua);
            http_response_code(409);
            echo json_encode(['ok' => false, 'error' => 'ACCOUNT_EXISTS', 'message' => 'Ton compte existe deja. Connecte-toi.']);
            exit;
        }

        if ($status === 'pending_activation') {
            // CAS resend : nouveau token + version++ + reset expires 7j. Slug INCHANGE.
            $newToken = bin2hex(random_bytes(32));
            $newHash = password_auth_hash($password);
            $pdo->prepare(
                "UPDATE users
                 SET activation_token=?, activation_token_version=activation_token_version+1,
                     activation_token_expires_at=DATE_ADD(NOW(), INTERVAL 7 DAY),
                     password_hash=?, password_set_at=NOW(),
                     prenom=?, nom=?, display_name=?, telephone=?, societe=?
                 WHERE id=?"
            )->execute([$newToken, $newHash, $prenom, $nom, trim($prenom . ' ' . $nom), $telephone, $societe, $uid]);
            $pdo->commit();

            password_auth_rate_log($pdo, 'signup_public', $email, $ip, true, $ua);
            @file_put_contents($signupLog, '[' . date('c') . "] signup-public RESEND uid=$uid slug=$slug email=$email ip=$ip\n", FILE_APPEND);

            $activationUrl = "https://auth.ocre.immo/confirm?token={$newToken}";
            $html = ocre_signup_welcome_email_html(
                $prenom,
                $activationUrl,
                'Activer mon compte',
                'Renvoi de ton lien d\'activation',
                'Voici un nouveau lien pour activer ton compte. Le precedent a ete invalide.<br><span style="font-size:13px;color:#6B5642">Lien valide 7 jours.</span>'
            );
            @ocre_send_email($email, 'Renvoi de ton lien d\'activation Ocre Immo', $html);

            echo json_encode([
                'ok' => true,
                'resent' => true,
                'message' => 'Un nouveau mail d\'activation vient d\'etre envoye. Verifie ta boite.',
                'slug' => $slug,
            ]);
            exit;
        }

        $pdo->rollBack();
        http_response_code(409);
        echo json_encode(['ok' => false, 'error' => 'ACCOUNT_LOCKED', 'message' => 'Statut compte incompatible. Contacte le support.']);
        exit;
    }

    // === CAS NOUVEAU EMAIL : INSERT pending puis exec provision HORS transaction ===
    $baseSlug = preg_replace('/[^a-z0-9-]/', '', strtolower(preg_replace('/\s+/', '-', $prenom . '-' . $nom))) ?: 'agent';
    $baseSlug = substr($baseSlug, 0, 30);
    $slug = $baseSlug . '-' . substr(bin2hex(random_bytes(2)), 0, 4);
    $activationToken = bin2hex(random_bytes(32));
    $passwordHash = password_auth_hash($password);

    $ins = $pdo->prepare(
        "INSERT INTO users (email, prenom, nom, display_name, slug, telephone, societe, role, status, activation_token, activation_token_version, activation_token_expires_at, password_hash, password_set_at, cgu_accepted_at, cgu_version_accepted, created_at)
         VALUES (?, ?, ?, ?, ?, ?, ?, 'agent', 'pending_activation', ?, 1, DATE_ADD(NOW(), INTERVAL 7 DAY), ?, NOW(), NOW(), '1.0', NOW())"
    );
    $ins->execute([$email, $prenom, $nom, trim($prenom . ' ' . $nom), $slug, $telephone, $societe ?: null, $activationToken, $passwordHash]);
    $newUid = (int)$pdo->lastInsertId();
    $pdo->commit();
} catch (Throwable $e) {
    if ($pdo->inTransaction()) { $pdo->rollBack(); }
    // Race condition perdue : un autre POST concurrent a INSERT en premier.
    // Codes possibles : 1062 (ER_DUP_ENTRY uniq_email), 1213 (ER_LOCK_DEADLOCK), 40001 (serialization).
    // Strategie : re-SELECT le row vainqueur + UPDATE token + RESEND (semantique idempotente).
    $sqlErr = (int)($e->errorInfo[1] ?? 0);
    if (($e instanceof PDOException) && in_array($sqlErr, [1062, 1213], true)) {
        try {
            $pdo2 = $pdo;
            $pdo2->beginTransaction();
            $st = $pdo2->prepare("SELECT id, slug, status FROM users WHERE email=? FOR UPDATE");
            $st->execute([$email]);
            $row = $st->fetch(PDO::FETCH_ASSOC);
            if ($row && $row['status'] === 'pending_activation') {
                $newToken = bin2hex(random_bytes(32));
                $newHash = password_auth_hash($password);
                $pdo2->prepare(
                    "UPDATE users SET activation_token=?, activation_token_version=activation_token_version+1,
                       activation_token_expires_at=DATE_ADD(NOW(), INTERVAL 7 DAY), password_hash=?, password_set_at=NOW()
                     WHERE id=?"
                )->execute([$newToken, $newHash, (int)$row['id']]);
                $pdo2->commit();

                $activationUrl = "https://auth.ocre.immo/confirm?token={$newToken}";
                $html = ocre_signup_welcome_email_html($prenom, $activationUrl, 'Activer mon compte', 'Bienvenue sur Ocre Immo',
                    'Confirme ton email pour activer ton compte.<br><span style="font-size:13px;color:#6B5642">Lien valide 7 jours.</span>');
                @ocre_send_email($email, 'Confirme ton inscription Ocre Immo', $html);

                @file_put_contents($signupLog, '[' . date('c') . "] signup-public RACE-LOSER resent uid={$row['id']} slug={$row['slug']} email=$email\n", FILE_APPEND);
                echo json_encode(['ok' => true, 'resent' => true, 'message' => 'Mail d\'activation envoye. Verifie ta boite.', 'slug' => $row['slug']]);
                exit;
            }
            $pdo2->rollBack();
        } catch (Throwable $e2) { if ($pdo->inTransaction()) { $pdo->rollBack(); } }
    }
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'INSERT user fail: ' . substr($e->getMessage(), 0, 120)]);
    exit;
}

password_auth_rate_log($pdo, 'signup_public', $email, $ip, true, $ua);

// === HORS transaction : provision-tenant.sh (peut etre long). Rollback compensatoire si KO.
$cmd = sprintf('sudo /opt/ocre-app/scripts/provision-tenant.sh %s %d 2>&1', escapeshellarg($slug), $newUid);
$provisionOut = []; $provisionRc = 0;
@exec($cmd, $provisionOut, $provisionRc);
@file_put_contents($signupLog, '[' . date('c') . "] signup-public NEW slug=$slug uid=$newUid rc=$provisionRc\n" . implode("\n", $provisionOut) . "\n\n", FILE_APPEND);

if ($provisionRc !== 0) {
    // ROLLBACK COMPENSATOIRE : DROP DB partielle + DELETE meta + notif Telegram.
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

$activationUrl = "https://auth.ocre.immo/confirm?token={$activationToken}";
$html = ocre_signup_welcome_email_html(
    $prenom,
    $activationUrl,
    'Activer mon compte',
    'Bienvenue sur Ocre Immo',
    'Confirme ton email pour activer ton compte et acceder a ton espace Oi Agent.<br><span style="font-size:13px;color:#6B5642">Lien valide 7 jours.</span>'
);
@ocre_send_email($email, 'Confirme ton inscription Ocre Immo', $html);

echo json_encode([
    'ok' => true,
    'message' => 'Verifie ta boite mail pour activer ton compte.',
    'slug' => $slug,
]);
