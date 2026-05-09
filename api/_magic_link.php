<?php
// M/2026/05/09/13 (M63) — Helper consommation magic link tolérant prefetch bots Gmail/Slack/etc.
// + fenetre 5 min multi-consume + cap 3 sessions + audit attempts.
//
// Pattern d'usage dans agents_activate_v2.php / superadmin_activate.php :
//   require_once __DIR__ . '/_magic_link.php';
//   $check = checkMagicLinkConsume($pdo, $token, $_SERVER['HTTP_USER_AGENT'] ?? '', $_SERVER['REMOTE_ADDR'] ?? '');
//   if ($check['action'] === 'bot_bypass') { header('Content-Type: text/html'); echo magicLinkBotPage(); exit; }
//   if ($check['action'] === 'reject') { http_response_code($check['http']); echo json_encode($check['response']); exit; }
//   $user = $check['user']; // continue avec creation session

require_once __DIR__ . '/db.php';

const MAGIC_LINK_WINDOW_SECONDS = 300; // 5 min
const MAGIC_LINK_MAX_CONSUMES = 3;     // max 3 sessions distinctes par token

const BOT_USER_AGENT_PATTERNS = [
    'GoogleImageProxy', 'GoogleSafeBrowsing', 'Googlebot', 'Mail.GoogleSecurity',
    'facebookexternalhit', 'Slackbot-LinkExpanding', 'Slackbot', 'Twitterbot',
    'WhatsApp', 'TelegramBot', 'LinkedInBot', 'Discordbot', 'AhrefsBot',
    'Outlook-iOS', 'Yahoo Mail', 'YandexBot', 'DuckDuckBot', 'Applebot',
];

function isBotUserAgent(string $ua): bool {
    if ($ua === '') return true; // UA vide = suspect
    $ualc = strtolower($ua);
    // Patterns generiques
    if (preg_match('/\b(bot|crawler|preview|fetcher|spider|scraper|prefetch|linkpreview)\b/i', $ualc)) return true;
    // Liste explicite
    foreach (BOT_USER_AGENT_PATTERNS as $pat) {
        if (stripos($ua, $pat) !== false) return true;
    }
    return false;
}

function logMagicLinkAttempt(PDO $pdo, string $token, ?int $userId, string $ua, string $ip, string $result): void {
    try {
        $st = $pdo->prepare(
            "INSERT INTO magic_link_attempts (token_prefix, user_id, user_agent, ip_address, result)
             VALUES (?, ?, ?, ?, ?)"
        );
        $st->execute([substr($token, 0, 16), $userId, substr($ua, 0, 500), substr($ip, 0, 45), $result]);
    } catch (Throwable $e) {
        @error_log('[magic_link] attempt log failed: ' . $e->getMessage());
    }
}

/**
 * Verifie un token magic link et retourne action + user.
 * @return array {
 *   action: 'bot_bypass' | 'consume' | 'reject',
 *   user?: array,                         // si consume : data du user (id, email, slug, prenom, nom, role)
 *   is_first_consume?: bool,              // si consume : true si premier humain
 *   http?: int,                           // si reject : code HTTP
 *   response?: array,                     // si reject : payload JSON
 * }
 */
function checkMagicLinkConsume(PDO $pdo, string $token, string $ua, string $ip): array {
    if ($token === '' || !preg_match('/^[a-f0-9]{32,128}$/', $token)) {
        logMagicLinkAttempt($pdo, $token, null, $ua, $ip, 'invalid_sig');
        return ['action' => 'reject', 'http' => 400, 'response' => ['ok' => false, 'error' => 'TOKEN_INVALID']];
    }

    // Bot bypass : retourner sans toucher la DB du user.
    if (isBotUserAgent($ua)) {
        logMagicLinkAttempt($pdo, $token, null, $ua, $ip, 'bot_skip');
        return ['action' => 'bot_bypass'];
    }

    $st = $pdo->prepare(
        "SELECT id, email, prenom, nom, slug, role, status, archived_at, magic_link_disabled,
                activation_token_expires_at, activation_first_consumed_at, activation_consumes_count
         FROM users WHERE activation_token = ? LIMIT 1"
    );
    // Note: magic_link_disabled n existe peut-etre pas, on degrade gracieusement.
    try {
        $st->execute([$token]);
    } catch (Throwable $e) {
        // Si la colonne magic_link_disabled est absente (pas migration), retry sans.
        $st = $pdo->prepare(
            "SELECT id, email, prenom, nom, slug, role, status, archived_at,
                    activation_token_expires_at, activation_first_consumed_at, activation_consumes_count
             FROM users WHERE activation_token = ? LIMIT 1"
        );
        $st->execute([$token]);
    }
    $user = $st->fetch(PDO::FETCH_ASSOC);

    if (!$user) {
        logMagicLinkAttempt($pdo, $token, null, $ua, $ip, 'token_not_found');
        return ['action' => 'reject', 'http' => 404, 'response' => ['ok' => false, 'error' => 'TOKEN_NOT_FOUND']];
    }

    $userId = (int)$user['id'];
    if (!empty($user['archived_at'])) {
        logMagicLinkAttempt($pdo, $token, $userId, $ua, $ip, 'disabled');
        return ['action' => 'reject', 'http' => 403, 'response' => ['ok' => false, 'error' => 'USER_ARCHIVED']];
    }
    if (isset($user['magic_link_disabled']) && (int)$user['magic_link_disabled'] === 1) {
        logMagicLinkAttempt($pdo, $token, $userId, $ua, $ip, 'disabled');
        return ['action' => 'reject', 'http' => 403, 'response' => ['ok' => false, 'error' => 'MAGIC_LINK_DISABLED']];
    }

    $exp = (string)($user['activation_token_expires_at'] ?? '');
    if ($exp && strtotime($exp) < time()) {
        logMagicLinkAttempt($pdo, $token, $userId, $ua, $ip, 'expired');
        return ['action' => 'reject', 'http' => 410, 'response' => ['ok' => false, 'error' => 'TOKEN_EXPIRED']];
    }

    $firstConsumed = $user['activation_first_consumed_at'];
    $count = (int)$user['activation_consumes_count'];

    // Premier consume humain : marque first_consumed_at + count=1.
    if ($firstConsumed === null) {
        try {
            $upd = $pdo->prepare("UPDATE users SET activation_first_consumed_at = NOW(), activation_consumes_count = 1 WHERE id = ?");
            $upd->execute([$userId]);
        } catch (Throwable $e) {
            @error_log('[magic_link] first_consume update failed user_id=' . $userId . ' err=' . $e->getMessage());
        }
        logMagicLinkAttempt($pdo, $token, $userId, $ua, $ip, 'success');
        return ['action' => 'consume', 'user' => $user, 'is_first_consume' => true];
    }

    // Multi-consume : check fenetre 5 min + cap 3 sessions.
    $elapsed = time() - strtotime($firstConsumed);
    if ($elapsed > MAGIC_LINK_WINDOW_SECONDS) {
        logMagicLinkAttempt($pdo, $token, $userId, $ua, $ip, 'window_expired');
        return ['action' => 'reject', 'http' => 410, 'response' => ['ok' => false, 'error' => 'WINDOW_EXPIRED', 'detail' => 'Lien expire (fenetre 5 min depassee). Redemandez-en un.']];
    }
    if ($count >= MAGIC_LINK_MAX_CONSUMES) {
        logMagicLinkAttempt($pdo, $token, $userId, $ua, $ip, 'too_many');
        return ['action' => 'reject', 'http' => 429, 'response' => ['ok' => false, 'error' => 'TOO_MANY_CONSUMES', 'detail' => 'Limite ' . MAGIC_LINK_MAX_CONSUMES . ' sessions atteinte. Redemandez un nouveau lien.']];
    }

    // Multi-consume autorise : increment count.
    try {
        $upd = $pdo->prepare("UPDATE users SET activation_consumes_count = activation_consumes_count + 1 WHERE id = ?");
        $upd->execute([$userId]);
    } catch (Throwable $e) {
        @error_log('[magic_link] increment count failed user_id=' . $userId . ' err=' . $e->getMessage());
    }
    logMagicLinkAttempt($pdo, $token, $userId, $ua, $ip, 'success');
    return ['action' => 'consume', 'user' => $user, 'is_first_consume' => false];
}

function magicLinkBotPage(): string {
    return '<!DOCTYPE html><html lang="fr"><head><meta charset="UTF-8"><title>Lien d\'acces Ocre Immo</title>'
        . '<meta name="robots" content="noindex,nofollow"></head>'
        . '<body style="font-family:-apple-system,sans-serif;background:#FCFAF6;color:#3a2e22;text-align:center;padding:60px 20px;">'
        . '<h1 style="font-family:Cormorant Garamond,serif;color:#8B5E3C;font-style:italic;">Lien d\'acces Ocre Immo</h1>'
        . '<p>Ce lien magique a ete genere. Cliquez dessus depuis votre navigateur pour vous authentifier.</p>'
        . '<p style="font-size:12px;color:#8B7F6E;font-style:italic;margin-top:24px;">(Page servie sans consommation du token : preserve la validite pour le clic humain.)</p>'
        . '</body></html>';
}
