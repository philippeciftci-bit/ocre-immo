<?php
// M/2026/05/13/18 — SSO lib : signing HMAC-SHA256 + cookie payload encode/decode.
function sso_signing_key(): string {
    static $key = null;
    if ($key !== null) return $key;
    $path = '/etc/ocre/sso_signing_key';
    if (!is_readable($path)) throw new RuntimeException('SSO signing key missing');
    $key = trim((string)file_get_contents($path));
    if (!$key) throw new RuntimeException('SSO signing key empty');
    return $key;
}
function sso_sign(string $payload): string {
    return hash_hmac('sha256', $payload, sso_signing_key());
}
function sso_cookie_encode(array $data): string {
    $json = json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    $payload = rtrim(strtr(base64_encode($json), '+/', '-_'), '=');
    $sig = sso_sign($payload);
    return $payload . '.' . $sig;
}
function sso_cookie_decode(string $cookie): ?array {
    $parts = explode('.', $cookie, 2);
    if (count($parts) !== 2) return null;
    [$payload, $sig] = $parts;
    if (!hash_equals(sso_sign($payload), $sig)) return null; // tampered
    $json = base64_decode(strtr($payload, '-_', '+/'));
    if (!$json) return null;
    $data = json_decode($json, true);
    if (!is_array($data) || empty($data['exp']) || $data['exp'] < time()) return null;
    return $data;
}
function sso_set_cookie(array $data, int $ttlSec = 7 * 86400): void {
    $data['exp'] = time() + $ttlSec;
    $cookie = sso_cookie_encode($data);
    setcookie('ocre_sso', $cookie, [
        'expires' => time() + $ttlSec,
        'path' => '/',
        'domain' => '.ocre.immo',
        'secure' => true,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
}
function sso_clear_cookie(): void {
    setcookie('ocre_sso', '', [
        'expires' => time() - 3600,
        'path' => '/',
        'domain' => '.ocre.immo',
        'secure' => true,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
}
function sso_get_cookie(): ?array {
    $raw = $_COOKIE['ocre_sso'] ?? '';
    if (!$raw) return null;
    return sso_cookie_decode($raw);
}

// M/2026/05/13/26 — Helper greffe activation : INSERT sso_sessions + lazy populate
// user_tenants + setcookie HMAC en un appel. PDO ocre_meta fourni par l'appelant.
function sso_emit_cookie(PDO $meta, int $userId, string $email, string $userSlug, string $ip = '', string $ua = '', int $ttlSec = 7 * 86400): string {
    $sessionToken = bin2hex(random_bytes(32));
    $meta->prepare(
        "INSERT INTO sso_sessions (session_token, user_id, ip_address, user_agent, expires_at, last_seen_at)
         VALUES (?,?,?,?, DATE_ADD(NOW(), INTERVAL 7 DAY), NOW())"
    )->execute([$sessionToken, $userId, $ip, substr($ua, 0, 500)]);
    if ($userSlug !== '' && preg_match('/^[a-z0-9-]+$/', $userSlug)) {
        $meta->prepare("INSERT IGNORE INTO user_tenants (user_id, tenant_slug, role) VALUES (?,?,?)")
            ->execute([$userId, $userSlug, 'owner']);
    }
    $tSt = $meta->prepare("SELECT tenant_slug FROM user_tenants WHERE user_id = ? ORDER BY tenant_slug");
    $tSt->execute([$userId]);
    $tenants = array_column($tSt->fetchAll(PDO::FETCH_ASSOC), 'tenant_slug');
    sso_set_cookie([
        'session_token' => $sessionToken,
        'user_id' => $userId,
        'email' => $email,
        'tenants' => $tenants,
        'current_tenant' => $tenants[0] ?? ($userSlug !== '' ? $userSlug : null),
        'iat' => time(),
    ], $ttlSec);
    return $sessionToken;
}
