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

    $db->prepare("UPDATE auth_users SET last_login_at = NOW() WHERE id = ?")->execute([$userId]);

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
// M_OCRE_PARCOURS_V4 — toutes les apps via app.ocre.immo/oi-<slug> (sous-domaines pas tous deployes)
$appUrls = [
    'agent' => 'https://app.ocre.immo/oi-agent',
    'scan' => 'https://app.ocre.immo/oi-scan',
    'book' => 'https://app.ocre.immo/oi-book',
    'demande' => 'https://app.ocre.immo/oi-recherche',
    'capture' => 'https://app.ocre.immo/oi-capture',
    'estimer' => 'https://app.ocre.immo/oi-estimer',
];
$dest = $appUrls[$appTarget] ?? 'https://app.ocre.immo/oi-agent';
// Activation auto module pour user (premier login outil = activation)
if ($appTarget) um_activate($userId, $appTarget);
header('Location: ' . $dest);
exit;
