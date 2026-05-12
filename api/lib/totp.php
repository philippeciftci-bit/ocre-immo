<?php
// M/2026/05/13/17 — TOTP RFC 6238 pure PHP (HMAC-SHA1, periode 30s, drift +/-1 fenetre).
// Pas de dependance externe. Compatible Google Authenticator / Authy / 1Password / Microsoft Authenticator.

function totp_random_secret(int $bytes = 20): string {
    return totp_base32_encode(random_bytes($bytes));
}

function totp_base32_encode(string $data): string {
    $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
    $out = ''; $v = 0; $vBits = 0;
    for ($i = 0; $i < strlen($data); $i++) {
        $v = ($v << 8) | ord($data[$i]); $vBits += 8;
        while ($vBits >= 5) { $out .= $alphabet[($v >> ($vBits - 5)) & 0x1F]; $vBits -= 5; }
    }
    if ($vBits > 0) $out .= $alphabet[($v << (5 - $vBits)) & 0x1F];
    return $out;
}

function totp_base32_decode(string $s): string {
    $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
    $s = strtoupper(preg_replace('/[^A-Z2-7]/', '', $s));
    $out = ''; $v = 0; $vBits = 0;
    for ($i = 0; $i < strlen($s); $i++) {
        $pos = strpos($alphabet, $s[$i]); if ($pos === false) continue;
        $v = ($v << 5) | $pos; $vBits += 5;
        if ($vBits >= 8) { $out .= chr(($v >> ($vBits - 8)) & 0xFF); $vBits -= 8; }
    }
    return $out;
}

function totp_code(string $secret, int $timestamp = 0, int $period = 30, int $digits = 6): string {
    if (!$timestamp) $timestamp = time();
    $counter = intdiv($timestamp, $period);
    // 8 bytes big-endian counter
    $bin = '';
    for ($i = 7; $i >= 0; $i--) { $bin = chr($counter & 0xFF) . $bin; $counter >>= 8; }
    $key = totp_base32_decode($secret);
    $hash = hash_hmac('sha1', $bin, $key, true);
    $offset = ord($hash[19]) & 0x0F;
    $code = ((ord($hash[$offset]) & 0x7F) << 24)
          | ((ord($hash[$offset + 1]) & 0xFF) << 16)
          | ((ord($hash[$offset + 2]) & 0xFF) << 8)
          | (ord($hash[$offset + 3]) & 0xFF);
    $code = $code % (10 ** $digits);
    return str_pad((string)$code, $digits, '0', STR_PAD_LEFT);
}

// Verifie code utilisateur avec drift +/- 1 fenetre. Retourne window match (ou false).
function totp_verify(string $secret, string $userCode, ?int $lastUsedWindow = null): int|false {
    $userCode = preg_replace('/\s+/', '', $userCode);
    $now = time();
    $period = 30;
    $currentWindow = intdiv($now, $period);
    for ($delta = -1; $delta <= 1; $delta++) {
        $window = $currentWindow + $delta;
        // Anti-replay : refuse window deja utilise.
        if ($lastUsedWindow !== null && $window <= $lastUsedWindow) continue;
        $expected = totp_code($secret, $window * $period);
        if (hash_equals($expected, $userCode)) return $window;
    }
    return false;
}

function totp_otpauth_url(string $secret, string $accountName, string $issuer = 'Oi Agent'): string {
    return 'otpauth://totp/' . rawurlencode($issuer . ':' . $accountName) .
        '?secret=' . $secret . '&issuer=' . rawurlencode($issuer) . '&algorithm=SHA1&digits=6&period=30';
}

// QR code data URI : fallback simple via API qr-server (self-hosted ou externe). Pour eviter dependance externe,
// on retourne l URL otpauth en clair et le client genere le QR via lib JS (qrcode.js deja eventuellement dispo).
// Le client peut aussi afficher juste le texte secret pour saisie manuelle dans l app authenticator.
function totp_qr_payload(string $otpauthUrl): string {
    return $otpauthUrl; // le frontend genere le QR via lib JS
}
