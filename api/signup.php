<?php
// M/2026/04/29/1 — Signup public + provisioning auto. Hosted sur app.ocre.immo.
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/lib/email_sender.php';
header('Content-Type: application/json; charset=utf-8');

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
if ($method !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'POST required']);
    exit;
}
$body = json_decode(file_get_contents('php://input'), true) ?: $_POST;

function ensureSignupSchema(): PDO {
    static $meta = null;
    if ($meta) return $meta;
    $meta = new PDO('mysql:host=' . DB_HOST . ';dbname=ocre_meta;charset=utf8mb4', DB_USER, DB_PASS, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
    $cols = $meta->query("SHOW COLUMNS FROM users")->fetchAll(PDO::FETCH_COLUMN);
    $alters = [
        'telephone' => "ALTER TABLE users ADD COLUMN telephone VARCHAR(50) NULL",
        'societe' => "ALTER TABLE users ADD COLUMN societe VARCHAR(255) NULL",
        'billing_plan' => "ALTER TABLE users ADD COLUMN billing_plan ENUM('decouverte','pro','equipe') NOT NULL DEFAULT 'decouverte'",
        'status' => "ALTER TABLE users ADD COLUMN status ENUM('pending_activation','active','suspended','deleted') NOT NULL DEFAULT 'active'",
        'activation_token' => "ALTER TABLE users ADD COLUMN activation_token VARCHAR(64) NULL",
        'activation_token_expires_at' => "ALTER TABLE users ADD COLUMN activation_token_expires_at DATETIME NULL",
        'slug' => "ALTER TABLE users ADD COLUMN slug VARCHAR(50) NULL",
        'cgu_accepted_at' => "ALTER TABLE users ADD COLUMN cgu_accepted_at DATETIME NULL",
        'cgu_version_accepted' => "ALTER TABLE users ADD COLUMN cgu_version_accepted VARCHAR(10) NULL",
        'deletion_requested_at' => "ALTER TABLE users ADD COLUMN deletion_requested_at DATETIME NULL",
        'anonymized_at' => "ALTER TABLE users ADD COLUMN anonymized_at DATETIME NULL",
    ];
    foreach ($alters as $col => $sql) {
        if (!in_array($col, $cols, true)) {
            try { $meta->exec($sql); } catch (Throwable $e) {}
        }
    }
    try { $meta->exec("CREATE INDEX IF NOT EXISTS idx_activation_token ON users (activation_token)"); } catch (Throwable $e) {}
    try {
        $meta->exec("CREATE TABLE IF NOT EXISTS signup_rate_limit (
            id BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
            ip VARCHAR(64) NOT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_ip_created (ip, created_at)
        ) CHARACTER SET utf8mb4");
    } catch (Throwable $e) {}
    return $meta;
}

$meta = ensureSignupSchema();

$prenom = trim($body['prenom'] ?? '');
$nom = trim($body['nom'] ?? '');
$email = trim(strtolower($body['email'] ?? ''));
$telephone = trim($body['telephone'] ?? '');
$societe = trim($body['societe'] ?? '');
$slug = strtolower(trim($body['slug'] ?? ''));
$plan = $body['plan'] ?? 'decouverte';
$cgu = !empty($body['cgu']);
$rgpd = !empty($body['rgpd']);

if (!$prenom || !$nom) { http_response_code(400); echo json_encode(['ok' => false, 'error' => 'prenom + nom requis']); exit; }
if (!filter_var($email, FILTER_VALIDATE_EMAIL)) { http_response_code(400); echo json_encode(['ok' => false, 'error' => 'email invalide']); exit; }
if (!preg_match('/^[\d\+\-\s\.]{6,30}$/', $telephone)) { http_response_code(400); echo json_encode(['ok' => false, 'error' => 'telephone invalide']); exit; }
if (!preg_match('/^[a-z0-9-]{3,30}$/', $slug)) { http_response_code(400); echo json_encode(['ok' => false, 'error' => 'slug invalide (a-z 0-9 -, 3-30 chars)']); exit; }
if (!in_array($plan, ['decouverte', 'pro', 'equipe'], true)) { http_response_code(400); echo json_encode(['ok' => false, 'error' => 'plan invalide']); exit; }
if (!$cgu || !$rgpd) { http_response_code(400); echo json_encode(['ok' => false, 'error' => 'CGU + RGPD requis']); exit; }

// Rate limit : 3 par IP par 24h.
$ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
$rt = $meta->prepare("SELECT COUNT(*) FROM signup_rate_limit WHERE ip = ? AND created_at > NOW() - INTERVAL 24 HOUR");
$rt->execute([$ip]);
if ((int) $rt->fetchColumn() >= 3) {
    http_response_code(429);
    echo json_encode(['ok' => false, 'error' => 'rate limit (3 max / 24h)']);
    exit;
}

// Unicité
$st = $meta->prepare("SELECT COUNT(*) FROM users WHERE email = ?");
$st->execute([$email]);
if ((int) $st->fetchColumn() > 0) { http_response_code(409); echo json_encode(['ok' => false, 'error' => 'email déjà pris']); exit; }
$st = $meta->prepare("SELECT COUNT(*) FROM users WHERE slug = ?");
$st->execute([$slug]);
if ((int) $st->fetchColumn() > 0) { http_response_code(409); echo json_encode(['ok' => false, 'error' => 'slug déjà pris']); exit; }

$activationToken = bin2hex(random_bytes(32));
$displayName = trim($prenom . ' ' . $nom);
$pwHash = password_hash(bin2hex(random_bytes(16)), PASSWORD_BCRYPT);

try {
    $ins = $meta->prepare(
        "INSERT INTO users (email, display_name, slug, telephone, societe, billing_plan, status, activation_token, activation_token_expires_at, password_hash, role, cgu_accepted_at, cgu_version_accepted, created_at)
         VALUES (?, ?, ?, ?, ?, ?, 'pending_activation', ?, DATE_ADD(NOW(), INTERVAL 7 DAY), ?, 'agent', NOW(), '1.0', NOW())"
    );
    $ins->execute([$email, $displayName, $slug, $telephone, $societe ?: null, $plan, $activationToken, $pwHash]);
    $newUid = (int) $meta->lastInsertId();
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'INSERT user fail: ' . substr($e->getMessage(), 0, 200)]);
    exit;
}

$meta->prepare("INSERT INTO signup_rate_limit (ip) VALUES (?)")->execute([$ip]);

// M/2026/05/14/62 — Provisioning tenant SYNCHRONE avec capture exit code + rollback meta.
// AVANT : nohup background -> exit code perdu -> si provision-tenant.sh exit 5/6 (M/14/61
// fail-strict DROP DB), user meta cree sans DB tenant -> sub-domain affiche SCHEMA_DRIFT
// au login. Cause racine bug sciage44-1ad8 (M/14/62). Pattern Codex : sync wait + rollback.
$provisionLog = '/var/log/ocre-signup.log';
$cmd = sprintf(
    '/root/workspace/ocre-immo/scripts/provision-tenant.sh %s %d 2>&1',
    escapeshellarg($slug),
    $newUid
);
$provisionOut = [];
$provisionRc = 0;
@exec($cmd, $provisionOut, $provisionRc);
@file_put_contents(
    $provisionLog,
    '[' . date('c') . "] slug=$slug uid=$newUid rc=$provisionRc\n" . implode("\n", $provisionOut) . "\n\n",
    FILE_APPEND
);
if ($provisionRc !== 0) {
    // Rollback meta : retire user + workspace + members crees a L95-107 + L107.
    try {
        $meta->prepare("DELETE wm FROM workspace_members wm JOIN workspaces w ON wm.workspace_id = w.id WHERE w.slug = ?")->execute([$slug]);
        $meta->prepare("DELETE FROM workspaces WHERE slug = ?")->execute([$slug]);
        $meta->prepare("DELETE FROM users WHERE id = ?")->execute([$newUid]);
    } catch (Throwable $e) { /* swallow rollback errors */ }
    http_response_code(500);
    echo json_encode([
        'ok' => false,
        'error' => 'Tenant provisioning failed, signup annule',
        'rc' => $provisionRc,
        'tail' => array_slice($provisionOut, -5),
    ]);
    exit;
}

// Email d'activation
$activationUrl = "https://{$slug}.ocre.immo/login?activation_token={$activationToken}";
$subject = 'Bienvenue sur Ocre Immo — Activez votre compte';
$html = "<html><body style=\"font-family:-apple-system,sans-serif;color:#3a2e22;\">"
    . "<div style=\"max-width:600px;margin:0 auto;padding:24px;\">"
    . "<h1 style=\"font-family:'Cormorant Garamond',Georgia,serif;color:#8B6F47;\">Bienvenue sur Ocre Immo</h1>"
    . "<p>Bonjour " . htmlspecialchars($prenom, ENT_QUOTES) . ",</p>"
    . "<p>Votre compte est créé. Cliquez sur le bouton pour définir votre mot de passe et commencer.</p>"
    . "<a href=\"{$activationUrl}\" style=\"display:inline-block;padding:14px 28px;background:#8B6F47;color:#fff;text-decoration:none;border-radius:4px;font-weight:bold;\">Activer mon compte</a>"
    . "<p style=\"font-size:12px;color:#999;\">Ce lien est valide 7 jours. Si le bouton ne fonctionne pas :<br>{$activationUrl}</p>"
    . "<p style=\"font-size:11px;color:#999;margin-top:24px;\">Ocre Immo — philippe.ciftci@gmail.com</p>"
    . "</div></body></html>";
$emailSent = ocre_send_email($email, $subject, $html);

echo json_encode([
    'ok' => true,
    'user_id' => $newUid,
    'slug' => $slug,
    'email_sent' => $emailSent,
    'next_step' => 'check_email',
    'tenant_url' => "https://{$slug}.ocre.immo/",
]);
