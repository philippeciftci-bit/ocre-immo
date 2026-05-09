<?php
// M97 — Helper JWT HS256 minimaliste, pas de lib externe.
// Sécurité : algo verrouillé HS256, refus alg:none, refus alg switching.
// Validation exp + iat + sub + jti obligatoires.

const JWT_ALG = 'HS256';
const JWT_TTL_SECONDS = 3600;       // 1h
const JWT_LEEWAY = 5;               // 5s clock skew

function jwt_secret(): string {
    static $secret = null;
    if ($secret !== null) return $secret;
    $path = '/root/.secrets/ocre_jwt_secret';
    if (!is_readable($path)) {
        http_response_code(500);
        die('JWT secret unreadable');
    }
    $secret = trim(file_get_contents($path));
    if (strlen($secret) < 32) {
        http_response_code(500);
        die('JWT secret too short');
    }
    return $secret;
}

function jwt_b64url_encode(string $data): string {
    return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
}

function jwt_b64url_decode(string $data): string {
    $pad = strlen($data) % 4;
    if ($pad) $data .= str_repeat('=', 4 - $pad);
    return base64_decode(strtr($data, '-_', '+/'));
}

function jwt_uuid_v4(): string {
    $b = random_bytes(16);
    $b[6] = chr((ord($b[6]) & 0x0f) | 0x40);
    $b[8] = chr((ord($b[8]) & 0x3f) | 0x80);
    return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($b), 4));
}

function jwt_encode(int $sub, int $ttl = JWT_TTL_SECONDS, ?string $jti = null): array {
    $now = time();
    $jti = $jti ?: jwt_uuid_v4();
    $header = ['alg' => JWT_ALG, 'typ' => 'JWT'];
    $payload = ['sub' => $sub, 'iat' => $now, 'exp' => $now + $ttl, 'jti' => $jti];
    $h = jwt_b64url_encode(json_encode($header, JSON_UNESCAPED_SLASHES));
    $p = jwt_b64url_encode(json_encode($payload, JSON_UNESCAPED_SLASHES));
    $sig = hash_hmac('sha256', "$h.$p", jwt_secret(), true);
    $s = jwt_b64url_encode($sig);
    return ['token' => "$h.$p.$s", 'jti' => $jti, 'exp' => $now + $ttl, 'iat' => $now];
}

// Decode + validate. $check_exp=false pour logout (lecture sig only).
// Retourne ['ok'=>bool, 'claims'=>array|null, 'error'=>string|null]
function jwt_decode(string $token, bool $check_exp = true): array {
    if (!is_string($token) || substr_count($token, '.') !== 2) {
        return ['ok' => false, 'claims' => null, 'error' => 'malformed'];
    }
    [$h, $p, $s] = explode('.', $token);

    $header = json_decode(jwt_b64url_decode($h), true);
    if (!is_array($header) || ($header['alg'] ?? null) !== JWT_ALG) {
        return ['ok' => false, 'claims' => null, 'error' => 'alg_invalid'];
    }
    $payload = json_decode(jwt_b64url_decode($p), true);
    if (!is_array($payload)) {
        return ['ok' => false, 'claims' => null, 'error' => 'payload_invalid'];
    }
    $sig = jwt_b64url_decode($s);
    $expected = hash_hmac('sha256', "$h.$p", jwt_secret(), true);
    if (!hash_equals($expected, $sig)) {
        return ['ok' => false, 'claims' => null, 'error' => 'signature_invalid'];
    }
    foreach (['sub', 'iat', 'exp', 'jti'] as $k) {
        if (!isset($payload[$k])) return ['ok' => false, 'claims' => null, 'error' => "missing_$k"];
    }
    if ($check_exp) {
        $now = time();
        if ($payload['exp'] < $now - JWT_LEEWAY) {
            return ['ok' => false, 'claims' => $payload, 'error' => 'expired'];
        }
        if ($payload['iat'] > $now + JWT_LEEWAY) {
            return ['ok' => false, 'claims' => $payload, 'error' => 'iat_future'];
        }
    }
    return ['ok' => true, 'claims' => $payload, 'error' => null];
}
