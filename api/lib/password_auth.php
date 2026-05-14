<?php
// M/2026/05/14/71 — Phase B AUTH-PERENNE : helpers password Argon2id.
// Validation Mistral OWASP 2026 : memory_cost=65536 time_cost=4 threads=2.
// Liste embarquee top-10000 (offline, zero latence) pour breached check.

declare(strict_types=1);

const PASSWORD_AUTH_MIN_LENGTH = 12;
const PASSWORD_AUTH_MAX_LENGTH = 256;
const PASSWORD_AUTH_TOP10K_PATH = __DIR__ . '/data/top10k-passwords.txt';

/**
 * Hash un mot de passe avec Argon2id (params OWASP 2026 validated Mistral).
 */
function password_auth_hash(string $password): string {
    return password_hash($password, PASSWORD_ARGON2ID, [
        'memory_cost' => 65536, // 64 MiB
        'time_cost'   => 4,
        'threads'     => 2,
    ]);
}

/**
 * Verifie un mot de passe contre un hash stocke (constant-time interne password_verify).
 */
function password_auth_verify(string $password, string $hash): bool {
    return password_verify($password, $hash);
}

/**
 * Verifie si le hash doit etre rejoue (params Argon2id ont evolue).
 */
function password_auth_needs_rehash(string $hash): bool {
    return password_needs_rehash($hash, PASSWORD_ARGON2ID, [
        'memory_cost' => 65536, 'time_cost' => 4, 'threads' => 2,
    ]);
}

/**
 * Valide la force d'un mot de passe.
 * Retourne null si OK, ou string error si invalide.
 */
function password_auth_validate_strength(string $password): ?string {
    $len = strlen($password);
    if ($len < PASSWORD_AUTH_MIN_LENGTH) {
        return 'Mot de passe trop court (' . PASSWORD_AUTH_MIN_LENGTH . ' caracteres minimum)';
    }
    if ($len > PASSWORD_AUTH_MAX_LENGTH) {
        return 'Mot de passe trop long';
    }
    if (password_auth_is_breached_offline($password)) {
        return 'Ce mot de passe figure dans une liste publique de mots de passe compromis. Choisis-en un autre.';
    }
    return null;
}

/**
 * Verifie si le mot de passe est dans la liste embarquee top-10000.
 * Comparaison case-insensitive (les listes pro-2026 sont normalisees lowercase).
 */
function password_auth_is_breached_offline(string $password): bool {
    static $set = null;
    if ($set === null) {
        $set = [];
        if (is_file(PASSWORD_AUTH_TOP10K_PATH)) {
            $f = fopen(PASSWORD_AUTH_TOP10K_PATH, 'r');
            if ($f) {
                while (($l = fgets($f)) !== false) {
                    $l = strtolower(trim($l));
                    if ($l !== '') $set[$l] = true;
                }
                fclose($f);
            }
        }
    }
    return isset($set[strtolower($password)]);
}

/**
 * Genere un token de reset password (32 bytes, hex).
 */
function password_auth_generate_token(): string {
    return bin2hex(random_bytes(32));
}

/**
 * Hash un token reset (SHA-256) pour stockage en DB. Le clair n'est JAMAIS en DB.
 */
function password_auth_hash_token(string $token): string {
    return hash('sha256', $token);
}

/**
 * Rate-limit table init (idempotent).
 */
function password_auth_rate_limit_init(PDO $pdo): void {
    $pdo->exec("CREATE TABLE IF NOT EXISTS auth_attempts (
        id INT AUTO_INCREMENT PRIMARY KEY,
        scope VARCHAR(32) NOT NULL,
        identifier VARCHAR(255) NOT NULL,
        ip VARCHAR(45) NOT NULL,
        ts DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        success TINYINT(1) NOT NULL DEFAULT 0,
        user_agent VARCHAR(500) DEFAULT NULL,
        INDEX idx_scope_id_ts (scope, identifier, ts),
        INDEX idx_ip_ts (ip, ts)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
}

/**
 * Verifie le rate-limit pour login : 5/min/email + 20/h/IP.
 * Retourne null si OK, ou string error si bloque.
 */
function password_auth_rate_check_login(PDO $pdo, string $email, string $ip): ?string {
    password_auth_rate_limit_init($pdo);
    $st = $pdo->prepare("SELECT COUNT(*) FROM auth_attempts WHERE scope='login' AND identifier=? AND ts > NOW() - INTERVAL 1 MINUTE AND success=0");
    $st->execute([$email]);
    if ((int)$st->fetchColumn() >= 5) return 'Trop de tentatives sur ce compte, attends 1 minute.';
    $st = $pdo->prepare("SELECT COUNT(*) FROM auth_attempts WHERE scope='login' AND ip=? AND ts > NOW() - INTERVAL 1 HOUR AND success=0");
    $st->execute([$ip]);
    if ((int)$st->fetchColumn() >= 20) return 'Trop de tentatives depuis ton reseau, attends 1 heure.';
    return null;
}

/**
 * Log une tentative.
 */
function password_auth_rate_log(PDO $pdo, string $scope, string $identifier, string $ip, bool $success, ?string $ua = null): void {
    password_auth_rate_limit_init($pdo);
    $pdo->prepare("INSERT INTO auth_attempts (scope, identifier, ip, success, user_agent) VALUES (?, ?, ?, ?, ?)")
        ->execute([$scope, $identifier, $ip, $success ? 1 : 0, $ua !== null ? substr($ua, 0, 500) : null]);
}
