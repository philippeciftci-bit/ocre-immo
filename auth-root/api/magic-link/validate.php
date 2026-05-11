<?php
// M97 — GET /api/magic-link/validate.php?token=XXX
// Vérifie + crée JWT + pose cookies cross-subdomain + redirect app.ocre.immo.

require_once __DIR__ . '/../../lib/auth_db.php';
require_once __DIR__ . '/../../lib/jwt.php';
require_once __DIR__ . '/../../lib/user_modules.php';

// M_OCRE_PARCOURS_V4 — mode magic link configurable
const MAGIC_LINK_MODE = 'indefinite'; // 'single_use' | 'limited_24h' | 'limited_30d' | 'indefinite'

auth_ensure_schema();
um_ensure_schema();

$token = $_GET['token'] ?? '';
if (!preg_match('/^[a-f0-9]{64}$/', $token)) {
    header('Location: /error.html?reason=token_invalid');
    exit;
}

$db = auth_db();
// M_OCRE_PARCOURS_V4 — selon MAGIC_LINK_MODE filter expires_at + used_at
$tokenSql = "SELECT id, user_id FROM auth_magic_tokens WHERE token = ?";
if (MAGIC_LINK_MODE === 'single_use') $tokenSql .= " AND used_at IS NULL AND expires_at > NOW()";
elseif (MAGIC_LINK_MODE === 'limited_24h' || MAGIC_LINK_MODE === 'limited_30d') $tokenSql .= " AND expires_at > NOW()";
// indefinite : pas de filtre exp/used → token réutilisable indéfiniment
$tokenSql .= " LIMIT 1";
$st = $db->prepare($tokenSql);
$st->execute([$token]);
$row = $st->fetch();
if (!$row) {
    header('Location: /error.html?reason=token_invalid');
    exit;
}

$userId = (int) $row['user_id'];

$db->beginTransaction();
try {
    // En mode indefinite : ne pas marquer used_at (réutilisable)
    if (MAGIC_LINK_MODE !== 'indefinite') {
        $up = $db->prepare("UPDATE auth_magic_tokens SET used_at = NOW() WHERE id = ? AND used_at IS NULL");
        $up->execute([$row['id']]);
        if (MAGIC_LINK_MODE === 'single_use' && $up->rowCount() !== 1) {
            $db->rollBack();
            header('Location: /error.html?reason=token_invalid');
            exit;
        }
    }

    // JWT 1 an cette phase V4 (vs 30j avant)
    $jwt = jwt_encode($userId, 365 * 86400);
    $refresh = bin2hex(random_bytes(32));
    $ua = substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 256);
    $ip = auth_client_ip();

    $ins = $db->prepare(
        "INSERT INTO auth_sessions (user_id, jti, refresh_token, expires_at, user_agent, ip)
         VALUES (?, ?, ?, DATE_ADD(NOW(), INTERVAL 30 DAY), ?, ?)"
    );
    $ins->execute([$userId, $jwt['jti'], $refresh, $ua, $ip]);

    // M/2026/05/11/43 — stamp aussi last_magic_link_consumed_at pour le check TTL cas A par DB
    // (sert au login.php a determiner si le user est dans la fenetre TTL sans dependre du cookie navigateur).
    $db->prepare("UPDATE auth_users SET last_login_at = NOW(), last_magic_link_consumed_at = NOW() WHERE id = ?")->execute([$userId]);

    $db->commit();
} catch (Exception $e) {
    $db->rollBack();
    error_log('magic_validate: ' . $e->getMessage());
    header('Location: /error.html?reason=server');
    exit;
}

auth_set_cookies($jwt['token'], $refresh);
// M_OCRE_PATCH_OUTILS_RICHES — redirect vers app cible si param ?app=<slug> fourni
$appTarget = preg_replace('/[^a-z]/', '', strtolower((string)($_GET['app'] ?? '')));
if ($appTarget) um_activate($userId, $appTarget);

// M/2026/05/11/37 AMENDEMENT #2 — redirect direct vers la SPA tenant (skip splash agent.ocre.immo).
// Provisioning auto inline via lib partagee + sso_token dans l'URL pour SSO cross-subdomain.
$dest = null;
if ($appTarget === 'agent' || $appTarget === '') {
    require_once __DIR__ . '/../../lib/provision.php';
    $prov = auth_provision_tenant($userId, 'agent');
    if (!empty($prov['ok']) && !empty($prov['slug']) && !empty($prov['sso_token'])) {
        $dest = $prov['tenant_url'] . '?_s=' . urlencode($prov['sso_token']) . '&activated=1';
    } else {
        // Fallback : si provisioning fail, agent.ocre.immo router gere l'erreur proprement (retry).
        @error_log('[validate] provision failed user_id=' . $userId . ' detail=' . json_encode($prov));
        $dest = 'https://agent.ocre.immo/?activated=1';
    }
} else {
    // Apps sans sous-domaine dedie : fallback hub legacy.
    $appUrls = [
        'scan'    => 'https://app.ocre.immo/oi-scan',
        'book'    => 'https://app.ocre.immo/oi-book',
        'demande' => 'https://app.ocre.immo/oi-recherche',
        'capture' => 'https://app.ocre.immo/oi-capture',
        'estimer' => 'https://app.ocre.immo/oi-estimer',
    ];
    $dest = $appUrls[$appTarget] ?? 'https://agent.ocre.immo/?activated=1';
}
header('Location: ' . $dest);
exit;
