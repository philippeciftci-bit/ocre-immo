<?php
// M/2026/05/14/75 — signup public lambda (depuis vitrine / popup ocre.immo).
// POST { email, prenom, nom, societe?, telephone, password, cgu, rgpd }
// 1. Valide tous champs + mot de passe (Argon2id check_strength + top10k)
// 2. Crée user status='pending_activation' + hash password
// 3. Genere magic-link 24h pour confirmation email
// 4. Envoie email canonical ocre_signup_welcome_email_html (M/14/65)
// 5. Provision tenant async (sudo provision-tenant.sh par signup.php interne ?)
// NOTE : distinct du M/14/62 signup.php superadmin-side. Celui-ci public-facing.

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

// Validations
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

// Rate-limit : 3 signups/heure/IP
$st = $pdo->prepare("SELECT COUNT(*) FROM auth_attempts WHERE scope='signup_public' AND ip=? AND ts > NOW() - INTERVAL 1 HOUR");
$st->execute([$ip]);
if ((int)$st->fetchColumn() >= 3) {
    http_response_code(429);
    echo json_encode(['ok' => false, 'error' => 'Trop d\'inscriptions depuis ton reseau, attends 1 heure']);
    exit;
}

// Verifier email pas deja existant (sinon orienter login)
$st = $pdo->prepare("SELECT id FROM users WHERE email=? LIMIT 1");
$st->execute([$email]);
if ($st->fetch()) {
    http_response_code(409);
    echo json_encode(['ok' => false, 'error' => 'Cet email a deja un compte. Connecte-toi.']);
    exit;
}

// Genere slug + activation token
$baseSlug = preg_replace('/[^a-z0-9-]/', '', strtolower(preg_replace('/\s+/', '-', $prenom . '-' . $nom))) ?: 'agent';
$baseSlug = substr($baseSlug, 0, 30);
$slug = $baseSlug . '-' . substr(bin2hex(random_bytes(2)), 0, 4);
$activationToken = bin2hex(random_bytes(32));
$passwordHash = password_auth_hash($password);

// INSERT user pending
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

// Provision tenant (sudo sync, capture exit code, rollback meta si fail)
$provisionLog = '/var/log/ocre-signup.log';
$cmd = sprintf('sudo /opt/ocre-app/scripts/provision-tenant.sh %s %d 2>&1', escapeshellarg($slug), $newUid);
$provisionOut = []; $provisionRc = 0;
@exec($cmd, $provisionOut, $provisionRc);
@file_put_contents($provisionLog, '[' . date('c') . "] signup-public slug=$slug uid=$newUid rc=$provisionRc\n" . implode("\n", $provisionOut) . "\n\n", FILE_APPEND);
if ($provisionRc !== 0) {
    // Rollback meta
    try {
        $pdo->prepare("DELETE wm FROM workspace_members wm JOIN workspaces w ON wm.workspace_id = w.id WHERE w.slug = ?")->execute([$slug]);
        $pdo->prepare("DELETE FROM workspaces WHERE slug = ?")->execute([$slug]);
        $pdo->prepare("DELETE FROM users WHERE id = ?")->execute([$newUid]);
    } catch (Throwable $e) {}
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Provisioning workspace failed', 'rc' => $provisionRc, 'tail' => array_slice($provisionOut, -3)]);
    exit;
}

// Email canonical confirmation (lien magic-link 24h pour confirmer email + activer)
// M/2026/05/15/3 + M/2026/05/15/4 — confirm-signup sur auth.ocre.immo (same-origin /api/auth/).
// Workspace subdomain wildcard n a pas /api/auth/* (reserve auth-ocre vhost). Page activation
// donc servie depuis auth.ocre.immo. Cookie .ocre.immo (M/14/66) marche cross-subdomain.
// Redirect final vers workspace dans confirm_signup.php response.
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
