<?php
// M/2026/05/11/37 AMENDEMENT #2 — lib partagee : provision tenant pour user auth_users.
// Utilise par /api/provision-tenant.php (caller HTTP depuis agent.ocre.immo router)
// et par /api/magic-link/validate.php (inline apres consume, pour redirect direct tenant).

require_once __DIR__ . '/auth_db.php';

function _provision_slug_from_email(string $email): string {
    $prefix = preg_replace('/[^a-z0-9]/', '', strtolower(explode('@', $email)[0]));
    if (strlen($prefix) < 3) $prefix = 'oi' . $prefix;
    $hash = substr(hash('sha256', $email), 0, 4);
    $slug = substr($prefix, 0, 30) . '-' . $hash;
    return substr(preg_replace('/[^a-z0-9-]/', '', $slug), 0, 40);
}

function _provision_pdo_meta(): ?PDO {
    static $pdo = null;
    if ($pdo !== null) return $pdo;
    try {
        $env = parse_ini_file('/root/.secrets/ocre-db.env', false, INI_SCANNER_RAW);
        $pdo = new PDO('mysql:host=' . ($env['DB_HOST'] ?? '127.0.0.1') . ';dbname=ocre_meta;charset=utf8mb4',
            $env['DB_USER'] ?? 'ocre_app', $env['DB_PASS'] ?? '',
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC, PDO::ATTR_EMULATE_PREPARES => false]);
        return $pdo;
    } catch (Throwable $e) { error_log('[provision] meta connect failed: ' . $e->getMessage()); return null; }
}

/**
 * Provisionne le tenant Oi Agent pour le user auth_users donne.
 * Idempotent : reuse slug existant + INSERT IGNORE workspace_members.
 * Retour : ['ok'=>bool, 'slug'?, 'tenant_url'?, 'sso_token'?, 'error'?]
 */
function auth_provision_tenant(int $authUserId, string $app = 'agent'): array {
    if ($app !== 'agent') return ['ok' => false, 'error' => 'unsupported_app'];

    // Recup auth_user
    $st = auth_db()->prepare("SELECT email, first_name, last_name FROM auth_users WHERE id = ?");
    $st->execute([$authUserId]);
    $authUser = $st->fetch(PDO::FETCH_ASSOC);
    if (!$authUser) return ['ok' => false, 'error' => 'auth_user_not_found'];
    $email = strtolower((string) $authUser['email']);

    $pdoMeta = _provision_pdo_meta();
    if (!$pdoMeta) return ['ok' => false, 'error' => 'meta_db_unavailable'];

    // Cherche ou cree user legacy avec slug
    $lst = $pdoMeta->prepare("SELECT id, slug FROM users WHERE email = ? LIMIT 1");
    $lst->execute([$email]);
    $legacyUser = $lst->fetch();

    if ($legacyUser && !empty($legacyUser['slug'])) {
        $slug = $legacyUser['slug'];
        $legacyUserId = (int) $legacyUser['id'];
    } else {
        $slug = _provision_slug_from_email($email);
        $check = $pdoMeta->prepare("SELECT id FROM users WHERE slug = ? AND email != ?");
        $check->execute([$slug, $email]);
        if ($check->fetch()) $slug = substr($slug, 0, 30) . '-' . substr(hash('sha256', $email . microtime()), 0, 4);

        if ($legacyUser) {
            $pdoMeta->prepare("UPDATE users SET slug = ?, role = COALESCE(role, 'agent'), status = COALESCE(NULLIF(status,''), 'active') WHERE id = ?")
                ->execute([$slug, (int) $legacyUser['id']]);
            $legacyUserId = (int) $legacyUser['id'];
        } else {
            $ins = $pdoMeta->prepare("INSERT INTO users (email, slug, prenom, nom, role, status, activated_at, last_login) VALUES (?, ?, ?, ?, 'agent', 'active', NOW(), NOW())");
            $ins->execute([$email, $slug, (string) ($authUser['first_name'] ?? ''), (string) ($authUser['last_name'] ?? '')]);
            $legacyUserId = (int) $pdoMeta->lastInsertId();
        }
    }

    // Provisionne DB ocre_wsp_<slug> (idempotent)
    require_once '/opt/ocre-app/api/_provision.php';
    $prov = provision_agent_workspace($slug, $pdoMeta);
    $provOk = !empty($prov['ok']) || ($prov['error'] ?? '') === 'database_already_exists';
    if (!$provOk) {
        error_log('[provision] failed user_id=' . $authUserId . ' slug=' . $slug . ' detail=' . json_encode($prov));
        return ['ok' => false, 'error' => 'provision_failed', 'detail' => $prov];
    }

    // Workspace meta + member owner (idempotent)
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
        $mst = $pdoMeta->prepare("SELECT 1 FROM workspace_members WHERE workspace_id = ? AND user_id = ?");
        $mst->execute([$wspId, $legacyUserId]);
        if (!$mst->fetch()) {
            $pdoMeta->prepare("INSERT INTO workspace_members (workspace_id, user_id, role) VALUES (?, ?, 'owner')")
                ->execute([$wspId, $legacyUserId]);
        }
    } catch (Throwable $e) { /* swallow */ }

    // SSO token (session legacy 30j)
    $ssoToken = bin2hex(random_bytes(32));
    try {
        $ua = substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 256);
        $ip = $_SERVER['REMOTE_ADDR'] ?? '';
        $pdoMeta->prepare("INSERT INTO sessions (token, user_id, expires_at, ip, user_agent, last_activity) VALUES (?, ?, DATE_ADD(NOW(), INTERVAL 30 DAY), ?, ?, NOW())")
            ->execute([$ssoToken, $legacyUserId, $ip, $ua]);
    } catch (Throwable $e) { error_log('[provision] sso insert failed: ' . $e->getMessage()); }

    return [
        'ok' => true,
        'slug' => $slug,
        'tenant_url' => 'https://' . $slug . '.ocre.immo/',
        'sso_token' => $ssoToken,
        'legacy_user_id' => $legacyUserId,
    ];
}
