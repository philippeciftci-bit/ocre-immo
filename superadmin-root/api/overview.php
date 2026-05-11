<?php
// GET /api/overview.php → status temps réel chaque domaine Ocre.
require_once __DIR__ . '/_lib.php';
sa_cors();
sa_require_super_admin();

$domains = [
    ['name' => 'ocre.immo (vitrine WP)',     'url' => 'https://ocre.immo/'],
    ['name' => 'app.ocre.immo (hub)',        'url' => 'https://app.ocre.immo/'],
    ['name' => 'agent.ocre.immo (landing)',  'url' => 'https://agent.ocre.immo/'],
    ['name' => 'auth.ocre.immo',             'url' => 'https://auth.ocre.immo/'],
    ['name' => 'signup.ocre.immo',           'url' => 'https://signup.ocre.immo/'],
    ['name' => 'admin.ocre.immo',            'url' => 'https://admin.ocre.immo/'],
];

$results = [];
foreach ($domains as $d) {
    $start = microtime(true);
    $ch = curl_init($d['url']);
    curl_setopt_array($ch, [
        CURLOPT_NOBODY => true,
        CURLOPT_TIMEOUT => 4,
        CURLOPT_CONNECTTIMEOUT => 2,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_USERAGENT => 'OcreSuperAdmin/1.0',
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => false,
    ]);
    curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = curl_error($ch);
    curl_close($ch);
    $elapsed = (int) ((microtime(true) - $start) * 1000);
    $ok = $code >= 200 && $code < 400;
    $results[] = [
        'name' => $d['name'], 'url' => $d['url'],
        'http' => $code, 'ok' => $ok, 'ms' => $elapsed,
        'error' => $err ?: null,
    ];
}

// Stats sessions / users globales
$db = auth_db();
$stats = [];
try {
    $stats['users_total'] = (int) $db->query("SELECT COUNT(*) FROM auth_users")->fetchColumn();
    $stats['users_active'] = (int) $db->query("SELECT COUNT(*) FROM auth_users WHERE status='active'")->fetchColumn();
    $stats['sessions_active'] = (int) $db->query("SELECT COUNT(*) FROM auth_sessions WHERE revoked_at IS NULL AND expires_at > NOW()")->fetchColumn();
    $stats['magic_pending'] = (int) $db->query("SELECT COUNT(*) FROM auth_magic_tokens WHERE used_at IS NULL AND expires_at > NOW()")->fetchColumn();
    $stats['signups_24h'] = (int) $db->query("SELECT COUNT(*) FROM auth_users WHERE created_at > DATE_SUB(NOW(), INTERVAL 1 DAY)")->fetchColumn();
    $stats['logins_24h'] = (int) $db->query("SELECT COUNT(*) FROM auth_users WHERE last_login_at > DATE_SUB(NOW(), INTERVAL 1 DAY)")->fetchColumn();
} catch (Throwable $e) { /* swallow */ }

sa_send_json(['ok' => true, 'domains' => $results, 'stats' => $stats, 'generated_at' => date('c')]);
