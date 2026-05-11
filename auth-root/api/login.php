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
$st = auth_db()->prepare("SELECT id, email, magic_link_ttl_hours FROM auth_users WHERE email = ? LIMIT 1");
$st->execute([$email]);
$user = $st->fetch(PDO::FETCH_ASSOC);

// CAS C — user inconnu → signup required (frontend deploie un accordeon avec champs prenom/nom/tel/cgu/rgpd).
// Le frontend POST ensuite vers /api/magic-link/request.php avec le full profile.
// On fournit le fallback redirect_url pour les clients qui ne savent pas faire l'accordeon (compat).
if (!$user) {
    auth_send_json([
        'ok' => true,
        'action' => 'signup_required',
        'redirect_url' => 'https://auth.ocre.immo/signup?app=' . urlencode($app) . '&email=' . urlencode($email),
    ]);
}

$userId = (int) $user['id'];
$ttlHours = max(1, (int) ($user['magic_link_ttl_hours'] ?? 24));

// CAS A — cookie ocre_jwt valide + session NON revoked + slug tenant existe → direct
$jwtCookie = $_COOKIE['ocre_jwt'] ?? '';
$hasValidSession = false;
if ($jwtCookie) {
    $r = jwt_decode($jwtCookie, true);
    if ($r['ok'] && (int)$r['claims']['sub'] === $userId) {
        $jti = $r['claims']['jti'];
        $sst = auth_db()->prepare("SELECT 1 FROM auth_sessions WHERE jti = ? AND user_id = ? AND revoked_at IS NULL AND expires_at > NOW() LIMIT 1");
        $sst->execute([$jti, $userId]);
        if ($sst->fetch()) $hasValidSession = true;
    }
}

if ($hasValidSession) {
    // Verifie tenant (slug dans ocre_meta.users + DB ocre_wsp_<slug>)
    try {
        $env = parse_ini_file('/root/.secrets/ocre-db.env', false, INI_SCANNER_RAW);
        $pdoMeta = new PDO('mysql:host=' . ($env['DB_HOST'] ?? '127.0.0.1') . ';dbname=ocre_meta;charset=utf8mb4',
            $env['DB_USER'] ?? 'ocre_app', $env['DB_PASS'] ?? '',
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
        $lu = $pdoMeta->prepare("SELECT slug FROM users WHERE email = ? AND slug IS NOT NULL AND slug != '' LIMIT 1");
        $lu->execute([$email]);
        $legacy = $lu->fetch();
        if ($legacy && !empty($legacy['slug'])) {
            $slug = preg_replace('/[^a-z0-9_-]/', '', $legacy['slug']);
            $existsDb = $pdoMeta->query("SHOW DATABASES LIKE 'ocre_wsp_" . $slug . "'")->fetch();
            if ($existsDb) {
                auth_send_json([
                    'ok' => true,
                    'action' => 'direct',
                    'redirect_url' => 'https://' . $slug . '.ocre.immo/',
                ]);
            }
        }
    } catch (Throwable $e) { /* fallback B */ }
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
$ttlLabel = $ttlHours >= 24 ? (intval($ttlHours / 24) . ' jours') : ($ttlHours . ' heures');
$html = '<!DOCTYPE html><html lang="fr"><body style="font-family:-apple-system,sans-serif;background:#FAF6F1;padding:32px;margin:0">'
    . '<div style="max-width:480px;margin:0 auto;background:#fff;padding:36px 30px;border-radius:14px;border:1px solid #E5DAC6">'
    . '<div style="text-align:center;margin-bottom:18px"><span style="font-family:Georgia,serif;font-style:italic;font-weight:600;font-size:36px;color:#8B5E3C">Oc<span style="color:#D4A256">re</span></span></div>'
    . '<h1 style="font-family:Georgia,serif;font-style:italic;font-weight:600;color:#3D2818;margin:0 0 16px;font-size:24px;text-align:center">Ton lien d\'acces Ocre</h1>'
    . '<p style="color:#6B5642">Voici ton lien magique. Valide <strong>' . htmlspecialchars($ttlLabel) . '</strong>, a usage unique.</p>'
    . '<p style="text-align:center;margin:32px 0">'
    . '<a href="' . htmlspecialchars($url) . '" style="background:#8B5E3C;color:#fff;text-decoration:none;padding:15px 32px;border-radius:10px;font-weight:600;display:inline-block">Entrer dans Oi ' . htmlspecialchars(ucfirst($app)) . ' &rarr;</a>'
    . '</p>'
    . '<p style="font-size:12.5px;color:#998877">Si tu n\'as pas demande ce lien, ignore cet email.</p>'
    . '<p style="font-size:11.5px;color:#998877">&mdash; Ocre Immo</p>'
    . '</div></body></html>';
$text = "Ton lien Ocre : " . $url . " (valide " . $ttlLabel . ")";

@email_send($email, 'Ton lien d\'acces Ocre', $html, $text);
@file_put_contents('/var/log/ocre-magic-link.log', '[' . date('c') . '] login_unified to=' . $email . ' app=' . $app . ' ttl=' . $ttlHours . 'h user_id=' . $userId . "\n", FILE_APPEND);

auth_send_json(['ok' => true, 'action' => 'link_sent']);
