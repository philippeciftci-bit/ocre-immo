<?php
// M/2026/05/09/71 — Helper sessions persistantes 30j cookie HttpOnly Secure SameSite Lax.
// Source de verite: ocre_meta.user_sessions (M71 SQL).
//
// Fonctions exposees :
//   createSession(int $userId, string $userAgent, string $ip): string  - genere token + INSERT, retourne token
//   validateSessionToken(string $token): ?array                         - retourne {user_id,email,slug,...} ou null
//   revokeSession(string $token): void                                  - UPDATE revoked_at
//   revokeAllSessions(int $userId): int                                 - UPDATE batch, retourne count
//   setSessionCookie(string $token): void                               - pose Set-Cookie 30j HttpOnly Secure Lax Domain=.ocre.immo
//   clearSessionCookie(): void                                          - pose Set-Cookie Max-Age=0
//   getCurrentUserFromCookie(): ?array                                  - shortcut: lit cookie ocre_session puis validateSessionToken
//
// Securite :
//   - session_token = bin2hex(random_bytes(32)) = 256 bits crypto-strong
//   - Sliding expiration : validateSessionToken() RENOUVELLE expires_at = NOW + 30 DAYS
//   - HttpOnly = true (anti-XSS)
//   - Secure = true (HTTPS only)
//   - SameSite = Lax (anti-CSRF tout en autorisant le click magic link)
//   - Domain = .ocre.immo (partage cross-subdomain)
//   - Logs : token jamais loggue en clair, juste les 8 premiers chars + '...'

require_once __DIR__ . '/db.php';

const OCRE_SESSION_COOKIE_NAME = 'ocre_session';
const OCRE_SESSION_TTL_SECONDS = 30 * 24 * 3600; // 30 jours

function _session_pdo(): PDO {
    static $pdo = null;
    if ($pdo === null) {
        $pdo = new PDO(
            'mysql:host=' . DB_HOST . ';dbname=ocre_meta;charset=utf8mb4',
            DB_USER, DB_PASS,
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
        );
    }
    return $pdo;
}

function _session_log(string $tag, string $msg, ?string $token = null): void {
    $tokenPreview = $token ? substr($token, 0, 8) . '...' : '';
    @error_log('[ocre_session] ' . $tag . ' ' . $msg . ($tokenPreview ? ' token=' . $tokenPreview : ''));
}

function createSession(int $userId, string $userAgent, string $ip): string {
    $token = bin2hex(random_bytes(32));
    $pdo = _session_pdo();
    $st = $pdo->prepare(
        "INSERT INTO user_sessions (user_id, session_token, user_agent, ip_address, expires_at)
         VALUES (?, ?, ?, ?, DATE_ADD(NOW(), INTERVAL 30 DAY))"
    );
    $st->execute([$userId, $token, substr($userAgent, 0, 500), substr($ip, 0, 45)]);
    _session_log('CREATE', 'user_id=' . $userId, $token);
    return $token;
}

function validateSessionToken(string $token): ?array {
    if ($token === '' || !preg_match('/^[a-f0-9]{64}$/', $token)) return null;
    $pdo = _session_pdo();
    $st = $pdo->prepare(
        "SELECT s.id AS session_id, s.user_id, s.expires_at, s.revoked_at,
                u.email, u.slug, u.prenom, u.nom, u.role, u.country_code, u.archived_at
         FROM user_sessions s
         INNER JOIN users u ON u.id = s.user_id
         WHERE s.session_token = ?
         LIMIT 1"
    );
    $st->execute([$token]);
    $row = $st->fetch();
    if (!$row) return null;
    if ($row['revoked_at'] !== null) { _session_log('REJECT', 'revoked', $token); return null; }
    if (strtotime($row['expires_at']) < time()) { _session_log('REJECT', 'expired', $token); return null; }
    if ($row['archived_at'] !== null) { _session_log('REJECT', 'user_archived', $token); return null; }
    // Sliding expiration : RENEW expires_at + last_seen_at (ON UPDATE auto par DB).
    try {
        $upd = $pdo->prepare("UPDATE user_sessions SET expires_at = DATE_ADD(NOW(), INTERVAL 30 DAY) WHERE id = ?");
        $upd->execute([(int)$row['session_id']]);
    } catch (Throwable $e) {
        _session_log('WARN', 'renew_failed: ' . $e->getMessage(), $token);
    }
    return [
        'session_id' => (int)$row['session_id'],
        'user_id' => (int)$row['user_id'],
        'email' => $row['email'],
        'slug' => $row['slug'],
        'prenom' => $row['prenom'],
        'nom' => $row['nom'],
        'role' => $row['role'],
        'country_code' => $row['country_code'],
    ];
}

function revokeSession(string $token): void {
    if ($token === '' || !preg_match('/^[a-f0-9]{64}$/', $token)) return;
    $pdo = _session_pdo();
    $st = $pdo->prepare("UPDATE user_sessions SET revoked_at = NOW() WHERE session_token = ? AND revoked_at IS NULL");
    $st->execute([$token]);
    _session_log('REVOKE', 'one', $token);
}

function revokeAllSessions(int $userId): int {
    $pdo = _session_pdo();
    $st = $pdo->prepare("UPDATE user_sessions SET revoked_at = NOW() WHERE user_id = ? AND revoked_at IS NULL");
    $st->execute([$userId]);
    $n = $st->rowCount();
    _session_log('REVOKE_ALL', 'user_id=' . $userId . ' count=' . $n);
    return $n;
}

function setSessionCookie(string $token): void {
    // Domain = .ocre.immo pour partage cross-subdomain.
    $opts = [
        'expires' => time() + OCRE_SESSION_TTL_SECONDS,
        'path' => '/',
        'domain' => '.ocre.immo',
        'secure' => true,
        'httponly' => true,
        'samesite' => 'Lax',
    ];
    setcookie(OCRE_SESSION_COOKIE_NAME, $token, $opts);
}

function clearSessionCookie(): void {
    $opts = [
        'expires' => time() - 3600,
        'path' => '/',
        'domain' => '.ocre.immo',
        'secure' => true,
        'httponly' => true,
        'samesite' => 'Lax',
    ];
    setcookie(OCRE_SESSION_COOKIE_NAME, '', $opts);
}

function getCurrentUserFromCookie(): ?array {
    $token = $_COOKIE[OCRE_SESSION_COOKIE_NAME] ?? '';
    if ($token === '') return null;
    return validateSessionToken($token);
}

// M99 — Dual-mode SSO + legacy. Priorite 1 : cookie ocre_jwt (auth.ocre.immo).
// Priorite 2 : cookie ocre_session (M71 magic link tenant). Coexistence 30j prevue.
// Retour : meme structure que validateSessionToken + cle '_sso_source' = 'sso'|'legacy'.
function getCurrentUserDualMode(): ?array {
    if (file_exists(__DIR__ . '/sso_bridge.php')) {
        require_once __DIR__ . '/sso_bridge.php';
        $sso = getUserFromSsoCookie(true);
        if ($sso !== null) return $sso;
    }
    $legacy = getCurrentUserFromCookie();
    if ($legacy !== null) {
        $legacy['_sso_source'] = 'legacy';
        return $legacy;
    }
    return null;
}
