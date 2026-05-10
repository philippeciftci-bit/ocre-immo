<?php
// M_GLITCHTIP_INSTALL — Bootstrap Sentry PHP SDK pour backend Ocre (auth, app)
// Déploiement : /opt/ocre-auth/lib/sentry-init.php + /opt/ocre-app/api/lib/sentry-init.php
// Inclusion : require_once en haut de chaque endpoint critique
// Pré-requis : composer require sentry/sentry (dans /var/www/ocre-auth/ et /opt/ocre-app/)
//
// Configuration : DSN lu depuis /etc/ocre/glitchtip-dsn-backend (mode 0640 root:www-data, accessible www-ocre via group)

if (!class_exists('\Sentry\Init')) {
    // Composer autoload pas encore chargé : tenter chemin standard
    $autoloads = [
        __DIR__ . '/../../vendor/autoload.php',
        __DIR__ . '/../vendor/autoload.php',
        '/var/www/ocre-auth/vendor/autoload.php',
        '/opt/ocre-app/vendor/autoload.php',
    ];
    foreach ($autoloads as $a) { if (is_readable($a)) { require_once $a; break; } }
}

if (!function_exists('\Sentry\init')) {
    error_log('[ocre-sentry] Sentry SDK non installé (composer require sentry/sentry manquant) — GlitchTip skip');
    return;
}

$dsnFile = '/etc/ocre/glitchtip-dsn-backend';
if (!is_readable($dsnFile)) {
    error_log('[ocre-sentry] DSN file inaccessible : ' . $dsnFile);
    return;
}
$dsn = trim(file_get_contents($dsnFile));
if (!$dsn || $dsn === 'TODO_REMPLIR_APRES_INSTALL_GLITCHTIP') {
    error_log('[ocre-sentry] DSN vide ou placeholder — GlitchTip skip');
    return;
}

// Identifier le service (auth ou app) selon chemin
$service = 'unknown';
if (strpos(__DIR__, 'ocre-auth') !== false) $service = 'ocre-auth';
elseif (strpos(__DIR__, 'ocre-app') !== false) $service = 'ocre-app';

\Sentry\init([
    'dsn' => $dsn,
    'environment' => 'production',
    'release' => $service . '@' . (@trim(@file_get_contents(__DIR__ . '/../VERSION')) ?: 'unknown'),
    'traces_sample_rate' => 0.1,
    'send_default_pii' => false,
    'before_send' => function (\Sentry\Event $event): ?\Sentry\Event {
        // Exclure 401 magic-link expiré / erreurs auth normales (bruit)
        $msg = (string)($event->getMessage() ?? '');
        if (str_contains($msg, 'token_expired') || str_contains($msg, 'Non authentifié')) return null;
        return $event;
    },
]);

// Helper pour wrap exceptions
if (!function_exists('ocre_capture_exception')) {
    function ocre_capture_exception(\Throwable $e, array $context = []): void {
        if (function_exists('\Sentry\captureException')) {
            \Sentry\configureScope(function (\Sentry\State\Scope $scope) use ($context) {
                foreach ($context as $k => $v) $scope->setExtra($k, $v);
            });
            \Sentry\captureException($e);
        }
    }
}
