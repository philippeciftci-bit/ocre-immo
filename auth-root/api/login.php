<?php
// M/2026/05/11/37 — Refactor /api/login.php : endpoint login-or-signup unifie.
// POST { email, app } → 3 cas :
//   A) user existe + session JWT valide + tenant provisionne  → {ok, action:"direct", redirect_url:"https://<slug>.ocre.immo/"}
//   B) user existe + pas de session active OU pas de tenant   → {ok, action:"link_sent"}  (magic link envoye, TTL custom user)
//   C) user n'existe pas                                       → {ok, action:"signup_required", redirect_url:"https://auth.ocre.immo/signup?app=...&email=..."}
// Anti-enumeration : retour 200 dans tous les cas valides. Erreurs 400 uniquement pour input invalide.
// Aucun appel a auth_get_or_create_user (vs ancien login.php qui creait silencieusement).

require_once __DIR__ . '/../lib/auth_db.php';
require_once __DIR__ . '/../lib/jwt.php';
require_once __DIR__ . '/../lib/email.php';

// CORS pour ocre.immo (popup vitrine) + auth.ocre.immo + agent.ocre.immo
$origin = $_SERVER['HTTP_ORIGIN'] ?? '';
$allowed = ['https://ocre.immo', 'https://www.ocre.immo', 'https://auth.ocre.immo', 'https://agent.ocre.immo'];
$tenantOrigin = preg_match('#^https://[a-z0-9][a-z0-9-]*\.ocre\.immo$#', $origin);
if (in_array($origin, $allowed, true) || $tenantOrigin) {
    header('Access-Control-Allow-Origin: ' . $origin);
    header('Access-Control-Allow-Credentials: true');
    header('Access-Control-Allow-Methods: POST, OPTIONS');
    header('Access-Control-Allow-Headers: Content-Type');
}
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') { http_response_code(204); exit; }
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') auth_send_json(['ok'=>false,'error'=>'method'], 405);

auth_ensure_schema();

$ip = auth_client_ip();
if (!auth_rate_limit_check($ip, 'login_unified', 30, 3600)) auth_send_json(['ok'=>false,'error'=>'rate_limit'], 429);
auth_rate_limit_record($ip, 'login_unified');

$body = json_decode(file_get_contents('php://input'), true) ?: [];
$email = strtolower(trim((string)($body['email'] ?? '')));
$app = preg_replace('/[^a-z]/', '', strtolower((string)($body['app'] ?? 'agent')));
if (!filter_var($email, FILTER_VALIDATE_EMAIL)) auth_send_json(['ok'=>false,'error'=>'email_invalid'], 400);

// Lookup user
$st = auth_db()->prepare("SELECT id, email, magic_link_ttl_hours, last_login_at, last_magic_link_consumed_at FROM auth_users WHERE email = ? LIMIT 1");
$st->execute([$email]);
$user = $st->fetch(PDO::FETCH_ASSOC);

// CAS C — user inconnu → signup required (frontend deploie un accordeon avec champs prenom/nom/tel/cgu/rgpd).
if (!$user) {
    auth_send_json([
        'ok' => true,
        'action' => 'signup_required',
        'redirect_url' => 'https://auth.ocre.immo/signup?app=' . urlencode($app) . '&email=' . urlencode($email),
    ]);
}

$userId = (int) $user['id'];
$ttlHours = max(1, (int) ($user['magic_link_ttl_hours'] ?? 24));

// M/2026/05/11/43 — CAS A base sur TTL DB (PAS sur cookie navigateur, fix Safari iPad ITP).
// Critere : max(last_login_at, last_magic_link_consumed_at) dans la fenetre [now - ttl_hours, now].
// Si OUI : re-pose cookies SSO (au cas ou navigateur les avait perdus) + retourne direct.
// Si NON ou jamais : fallback cas B (envoi nouveau magic link).
$lastA = $user['last_login_at'] ? strtotime($user['last_login_at']) : 0;
$lastB = $user['last_magic_link_consumed_at'] ? strtotime($user['last_magic_link_consumed_at']) : 0;
$lastAccess = max($lastA, $lastB);
$ttlSeconds = $ttlHours * 3600;
$withinTtl = $lastAccess > 0 && (time() - $lastAccess) < $ttlSeconds;

if ($withinTtl) {
    // M/2026/05/11/43 — CAS A par TTL DB : user a un acces recent dans la fenetre TTL.
    // (1) Recree session SSO inline : nouveau JWT + auth_sessions row + pose cookies (au cas ou
    //     navigateur les avait perdus ou bloques par ITP). UPDATE last_login_at NOW().
    // (2) Resolve slug tenant (cherche dans users legacy + provision inline si manquant).
    // (3) Retourne action=direct avec redirect_url tenant.
    try {
        // (1) Recree session SSO
        $jwt = jwt_encode($userId, 365 * 86400);
        $refresh = bin2hex(random_bytes(32));
        $ua = substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 256);
        try {
            auth_db()->prepare(
                "INSERT INTO auth_sessions (user_id, jti, refresh_token, expires_at, user_agent, ip)
                 VALUES (?, ?, ?, DATE_ADD(NOW(), INTERVAL 1 YEAR), ?, ?)"
            )->execute([$userId, $jwt['jti'], $refresh, $ua, $ip]);
            auth_db()->prepare("UPDATE auth_users SET last_login_at = NOW() WHERE id = ?")->execute([$userId]);
        } catch (Throwable $e) { /* swallow */ }
        auth_set_cookies($jwt['token'], $refresh);
    } catch (Throwable $e) { @error_log('[login cas A sso recreate] ' . $e->getMessage()); }

    try {
        $env = parse_ini_file('/root/.secrets/ocre-db.env', false, INI_SCANNER_RAW);
        $pdoMeta = new PDO('mysql:host=' . ($env['DB_HOST'] ?? '127.0.0.1') . ';dbname=ocre_meta;charset=utf8mb4',
            $env['DB_USER'] ?? 'ocre_app', $env['DB_PASS'] ?? '',
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
        $lu = $pdoMeta->prepare("SELECT slug FROM users WHERE email = ? AND slug IS NOT NULL AND slug != '' LIMIT 1");
        $lu->execute([$email]);
        $legacy = $lu->fetch();
        $slug = null;
        if ($legacy && !empty($legacy['slug'])) {
            $cand = preg_replace('/[^a-z0-9_-]/', '', $legacy['slug']);
            $existsDb = $pdoMeta->query("SHOW DATABASES LIKE 'ocre_wsp_" . $cand . "'")->fetch();
            if ($existsDb) $slug = $cand;
        }
        if (!$slug) {
            // Provisioning inline via lib partagee (M/37 amd#2).
            require_once __DIR__ . '/../lib/provision.php';
            $prov = auth_provision_tenant($userId, 'agent');
            if (!empty($prov['ok']) && !empty($prov['slug'])) {
                $slug = $prov['slug'];
                if (!empty($prov['sso_token'])) {
                    auth_send_json([
                        'ok' => true,
                        'action' => 'direct',
                        'redirect_url' => 'https://' . $slug . '.ocre.immo/?_s=' . urlencode($prov['sso_token']) . '&source=ttl_login',
                    ]);
                }
            }
        }
        if ($slug) {
            auth_send_json([
                'ok' => true,
                'action' => 'direct',
                'redirect_url' => 'https://' . $slug . '.ocre.immo/?source=ttl_login',
            ]);
        }
    } catch (Throwable $e) { @error_log('[login cas A] ' . $e->getMessage()); }
}

// CAS B — magic link envoye avec TTL custom user
try {
    auth_db()->prepare("DELETE FROM auth_magic_tokens WHERE expires_at < NOW()")->execute();
    auth_db()->prepare("UPDATE auth_magic_tokens SET used_at = NOW() WHERE user_id = ? AND used_at IS NULL")->execute([$userId]);
} catch (Throwable $e) {}

$token = bin2hex(random_bytes(32));
try {
    $stIns = auth_db()->prepare("INSERT INTO auth_magic_tokens (user_id, token, expires_at, ip) VALUES (?, ?, DATE_ADD(NOW(), INTERVAL ? HOUR), ?)");
    $stIns->execute([$userId, $token, $ttlHours, $ip]);
} catch (Throwable $e) {
    @error_log('[login-unified] INSERT token failed: ' . $e->getMessage());
    auth_send_json(['ok'=>true, 'action'=>'link_sent']);
}

$url = 'https://auth.ocre.immo/api/magic-link/validate.php?token=' . $token . '&app=' . urlencode($app);

// M/2026/05/11/40 BUG#3 — formatTtlHuman : accord singulier/pluriel + accents UTF-8.
function _login_format_ttl_human(int $hours): string {
    if ($hours >= 24 && $hours % 24 === 0) {
        $days = intval($hours / 24);
        return $days === 1 ? '1 jour' : ($days . ' jours');
    }
    return $hours === 1 ? '1 heure' : ($hours . ' heures');
}
$ttlLabel = _login_format_ttl_human($ttlHours);
$appLabel = htmlspecialchars(ucfirst($app), ENT_QUOTES, 'UTF-8');
$html = '<!DOCTYPE html><html lang="fr"><head><meta charset="UTF-8"></head><body style="font-family:-apple-system,sans-serif;background:#FAF6F1;padding:32px;margin:0">'
    . '<div style="max-width:480px;margin:0 auto;background:#fff;padding:36px 30px;border-radius:14px;border:1px solid #E5DAC6">'
    . '<div style="text-align:center;margin-bottom:18px"><span style="font-family:Georgia,serif;font-style:italic;font-weight:600;font-size:36px;color:#8B5E3C">Oc<span style="color:#D4A256">re</span></span></div>'
    . '<h1 style="font-family:Georgia,serif;font-style:italic;font-weight:600;color:#3D2818;margin:0 0 16px;font-size:24px;text-align:center">Ton lien d\'accès Ocre</h1>'
    . '<p style="color:#6B5642">Voici ton lien magique. Valide <strong>' . htmlspecialchars($ttlLabel, ENT_QUOTES, 'UTF-8') . '</strong>, à usage unique.</p>'
    . '<p style="text-align:center;margin:32px 0">'
    . '<a href="' . htmlspecialchars($url, ENT_QUOTES, 'UTF-8') . '" style="background:#8B5E3C;color:#fff;text-decoration:none;padding:15px 32px;border-radius:10px;font-weight:600;display:inline-block">Entrer dans Oi ' . $appLabel . ' &rarr;</a>'
    . '</p>'
    . '<p style="font-size:12.5px;color:#998877">Si tu n\'as pas demandé ce lien, ignore cet email.</p>'
    . '<p style="font-size:11.5px;color:#998877">&mdash; Ocre Immo</p>'
    . '</div></body></html>';
$text = "Ton lien Ocre : " . $url . " (valide " . $ttlLabel . ")";

@email_send($email, 'Ton lien d\'accès · Oi ' . ucfirst($app), $html, $text);
@file_put_contents('/var/log/ocre-magic-link.log', '[' . date('c') . '] login_unified to=' . $email . ' app=' . $app . ' ttl=' . $ttlHours . 'h user_id=' . $userId . "\n", FILE_APPEND);

auth_send_json(['ok' => true, 'action' => 'link_sent']);
