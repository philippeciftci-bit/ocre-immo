<?php
// M/2026/05/13/25 — SSO M118 login : valide email+pwd contre ocre_meta.users,
// peuple user_tenants (lazy migration), emet cookie SSO HMAC + insere sso_sessions.
// Rate-limit : 5 tentatives / minute / IP (table login_attempts).
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/sso_lib.php';
setCorsHeaders();
header('Content-Type: application/json; charset=utf-8');

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'method_not_allowed']);
    exit;
}

$meta = new PDO('mysql:host=' . DB_HOST . ';dbname=ocre_meta;charset=utf8mb4', DB_USER, DB_PASS, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
]);

$ip = $_SERVER['REMOTE_ADDR'] ?? '';
$ua = substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 500);

// Rate-limit : 5 tentatives / minute par IP.
try {
    $rl = $meta->prepare("SELECT COUNT(*) c FROM login_attempts WHERE ip_address = ? AND attempted_at > DATE_SUB(NOW(), INTERVAL 1 MINUTE)");
    $rl->execute([$ip]);
    if ((int)($rl->fetch()['c'] ?? 0) >= 5) {
        http_response_code(429);
        echo json_encode(['ok' => false, 'error' => 'rate_limited', 'retry_after_sec' => 60]);
        exit;
    }
} catch (Throwable $e) { /* table missing : ignore, sera cree par migration */ }

$input = json_decode(file_get_contents('php://input'), true) ?: [];
$email = strtolower(trim((string)($input['email'] ?? '')));
$pwd = (string)($input['password'] ?? '');
$returnTo = (string)($input['return_to'] ?? '');

function record_attempt($meta, $ip, $email, $success, $reason = null) {
    try {
        $st = $meta->prepare("INSERT INTO login_attempts (ip_address, email, success, reason) VALUES (?,?,?,?)");
        $st->execute([$ip, $email, $success ? 1 : 0, $reason]);
    } catch (Throwable $e) {}
}

if (!filter_var($email, FILTER_VALIDATE_EMAIL) || !$pwd) {
    record_attempt($meta, $ip, $email, false, 'invalid_input');
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'email_password_required']);
    exit;
}

$st = $meta->prepare("SELECT id, email, password_hash, status, prenom, nom, slug, archived_at, anonymized_at FROM users WHERE email = ? LIMIT 1");
$st->execute([$email]);
$u = $st->fetch();

if (!$u || !empty($u['archived_at']) || !empty($u['anonymized_at']) || !password_verify($pwd, (string)$u['password_hash'])) {
    record_attempt($meta, $ip, $email, false, 'invalid_credentials');
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'invalid_credentials']);
    exit;
}

if (($u['status'] ?? '') === 'pending_activation') {
    record_attempt($meta, $ip, $email, false, 'pending_activation');
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'pending_activation', 'code' => 'PENDING_ACTIVATION']);
    exit;
}
if (($u['status'] ?? '') === 'suspended') {
    record_attempt($meta, $ip, $email, false, 'suspended');
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'suspended']);
    exit;
}

$uid = (int)$u['id'];

// Lazy migration : peuple user_tenants si vide pour cet user.
// Source : users.slug (propre tenant) + workspace_members JOIN workspaces.
$existsSt = $meta->prepare("SELECT COUNT(*) c FROM user_tenants WHERE user_id = ?");
$existsSt->execute([$uid]);
$alreadyPopulated = (int)($existsSt->fetch()['c'] ?? 0) > 0;

if (!$alreadyPopulated) {
    $ins = $meta->prepare("INSERT IGNORE INTO user_tenants (user_id, tenant_slug, role) VALUES (?,?,?)");
    if (!empty($u['slug'])) {
        $ins->execute([$uid, (string)$u['slug'], 'owner']);
    }
    try {
        $wsSt = $meta->prepare(
            "SELECT w.slug, COALESCE(m.role,'agent') role
             FROM workspace_members m JOIN workspaces w ON w.id = m.workspace_id
             WHERE m.user_id = ? AND m.left_at IS NULL AND w.archived_at IS NULL"
        );
        $wsSt->execute([$uid]);
        foreach ($wsSt->fetchAll() as $w) {
            $role = in_array($w['role'], ['owner','agent','invite'], true) ? $w['role'] : 'agent';
            $ins->execute([$uid, (string)$w['slug'], $role]);
        }
    } catch (Throwable $e) { /* workspaces optionnel */ }
}

// Liste tenants accessibles (apres lazy migration).
$tSt = $meta->prepare("SELECT tenant_slug FROM user_tenants WHERE user_id = ? ORDER BY tenant_slug");
$tSt->execute([$uid]);
$tenants = array_column($tSt->fetchAll(), 'tenant_slug');
$currentTenant = $tenants[0] ?? ($u['slug'] ?? null);

// Cree session SSO cote serveur (token random 32 bytes).
$sessionToken = bin2hex(random_bytes(32));
$ttlSec = 7 * 86400;
$meta->prepare(
    "INSERT INTO sso_sessions (session_token, user_id, ip_address, user_agent, expires_at, last_seen_at)
     VALUES (?,?,?,?, DATE_ADD(NOW(), INTERVAL 7 DAY), NOW())"
)->execute([$sessionToken, $uid, $ip, $ua]);

// Emet cookie SSO HMAC.
sso_set_cookie([
    'session_token' => $sessionToken,
    'user_id' => $uid,
    'email' => $u['email'],
    'tenants' => $tenants,
    'current_tenant' => $currentTenant,
    'iat' => time(),
], $ttlSec);

// Touch users.last_login_at.
try { $meta->prepare("UPDATE users SET last_login_at = NOW(), last_login = NOW() WHERE id = ?")->execute([$uid]); } catch (Throwable $e) {}

record_attempt($meta, $ip, $email, true, 'sso_login_ok');

// Validation return_to : doit etre un sous-domaine ocre.immo.
$redirect = null;
if ($returnTo) {
    $parsed = parse_url($returnTo);
    $host = $parsed['host'] ?? '';
    if ($host && preg_match('/(^|\.)ocre\.immo$/', $host)) {
        $redirect = $returnTo;
    }
}
if (!$redirect && $currentTenant) {
    $redirect = 'https://' . $currentTenant . '.ocre.immo/';
}

echo json_encode([
    'ok' => true,
    'user' => [
        'id' => $uid,
        'email' => $u['email'],
        'prenom' => $u['prenom'],
        'nom' => $u['nom'],
    ],
    'tenants' => $tenants,
    'current_tenant' => $currentTenant,
    'redirect_to' => $redirect,
]);
