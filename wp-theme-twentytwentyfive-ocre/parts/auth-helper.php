<?php
// M_OAUTH_BOUCLE_FIX — Helper auth side-vitrine
// Decode JWT cookie ocre_jwt (HS256 signe par auth.ocre.immo) + retourne payload claims si valide non expire
// Pas de verif signature cote vitrine (perf + secret partage couteux), juste exp check
// Si vraie verif requise : appel /api/me sur auth.ocre.immo (cross-domain cookie)

if (!function_exists('ocre_decode_jwt_payload')) {
    function ocre_decode_jwt_payload(string $token): ?array {
        $parts = explode('.', $token);
        if (count($parts) !== 3) return null;
        $payloadB64 = strtr($parts[1], '-_', '+/');
        $payloadB64 .= str_repeat('=', (4 - strlen($payloadB64) % 4) % 4);
        $payloadJson = base64_decode($payloadB64);
        if (!$payloadJson) return null;
        $payload = json_decode($payloadJson, true);
        if (!is_array($payload)) return null;
        if (isset($payload['exp']) && $payload['exp'] < time()) return null;
        return $payload;
    }
}

if (!function_exists('ocre_is_logged_in')) {
    function ocre_is_logged_in(): bool {
        if (!isset($_COOKIE['ocre_jwt'])) return false;
        return ocre_decode_jwt_payload($_COOKIE['ocre_jwt']) !== null;
    }
}

if (!function_exists('ocre_user_first_name')) {
    function ocre_user_first_name(): string {
        if (!isset($_COOKIE['ocre_jwt'])) return '';
        $p = ocre_decode_jwt_payload($_COOKIE['ocre_jwt']);
        if (!$p) return '';
        // first_name pas dans JWT (claims minimaux sub/iat/exp/jti) → fetch via auth.ocre.immo/api/me asynchrone cote JS
        // Pour l'instant retourne vide, le bandeau JS recupere via fetch credentials include
        return (string)($p['first_name'] ?? $p['name'] ?? '');
    }
}
