<?php
// V20 etape 0bis — config DB lue depuis /root/.secrets/ocre-db.env (mode 600).
// Multi-tenant : DB_NAME determinee dynamiquement par le router PHP a partir
// du tenant slug (X-Tenant-Slug header). DB_NAME ci-dessous = fallback legacy.

// Lecture .env app-local (gitignored). Cle propagee depuis /root/.secrets/ocre-db.env.
$envFile = __DIR__ . '/../.env';
if (is_readable($envFile)) {
    $env = parse_ini_file($envFile, false, INI_SCANNER_RAW);
    define('DB_HOST', $env['DB_HOST'] ?? '127.0.0.1');
    define('DB_USER', $env['DB_USER'] ?? 'ocre_app');
    define('DB_PASS', $env['DB_PASS'] ?? '');
} else {
    // Fallback emergency hardcoded — DOIT etre vide en prod.
    define('DB_HOST', '127.0.0.1');
    define('DB_USER', 'ocre_app');
    define('DB_PASS', '');
}
// V20 multi-tenant + M84 : DB_NAME = ocre_wsp_<slug>. Une seule DB par tenant
// (suppression mode test/agent). Slug extrait du header X-Tenant-Slug nginx
// ou du sous-domaine. Fallback ocre_wsp_ozkan si slug vide ou invalide.
$_v20_slug = preg_replace('/[^a-z0-9_-]/', '', strtolower($_SERVER['HTTP_X_TENANT_SLUG'] ?? ''));
if (!$_v20_slug && !empty($_SERVER['HTTP_HOST']) && preg_match('/^([a-z0-9][a-z0-9-]*)\.ocre\.immo$/', $_SERVER['HTTP_HOST'], $_v20_m)) {
    $_v20_slug = $_v20_m[1];
}
if (!$_v20_slug) $_v20_slug = 'ozkan';
define('DB_NAME', 'ocre_wsp_' . $_v20_slug);
define('DB_CHARSET','utf8mb4');

define('ADMIN_CODE','OCRE-ADMIN-2026');
define('SESSION_DURATION',86400*30);
define('BCRYPT_COST',10);

define('APP_URL','https://app.ocre.immo/');
define('API_URL','https://app.ocre.immo/api/');

define('DEBUG',false);
define('LOG_ERRORS',true);

define('ALLOWED_ORIGINS',['https://app.ocre.immo','https://ocre.immo','https://www.ocre.immo']);

// M/2026/05/14/2 — Schema contract.
// Version cible attendue dans `_schema_migrations` du wsp. Bumper a chaque
// nouvelle migration ajoutee dans /opt/ocre-app/migrations/versions/.
// db.php verifie la version a chaque connexion wsp et retourne 503
// SCHEMA_DRIFT si la version courante est inferieure.
define('SCHEMA_VERSION_REQUIRED', 'V012');

// Slug courant (deduit ci-dessus) - expose pour d'autres modules (correlation, monitoring).
define('OCRE_WSP_SLUG', $_v20_slug);

// M/2026/05/14/2 — Correlation ID end-to-end.
// Capte X-Request-Id du front (ou genere) + ecrit ligne JSON /var/log/ocre/requests.log
// au shutdown. error_log() prefixe avec [req=...] pour grep facile.
if (!defined('OCRE_REQUEST_ID')) {
    $_rid = $_SERVER['HTTP_X_REQUEST_ID'] ?? '';
    if (!$_rid || !preg_match('/^[a-zA-Z0-9._:-]{6,80}$/', $_rid)) {
        $_rid = 'srv-' . dechex((int)(microtime(true) * 1000)) . '-' . bin2hex(random_bytes(4));
    }
    define('OCRE_REQUEST_ID', $_rid);
    define('OCRE_REQUEST_START', microtime(true));
    @header('X-Request-Id: ' . OCRE_REQUEST_ID);
    @ini_set('error_prepend_string', '[req=' . OCRE_REQUEST_ID . '] ');
}

function _ocreRequestLogger() {
    if (!defined('OCRE_REQUEST_ID')) return;
    $duration_ms = (int) round((microtime(true) - OCRE_REQUEST_START) * 1000);
    $status = http_response_code();
    $entry = [
        'ts' => date('c'),
        'method' => $_SERVER['REQUEST_METHOD'] ?? '',
        'url' => substr($_SERVER['REQUEST_URI'] ?? '', 0, 500),
        'wsp' => defined('OCRE_WSP_SLUG') ? OCRE_WSP_SLUG : '',
        'user_id' => $GLOBALS['_ocre_current_user_id'] ?? 0,
        'request_id' => OCRE_REQUEST_ID,
        'status' => is_int($status) ? $status : 0,
        'duration_ms' => $duration_ms,
        'remote_ip' => $_SERVER['REMOTE_ADDR'] ?? '',
        'host' => $_SERVER['HTTP_HOST'] ?? '',
    ];
    $err = error_get_last();
    if ($err && in_array($err['type'] ?? 0, [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
        $entry['php_error'] = substr(($err['message'] ?? '') . ' @ ' . ($err['file'] ?? '') . ':' . ($err['line'] ?? ''), 0, 400);
    }
    $logDir = '/var/log/ocre';
    if (!is_dir($logDir)) @mkdir($logDir, 0775, true);
    @file_put_contents($logDir . '/requests.log',
        json_encode($entry, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n",
        FILE_APPEND | LOCK_EX);
}
register_shutdown_function('_ocreRequestLogger');
