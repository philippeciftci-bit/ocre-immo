<?php
// M/2026/05/11/36 — Provision auto tenant Oi Agent pour user auth_users (V4 magic-link).
// Bridge : si auth_user.email n'a pas d'entree dans ocre_meta.users (legacy app), creer un user legacy
// avec slug genere depuis email. Provisionne DB ocre_wsp_<slug> via _provision.php existant.
// Cree une session legacy pour SSO cross-subdomain (token rendu via cookie + dans JSON pour _s redirect).
// Retour : {ok, slug, tenant_url, sso_token}

require_once __DIR__ . '/../lib/auth_db.php';
require_once __DIR__ . '/../lib/jwt.php';

// CORS strict pour agent.ocre.immo (le seul caller legitime).
$origin = $_SERVER['HTTP_ORIGIN'] ?? '';
if (preg_match('#^https://[a-z0-9-]+\.ocre\.immo$#', $origin)) {
    header('Access-Control-Allow-Origin: ' . $origin);
    header('Access-Control-Allow-Credentials: true');
    header('Access-Control-Allow-Methods: POST, OPTIONS');
    header('Access-Control-Allow-Headers: Content-Type');
}
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') { http_response_code(204); exit; }
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') auth_send_json(['ok' => false, 'error' => 'method'], 405);

auth_ensure_schema();

// Identification du user via cookie ocre_jwt.
$token = $_COOKIE['ocre_jwt'] ?? '';
if (!$token) auth_send_json(['ok' => false, 'error' => 'no_jwt'], 401);
$r = jwt_decode($token, true);
if (!$r['ok']) auth_send_json(['ok' => false, 'error' => $r['error']], 401);
$userId = (int) $r['claims']['sub'];

// Recup user auth_users
$st = auth_db()->prepare("SELECT id, email, first_name, last_name FROM auth_users WHERE id = ? LIMIT 1");
$st->execute([$userId]);
$authUser = $st->fetch(PDO::FETCH_ASSOC);
if (!$authUser) auth_send_json(['ok' => false, 'error' => 'auth_user_not_found'], 404);

$body = json_decode(file_get_contents('php://input'), true) ?: [];
$app = preg_replace('/[^a-z]/', '', strtolower((string) ($body['app'] ?? 'agent')));
if ($app !== 'agent') auth_send_json(['ok' => false, 'error' => 'unsupported_app: ' . $app], 400);

$email = strtolower((string) $authUser['email']);

// Genere un slug deterministe depuis l'email (premier prefix + hash court). 3-40 chars [a-z0-9-].
function provision_slug_from_email(string $email): string {
    $prefix = preg_replace('/[^a-z0-9]/', '', strtolower(explode('@', $email)[0]));
    if (strlen($prefix) < 3) $prefix = 'oi' . $prefix;
    $hash = substr(hash('sha256', $email), 0, 4);
    $slug = substr($prefix, 0, 30) . '-' . $hash;
    $slug = substr(preg_replace('/[^a-z0-9-]/', '', $slug), 0, 40);
    return $slug;
}

// Connexion meta (ocre_meta : users legacy + workspaces + provisioning DB).
try {
    $envFile = '/root/.secrets/ocre-db.env';
    $env = parse_ini_file($envFile, false, INI_SCANNER_RAW);
    $pdoMeta = new PDO(
        'mysql:host=' . ($env['DB_HOST'] ?? '127.0.0.1') . ';dbname=ocre_meta;charset=utf8mb4',
        $env['DB_USER'] ?? 'ocre_app',
        $env['DB_PASS'] ?? '',
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC, PDO::ATTR_EMULATE_PREPARES => false]
    );
} catch (Throwable $e) {
    auth_send_json(['ok' => false, 'error' => 'meta_db_connect_failed', 'detail' => $e->getMessage()], 500);
}

// Cherche ou cree user legacy avec slug.
$lst = $pdoMeta->prepare("SELECT id, email, slug, role FROM users WHERE email = ? LIMIT 1");
$lst->execute([$email]);
$legacyUser = $lst->fetch();

if ($legacyUser && !empty($legacyUser['slug'])) {
    $slug = $legacyUser['slug'];
    $legacyUserId = (int) $legacyUser['id'];
} else {
    $slug = provision_slug_from_email($email);
    // Anti-collision : si slug existe deja chez un autre email, append timestamp suffix
    $check = $pdoMeta->prepare("SELECT id FROM users WHERE slug = ? AND email != ?");
    $check->execute([$slug, $email]);
    if ($check->fetch()) $slug = substr($slug, 0, 30) . '-' . substr(hash('sha256', $email . microtime()), 0, 4);

    if ($legacyUser) {
        // Existe sans slug : update slug
        $pdoMeta->prepare("UPDATE users SET slug = ?, role = COALESCE(role, 'agent'), status = COALESCE(NULLIF(status,''), 'active') WHERE id = ?")
            ->execute([$slug, (int) $legacyUser['id']]);
        $legacyUserId = (int) $legacyUser['id'];
    } else {
        // Nouveau user legacy lie au compte auth_users
        $ins = $pdoMeta->prepare("INSERT INTO users (email, slug, prenom, nom, role, status, activated_at, last_login) VALUES (?, ?, ?, ?, 'agent', 'active', NOW(), NOW())");
        $ins->execute([$email, $slug, (string) ($authUser['first_name'] ?? ''), (string) ($authUser['last_name'] ?? '')]);
        $legacyUserId = (int) $pdoMeta->lastInsertId();
    }
}

// Provisionne la DB ocre_wsp_<slug> via le helper existant (idempotent).
require_once '/opt/ocre-app/api/_provision.php';
$prov = provision_agent_workspace($slug, $pdoMeta);
$provOk = !empty($prov['ok']) || ($prov['error'] ?? '') === 'database_already_exists';
if (!$provOk) {
    @error_log('[provision-tenant] failed user_id=' . $userId . ' slug=' . $slug . ' detail=' . json_encode($prov));
    auth_send_json(['ok' => false, 'error' => 'provision_failed', 'error_message' => 'Création du workspace impossible. Réessaie ou contacte le support.', 'detail' => $prov], 500);
}

// Cree workspace meta + member si pas deja en place (idempotent).
try {
    $wst = $pdoMeta->prepare("SELECT id FROM workspaces WHERE slug = ? LIMIT 1");
    $wst->execute([$slug]);
    $wsp = $wst->fetch();
    if ($wsp) {
        $wspId = (int) $wsp['id'];
    } else {
        $pdoMeta->prepare("INSERT INTO workspaces (slug, type, display_name) VALUES (?, 'wsp', ?)")
            ->execute([$slug, trim(($authUser['first_name'] ?? '') . ' ' . ($authUser['last_name'] ?? '')) ?: $email]);
        $wspId = (int) $pdoMeta->lastInsertId();
    }
    // Membership owner
    $mst = $pdoMeta->prepare("SELECT 1 FROM workspace_members WHERE workspace_id = ? AND user_id = ?");
    $mst->execute([$wspId, $legacyUserId]);
    if (!$mst->fetch()) {
        $pdoMeta->prepare("INSERT INTO workspace_members (workspace_id, user_id, role) VALUES (?, ?, 'owner')")
            ->execute([$wspId, $legacyUserId]);
    }
} catch (Throwable $e) { /* swallow : pas bloquant si schema differe */ }

// Cree une session legacy (table sessions) pour SSO cross-subdomain : la SPA Oi Agent
// recevra le token via _s=<token> dans l'URL et le posera en cookie de son cote.
$ssoToken = bin2hex(random_bytes(32));
try {
    $ua = substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 256);
    $ip = $_SERVER['REMOTE_ADDR'] ?? '';
    $pdoMeta->prepare("INSERT INTO sessions (token, user_id, expires_at, ip, user_agent, last_activity) VALUES (?, ?, DATE_ADD(NOW(), INTERVAL 30 DAY), ?, ?, NOW())")
        ->execute([$ssoToken, $legacyUserId, $ip, $ua]);
} catch (Throwable $e) {
    @error_log('[provision-tenant] sso session insert failed: ' . $e->getMessage());
}

auth_send_json([
    'ok' => true,
    'slug' => $slug,
    'tenant_url' => 'https://' . $slug . '.ocre.immo/',
    'sso_token' => $ssoToken,
    'user_id' => $legacyUserId,
]);
