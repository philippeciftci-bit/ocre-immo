<?php
// M_OCRE_PATCH_OUTILS_RICHES — POST /api/magic-link/request.php
// Wrapper login.php avec CORS ocre.immo + champs etendus signup (first_name/last_name/societe/phone/cgu/target_app)
// Stocke metadata profil dans auth_users (lazy schema via oauth/_lib.php oauth_ensure_extended_schema)
// Anti-enumeration : retour 200 toujours.

require_once __DIR__ . '/../../lib/auth_db.php';
require_once __DIR__ . '/../../lib/email.php';
require_once __DIR__ . '/../oauth/_lib.php';

// CORS pour ocre.immo (popup signup) + auth.ocre.immo (signup.html standalone)
$origin = $_SERVER['HTTP_ORIGIN'] ?? '';
$allowedOrigins = ['https://ocre.immo', 'https://www.ocre.immo', 'https://auth.ocre.immo'];
if (in_array($origin, $allowedOrigins, true)) {
    header('Access-Control-Allow-Origin: ' . $origin);
    header('Access-Control-Allow-Credentials: true');
    header('Access-Control-Allow-Methods: POST, OPTIONS');
    header('Access-Control-Allow-Headers: Content-Type');
}
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') { http_response_code(204); exit; }
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') auth_send_json(['ok'=>false,'error'=>'method'], 405);

auth_ensure_schema();
oauth_ensure_extended_schema();

$ip = auth_client_ip();
if (!auth_rate_limit_check($ip, 'magic_request', 10, 3600)) auth_send_json(['ok'=>false,'error'=>'rate_limit'], 429);
auth_rate_limit_record($ip, 'magic_request');

$raw = file_get_contents('php://input');
$data = json_decode($raw, true) ?: [];
$email = strtolower(trim((string)($data['email'] ?? '')));
if (!filter_var($email, FILTER_VALIDATE_EMAIL)) auth_send_json(['ok'=>false,'error'=>'email_invalid'], 400);

$first = trim((string)($data['first_name'] ?? ''));
$last = trim((string)($data['last_name'] ?? ''));
$societe = trim((string)($data['societe'] ?? ''));
$phone = trim((string)($data['phone'] ?? ''));
$cgu = !empty($data['cgu_accepted']);
$rgpd = !empty($data['rgpd_accepted']);
$targetApp = preg_replace('/[^a-z]/', '', strtolower((string)($data['target_app'] ?? 'agent')));

// M/2026/05/11/34 — double-lock CGU + RGPD obligatoire si profil etendu (full signup).
// Si un user fait juste "demande lien existant" (email seul, pas first_name), CGU/RGPD pas requis
// (déjà acceptés lors de l'inscription initiale). Si first_name OU phone fournis = full signup → 2 checks obligatoires.
$isFullSignup = ($first !== '' || $last !== '' || $phone !== '');
if ($isFullSignup && (!$cgu || !$rgpd)) {
    auth_send_json(['ok' => false, 'error' => 'cgu_rgpd_required'], 400);
}

try {
    $userId = auth_get_or_create_user($email);
    // Mise a jour profil etendu si fourni (non-destructif : COALESCE NULLIF '')
    if ($first || $last || $societe || $phone || $cgu || $rgpd) {
        try {
            $up = auth_db()->prepare("UPDATE auth_users SET
                first_name = COALESCE(NULLIF(?, ''), first_name),
                last_name = COALESCE(NULLIF(?, ''), last_name),
                societe = COALESCE(NULLIF(?, ''), societe),
                phone_e164 = COALESCE(NULLIF(?, ''), phone_e164),
                cgu_accepted_at = CASE WHEN ? = 1 AND cgu_accepted_at IS NULL THEN NOW() ELSE cgu_accepted_at END,
                rgpd_accepted_at = CASE WHEN ? = 1 AND rgpd_accepted_at IS NULL THEN NOW() ELSE rgpd_accepted_at END,
                oauth_provider = COALESCE(oauth_provider, 'email_magic')
                WHERE id = ?");
            $up->execute([$first, $last, $societe, $phone, $cgu ? 1 : 0, $rgpd ? 1 : 0, $userId]);
        } catch (Throwable $e) { /* swallow column missing */ }
    }
    // M/2026/05/11/20 — Cleanup tokens orphelins/expirés du user AVANT INSERT.
    // (1) Supprime les tokens expirés > 15 min (garde la table propre)
    // (2) Marque used_at sur les tokens non consommés non expirés du même user
    //     → garantit qu'un seul magic link actif par user à tout instant,
    //       le nouveau remplace l'ancien (anti orphelin loop).
    try {
        auth_db()->prepare("DELETE FROM auth_magic_tokens WHERE expires_at < NOW()")->execute();
        auth_db()->prepare("UPDATE auth_magic_tokens SET used_at = NOW() WHERE user_id = ? AND used_at IS NULL")->execute([$userId]);
    } catch (Throwable $e) { /* swallow : table may differ */ }
    // M/2026/05/11/37 — TTL custom par user (colonne magic_link_ttl_hours, default 24h).
    $ttlHours = 24;
    try {
        $ttlSt = auth_db()->prepare("SELECT magic_link_ttl_hours FROM auth_users WHERE id = ?");
        $ttlSt->execute([$userId]);
        $row = $ttlSt->fetch(PDO::FETCH_ASSOC);
        if ($row && (int)$row['magic_link_ttl_hours'] > 0) $ttlHours = (int)$row['magic_link_ttl_hours'];
    } catch (Throwable $e) { /* swallow column missing → fallback 24h */ }

    $token = bin2hex(random_bytes(32));
    $st = auth_db()->prepare("INSERT INTO auth_magic_tokens (user_id, token, expires_at, ip) VALUES (?, ?, DATE_ADD(NOW(), INTERVAL ? HOUR), ?)");
    $st->execute([$userId, $token, $ttlHours, $ip]);

    $url = 'https://auth.ocre.immo/api/magic-link/validate.php?token=' . $token . '&app=' . urlencode($targetApp);
    $hello = $first ? 'Salut ' . htmlspecialchars($first) . ' 👋' : 'Bonjour 👋';
    $html = '<!DOCTYPE html><html lang="fr"><body style="font-family:-apple-system,Segoe UI,sans-serif;background:#FAF6F1;padding:32px;margin:0">'
        . '<div style="max-width:480px;margin:0 auto;background:#fff;padding:36px 30px;border-radius:14px;border:1px solid #E5DAC6">'
        . '<div style="text-align:center;margin-bottom:18px"><span style="font-family:Georgia,serif;font-style:italic;font-weight:600;font-size:36px;color:#8B5E3C">Oc<span style="color:#D4A256">re</span></span></div>'
        . '<h1 style="font-family:Georgia,serif;font-style:italic;font-weight:600;color:#3D2818;margin:0 0 16px;font-size:24px;text-align:center">Ton lien Ocre · Oi ' . htmlspecialchars(ucfirst($targetApp)) . '</h1>'
        . '<p style="color:#3D2818">' . $hello . '</p>'
        . '<p style="color:#6B5642">Voici ton lien magique pour entrer dans Oi ' . htmlspecialchars(ucfirst($targetApp)) . '. Lien valide <strong>' . htmlspecialchars((function($h){if($h>=24 && $h%24===0){$d=intval($h/24);return $d===1?'1 jour':($d.' jours');}return $h===1?'1 heure':($h.' heures');})($ttlHours), ENT_QUOTES, 'UTF-8') . '</strong>, à usage unique.</p>'
        // M/2026/05/14/63 — bouton aligne sur ocre_btn_html() canonical (spec Philippe).
        // Table wrapper conserve pour robustesse Gmail iOS/iPad (M/2026/05/12/32 : strip padding
        // sur <a> simple, table inline universelle). Styles internes harmonises.
        . '<table cellpadding="0" cellspacing="0" border="0" role="presentation" align="center" style="margin:24px auto;border-collapse:separate;">'
        . '<tr><td align="center" bgcolor="#8B5A3C" style="background-color:#8B5A3C;border-radius:10px;padding:14px 24px;">'
        . '<a href="' . htmlspecialchars($url) . '" target="_blank" style="display:inline-block;background-color:#8B5A3C;color:#ffffff;text-decoration:none;font-family:\'DM Sans\',-apple-system,BlinkMacSystemFont,Helvetica,Arial,sans-serif;font-size:15px;font-weight:700;line-height:1.2">Entrer dans Oi ' . htmlspecialchars(ucfirst($targetApp)) . ' →</a>'
        . '</td></tr></table>'
        . '<p style="font-size:12.5px;color:#998877">Ou copie ce lien :<br><span style="word-break:break-all;color:#8B5E3C">' . htmlspecialchars($url) . '</span></p>'
        . '<hr style="border:0;border-top:1px solid #E5DAC6;margin:28px 0">'
        . '<p style="font-size:11.5px;color:#998877">Si tu n\'as pas demandé ce lien, ignore cet email — ton compte reste sécurisé.</p>'
        . '<p style="font-size:11.5px;color:#998877">— Ocre Immo · <a href="https://ocre.immo" style="color:#8B5E3C">ocre.immo</a></p>'
        . '</div></body></html>';
    $text = "Ton lien Ocre · Oi " . ucfirst($targetApp) . "\n\nLien magique : $url\n\nSi tu n'as pas demandé ce lien, ignore cet email.\n— Ocre Immo";

    // M/2026/05/12/7 — Mode test E2E : si headers X-E2E-Test + X-E2E-Timestamp valides
    // (HMAC-SHA256 partage avec /etc/ocre/e2e-secret.env, skew <= 60s), on persiste le token
    // dans /tmp/e2e-magic-tokens.json et on SKIP l envoi mail. Pattern interne Ocre / Auth0 mode test.
    $isE2E = false;
    $e2eHeader = $_SERVER['HTTP_X_E2E_TEST'] ?? '';
    $e2eTs = (int)($_SERVER['HTTP_X_E2E_TIMESTAMP'] ?? 0);
    if ($e2eHeader && $e2eTs && file_exists('/etc/ocre/e2e-secret.env')) {
        $secret = trim((string)@file_get_contents('/etc/ocre/e2e-secret.env'));
        if ($secret !== '' && abs(time() - $e2eTs) <= 60) {
            $expected = hash_hmac('sha256', (string)$e2eTs, $secret);
            if (hash_equals($expected, $e2eHeader)) {
                $isE2E = true;
                $tokensFile = '/tmp/e2e-magic-tokens.json';
                $existing = is_file($tokensFile) ? @file_get_contents($tokensFile) : '';
                $store = ($existing && ($d = json_decode($existing, true)) && is_array($d)) ? $d : [];
                $store[$email] = ['token' => $token, 'url' => $url, 'app' => $targetApp, 'created_at' => time()];
                @file_put_contents($tokensFile, json_encode($store), LOCK_EX);
                @chmod($tokensFile, 0660);
            }
        }
    }
    // M_OCRE_MAGIC_LINK_DIAG — logging fichier traçabilité OVH SMTP envois magic link
    $logFile = '/var/log/ocre-magic-link.log';
    @touch($logFile); @chmod($logFile, 0664);
    if ($isE2E) {
        $emailOk = false;
        $logLine = '[' . date('c') . '] to=' . $email . ' app=' . $targetApp . ' user_id=' . $userId . ' token_id_short=' . substr($token, 0, 12) . ' email_send=SKIPPED_E2E ip=' . $ip . "\n";
    } else {
        $emailOk = @email_send($email, 'Ton lien Ocre · Oi ' . ucfirst($targetApp), $html, $text);
        $logLine = '[' . date('c') . '] to=' . $email . ' app=' . $targetApp . ' user_id=' . $userId . ' token_id_short=' . substr($token, 0, 12) . ' email_send=' . ($emailOk ? 'TRUE' : 'FALSE') . ' ip=' . $ip . "\n";
    }
    @file_put_contents($logFile, $logLine, FILE_APPEND);
} catch (Throwable $e) {
    error_log('magic_request: ' . $e->getMessage());
    @file_put_contents('/var/log/ocre-magic-link.log', '[' . date('c') . '] EXCEPTION email=' . $email . ' err=' . $e->getMessage() . "\n", FILE_APPEND);
}

auth_send_json(['ok' => true, 'message' => 'Email envoyé']);
