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
// V20 multi-tenant : DB_NAME calculé dynamiquement à partir du tenant slug
// (header X-Tenant-Slug nginx) + mode agent/test (cookie OCRE_MODE_<SLUG>).
// Fallback ocre_wsp_ozkan si slug vide ou invalide.
$_v20_slug = preg_replace('/[^a-z0-9_-]/', '', strtolower($_SERVER['HTTP_X_TENANT_SLUG'] ?? ''));
if (!$_v20_slug && !empty($_SERVER['HTTP_HOST']) && preg_match('/^([a-z0-9][a-z0-9-]*)\.ocre\.immo$/', $_SERVER['HTTP_HOST'], $_v20_m)) {
    $_v20_slug = $_v20_m[1];
}
if (!$_v20_slug) $_v20_slug = 'ozkan';
$_v20_mode_cookie = 'OCRE_MODE_' . strtoupper($_v20_slug);
$_v20_mode = $_COOKIE[$_v20_mode_cookie] ?? 'agent';
if (!in_array($_v20_mode, ['agent', 'test'], true)) $_v20_mode = 'agent';
$_v20_suffix = ($_v20_mode === 'test') ? '_test' : '';
define('DB_NAME', 'ocre_wsp_' . $_v20_slug . $_v20_suffix);
define('DB_CHARSET','utf8mb4');

define('ADMIN_CODE','OCRE-ADMIN-2026');
define('SESSION_DURATION',86400*30);
define('BCRYPT_COST',10);

define('APP_URL','https://app.ocre.immo/');
define('API_URL','https://app.ocre.immo/api/');

define('DEBUG',false);
define('LOG_ERRORS',true);

define('ALLOWED_ORIGINS',['https://app.ocre.immo','https://ocre.immo','https://www.ocre.immo']);
