<?php
// M97 — Helper DB ocre_meta dédié au service auth. Ne dépend pas de /opt/ocre-app.

function auth_env(): array {
    static $env = null;
    if ($env !== null) return $env;
    $path = '/root/.secrets/ocre-db.env';
    if (!is_readable($path)) {
        http_response_code(500);
        die('DB env unreadable');
    }
    $env = parse_ini_file($path, false, INI_SCANNER_RAW);
    return $env;
}

function auth_db(): PDO {
    static $pdo = null;
    if ($pdo !== null) return $pdo;
    $env = auth_env();
    $host = $env['DB_HOST'] ?? '127.0.0.1';
    $user = $env['DB_USER'] ?? 'ocre_app';
    $pass = $env['DB_PASS'] ?? '';
    $dsn = "mysql:host=$host;dbname=ocre_meta;charset=utf8mb4";
    $pdo = new PDO($dsn, $user, $pass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);
    return $pdo;
}

function auth_ensure_schema(): void {
    $db = auth_db();
    $db->exec("CREATE TABLE IF NOT EXISTS auth_users (
        id INT AUTO_INCREMENT PRIMARY KEY,
        email VARCHAR(255) NOT NULL UNIQUE,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        last_login_at DATETIME NULL,
        status ENUM('active','suspended') NOT NULL DEFAULT 'active',
        INDEX idx_email (email)
    ) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");

    // M98 — colonnes profil ajoutées rétro-actif (idempotent).
    foreach ([
        "ALTER TABLE auth_users ADD COLUMN first_name VARCHAR(64) NULL",
        "ALTER TABLE auth_users ADD COLUMN last_name VARCHAR(64) NULL",
    ] as $sql) {
        try { $db->exec($sql); } catch (Exception $e) { /* deja present */ }
    }

    $db->exec("CREATE TABLE IF NOT EXISTS auth_magic_tokens (
        id INT AUTO_INCREMENT PRIMARY KEY,
        user_id INT NOT NULL,
        token VARCHAR(64) NOT NULL UNIQUE,
        expires_at DATETIME NOT NULL,
        used_at DATETIME NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        ip VARCHAR(45) NULL,
        INDEX idx_token (token),
        INDEX idx_user (user_id),
        INDEX idx_expires (expires_at)
    ) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");

    $db->exec("CREATE TABLE IF NOT EXISTS auth_sessions (
        id INT AUTO_INCREMENT PRIMARY KEY,
        user_id INT NOT NULL,
        jti VARCHAR(36) NOT NULL UNIQUE,
        refresh_token VARCHAR(64) NOT NULL UNIQUE,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        expires_at DATETIME NOT NULL,
        revoked_at DATETIME NULL,
        user_agent VARCHAR(256) NULL,
        ip VARCHAR(45) NULL,
        INDEX idx_jti (jti),
        INDEX idx_refresh (refresh_token),
        INDEX idx_user (user_id),
        INDEX idx_expires (expires_at)
    ) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");

    $db->exec("CREATE TABLE IF NOT EXISTS auth_rate_limit (
        id INT AUTO_INCREMENT PRIMARY KEY,
        ip VARCHAR(45) NOT NULL,
        action VARCHAR(32) NOT NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_ip_action_time (ip, action, created_at)
    ) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
}

function auth_get_or_create_user(string $email): int {
    $db = auth_db();
    $st = $db->prepare("SELECT id FROM auth_users WHERE email = ? LIMIT 1");
    $st->execute([$email]);
    $row = $st->fetch();
    if ($row) return (int) $row['id'];
    $ins = $db->prepare("INSERT INTO auth_users (email) VALUES (?)");
    $ins->execute([$email]);
    return (int) $db->lastInsertId();
}

function auth_client_ip(): string {
    $h = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? '';
    if ($h) return trim(explode(',', $h)[0]);
    return $_SERVER['REMOTE_ADDR'] ?? '';
}

function auth_rate_limit_check(string $ip, string $action, int $max, int $windowSec): bool {
    $db = auth_db();
    $st = $db->prepare(
        "SELECT COUNT(*) AS c FROM auth_rate_limit
         WHERE ip = ? AND action = ? AND created_at > DATE_SUB(NOW(), INTERVAL ? SECOND)"
    );
    $st->execute([$ip, $action, $windowSec]);
    $c = (int) $st->fetch()['c'];
    return $c < $max;
}

function auth_rate_limit_record(string $ip, string $action): void {
    $db = auth_db();
    $st = $db->prepare("INSERT INTO auth_rate_limit (ip, action) VALUES (?, ?)");
    $st->execute([$ip, $action]);
}

function auth_send_json($data, int $code = 200): void {
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

// M98 — CORS pour cross-subdomain app.ocre.immo / agent.ocre.immo.
// Whitelist stricte d'origines, credentials autorisés (cookies envoyés).
function auth_cors_allow(): void {
    $allowed = [
        'https://app.ocre.immo',
        'https://agent.ocre.immo',
        'https://scan.ocre.immo',
        'https://book.ocre.immo',
        'https://demande.ocre.immo',
        // M_OAUTH_BOUCLE_FIX — vitrine apex pour bandeau connecté fetch /api/me.php
        'https://ocre.immo',
        'https://www.ocre.immo',
    ];
    $origin = $_SERVER['HTTP_ORIGIN'] ?? '';
    // Aussi : tenant slugs <slug>.ocre.immo (Oi Agent multi-tenant M99)
    $tenantOrigin = preg_match('#^https://[a-z0-9][a-z0-9-]*\.ocre\.immo$#', $origin);
    if (in_array($origin, $allowed, true) || $tenantOrigin) {
        header('Access-Control-Allow-Origin: ' . $origin);
        header('Access-Control-Allow-Credentials: true');
        header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
        header('Access-Control-Allow-Headers: Content-Type');
        header('Vary: Origin');
    }
    if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') {
        http_response_code(204);
        exit;
    }
}

function auth_set_cookies(string $jwt, string $refresh): void {
    // M_OCRE_AGENT_SIGNUP_V1 — JWT cookie 30j (was 1h) cohérent UX Notion/Linear/Booking.
    $jwtOpts = [
        'expires' => time() + 30 * 86400,
        'path' => '/',
        'domain' => '.ocre.immo',
        'secure' => true,
        'httponly' => true,
        'samesite' => 'Lax',
    ];
    $refOpts = [
        'expires' => time() + 30 * 86400,
        'path' => '/',
        'domain' => '.ocre.immo',
        'secure' => true,
        'httponly' => true,
        'samesite' => 'Lax',
    ];
    setcookie('ocre_jwt', $jwt, $jwtOpts);
    setcookie('ocre_refresh', $refresh, $refOpts);
}

function auth_clear_cookies(): void {
    $opts = [
        'expires' => 1,
        'path' => '/',
        'domain' => '.ocre.immo',
        'secure' => true,
        'httponly' => true,
        'samesite' => 'Lax',
    ];
    setcookie('ocre_jwt', '', $opts);
    setcookie('ocre_refresh', '', $opts);
}
