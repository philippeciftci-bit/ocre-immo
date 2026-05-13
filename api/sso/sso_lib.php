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
