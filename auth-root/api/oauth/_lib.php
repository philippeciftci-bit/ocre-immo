<?php
// M_OCRE_AGENT_SIGNUP_V1 — Helper commun OAuth (Google/Apple/Facebook).
// Mode prod si /root/.secrets/<provider>-oauth.env present, sinon mock auto-redirect callback avec fake user.

require_once __DIR__ . '/../../lib/auth_db.php';
require_once __DIR__ . '/../../lib/jwt.php';

function oauth_load_env(string $provider): array {
    $file = '/root/.secrets/' . $provider . '-oauth.env';
    if (!is_file($file)) return ['_mock' => true];
    $env = [];
    foreach (file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        if (strpos($line, '#') === 0) continue;
        if (strpos($line, '=') === false) continue;
        [$k, $v] = array_map('trim', explode('=', $line, 2));
        $v = trim($v, '"\'');
        $env[$k] = $v;
    }
    $env['_mock'] = false;
    return $env;
}

function oauth_redirect_uri(string $provider): string {
    return 'https://auth.ocre.immo/api/oauth/' . $provider . '/callback.php';
}

function oauth_state_set(string $provider): string {
    $s = bin2hex(random_bytes(16));
    setcookie('oauth_state_' . $provider, $s, [
        'expires' => time() + 600, 'path' => '/', 'domain' => '.ocre.immo',
        'secure' => true, 'httponly' => true, 'samesite' => 'Lax',
    ]);
    return $s;
}

function oauth_state_check(string $provider, string $received): bool {
    $stored = $_COOKIE['oauth_state_' . $provider] ?? '';
    return $stored !== '' && hash_equals($stored, $received);
}

function oauth_ensure_extended_schema(): void {
    static $done = false;
    if ($done) return;
    try {
        $cols = [
            "ALTER TABLE auth_users ADD COLUMN oauth_provider VARCHAR(20) NULL",
            "ALTER TABLE auth_users ADD COLUMN oauth_provider_user_id VARCHAR(128) NULL",
            "ALTER TABLE auth_users ADD COLUMN first_name VARCHAR(64) NULL",
            "ALTER TABLE auth_users ADD COLUMN last_name VARCHAR(64) NULL",
            "ALTER TABLE auth_users ADD COLUMN societe VARCHAR(128) NULL",
            "ALTER TABLE auth_users ADD COLUMN phone_e164 VARCHAR(20) NULL",
            "ALTER TABLE auth_users ADD COLUMN phone_country_code CHAR(2) NULL",
            "ALTER TABLE auth_users ADD COLUMN cgu_accepted_at DATETIME NULL",
        ];
        foreach ($cols as $sql) { try { auth_pdo()->exec($sql); } catch (Throwable $e) {} }
        try { auth_pdo()->exec("CREATE INDEX idx_oauth ON auth_users (oauth_provider, oauth_provider_user_id)"); } catch (Throwable $e) {}
    } catch (Throwable $e) {}
    $done = true;
}

function oauth_upsert_user(string $provider, string $providerUserId, string $email, string $firstName = '', string $lastName = ''): int {
    oauth_ensure_extended_schema();
    $pdo = auth_pdo();
    // Lookup existant via provider + provider_user_id ou email
    $st = $pdo->prepare("SELECT id FROM auth_users WHERE (oauth_provider = ? AND oauth_provider_user_id = ?) OR email = ? LIMIT 1");
    $st->execute([$provider, $providerUserId, $email]);
    $uid = (int) $st->fetchColumn();
    if ($uid > 0) {
        $up = $pdo->prepare("UPDATE auth_users SET oauth_provider=?, oauth_provider_user_id=?, first_name=COALESCE(NULLIF(?, ''), first_name), last_name=COALESCE(NULLIF(?, ''), last_name) WHERE id=?");
        $up->execute([$provider, $providerUserId, $firstName, $lastName, $uid]);
        return $uid;
    }
    $ins = $pdo->prepare("INSERT INTO auth_users (email, oauth_provider, oauth_provider_user_id, first_name, last_name, created_at) VALUES (?, ?, ?, ?, ?, NOW())");
    $ins->execute([$email, $provider, $providerUserId, $firstName, $lastName]);
    return (int) $pdo->lastInsertId();
}

function oauth_complete_login(int $userId, string $email): void {
    // JWT 30j + refresh 30j + cookies + redirect app.ocre.immo
    $jwtPayload = ['sub' => $userId, 'email' => $email, 'iat' => time(), 'exp' => time() + 30 * 86400];
    $jwt = jwt_encode($jwtPayload);
    $refresh = bin2hex(random_bytes(32));
    // Save refresh
    try {
        auth_pdo()->prepare("INSERT INTO auth_refresh_tokens (user_id, token, expires_at) VALUES (?, ?, DATE_ADD(NOW(), INTERVAL 30 DAY))")->execute([$userId, hash('sha256', $refresh)]);
    } catch (Throwable $e) { /* table may differ, swallow */ }
    auth_set_cookies($jwt, $refresh);
    header('Location: https://app.ocre.immo/?_oauth_login=1');
    exit;
}
