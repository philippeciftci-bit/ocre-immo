<?php
// M/2026/04/29/33 — AES-256-GCM encrypt/decrypt pour tokens OAuth Google Drive.
// Cle maitresse dans /root/.secrets/drive_tokens_key (32 bytes hex).
// Format chiffre : base64(iv 12 bytes || tag 16 bytes || ciphertext).

const DRIVE_TOKEN_KEY_PATH = '/root/.secrets/drive_tokens_key';

function _drive_token_key(): string {
    static $key = null;
    if ($key !== null) return $key;
    if (!file_exists(DRIVE_TOKEN_KEY_PATH)) {
        // Auto-generate 32 bytes hex if absent (first run).
        $hex = bin2hex(random_bytes(32));
        @file_put_contents(DRIVE_TOKEN_KEY_PATH, $hex);
        @chmod(DRIVE_TOKEN_KEY_PATH, 0600);
    }
    $hex = trim((string) @file_get_contents(DRIVE_TOKEN_KEY_PATH));
    if (strlen($hex) !== 64) throw new RuntimeException('drive_tokens_key invalid (expected 64 hex chars)');
    $key = hex2bin($hex);
    return $key;
}

function drive_token_encrypt(string $plain): string {
    $key = _drive_token_key();
    $iv = random_bytes(12);
    $tag = '';
    $cipher = openssl_encrypt($plain, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag);
    if ($cipher === false) throw new RuntimeException('encrypt failed');
    return base64_encode($iv . $tag . $cipher);
}

function drive_token_decrypt(string $blob): string {
    $key = _drive_token_key();
    $raw = base64_decode($blob, true);
    if ($raw === false || strlen($raw) < 28) throw new RuntimeException('blob invalid');
    $iv = substr($raw, 0, 12);
    $tag = substr($raw, 12, 16);
    $cipher = substr($raw, 28);
    $plain = openssl_decrypt($cipher, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag);
    if ($plain === false) throw new RuntimeException('decrypt failed (wrong key or tampered)');
    return $plain;
}

// CSRF state HMAC-SHA256 signed (10 min expiry). Cle dans /root/.secrets/drive_oauth_state_key.
function drive_oauth_state_create(int $uid): string {
    $keyPath = '/root/.secrets/drive_oauth_state_key';
    if (!file_exists($keyPath)) {
        @file_put_contents($keyPath, bin2hex(random_bytes(32)));
        @chmod($keyPath, 0600);
    }
    $key = trim((string) @file_get_contents($keyPath));
    $exp = time() + 600;
    $payload = $uid . '.' . $exp . '.' . bin2hex(random_bytes(8));
    $sig = hash_hmac('sha256', $payload, $key);
    return base64_encode($payload . '.' . $sig);
}

function drive_oauth_state_verify(string $state, int $uid): bool {
    $keyPath = '/root/.secrets/drive_oauth_state_key';
    $key = trim((string) @file_get_contents($keyPath));
    if (!$key) return false;
    $raw = base64_decode($state, true);
    if (!$raw) return false;
    $parts = explode('.', $raw);
    if (count($parts) !== 4) return false;
    [$puid, $pexp, $nonce, $sig] = $parts;
    $expected = hash_hmac('sha256', "$puid.$pexp.$nonce", $key);
    if (!hash_equals($expected, $sig)) return false;
    if ((int) $puid !== $uid) return false;
    if ((int) $pexp < time()) return false;
    return true;
}
