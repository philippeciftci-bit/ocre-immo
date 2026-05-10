<?php
// M_GLITCHTIP_INSTALL — Webhook bridge GlitchTip → Telegram (/root/bin/notify)
// Déploiement : /opt/glitchtip-webhook/handler.php (chmod 0644 root:www-data)
// Configurer dans GlitchTip UI : Settings → Alerts → Webhook URL = https://glitchtip.46-225-215-148.sslip.io/glitchtip-webhook
//
// Sécurité : token simple via query string ?t=<secret> pour éviter spam (à compléter avec HMAC GlitchTip si dispo)
// Le token doit être présent dans /etc/ocre/glitchtip-webhook.token mode 0640

header('Content-Type: application/json; charset=utf-8');

// Vérification token
$tokenFile = '/etc/ocre/glitchtip-webhook.token';
if (!is_readable($tokenFile)) { http_response_code(500); echo '{"error":"token not configured"}'; exit; }
$expected = trim(file_get_contents($tokenFile));
$received = trim($_GET['t'] ?? '');
if (!$expected || !hash_equals($expected, $received)) {
    http_response_code(403);
    echo '{"error":"invalid token"}';
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo '{"error":"method not allowed"}';
    exit;
}

$raw = file_get_contents('php://input');
$payload = json_decode($raw, true) ?: [];

$project = $payload['project_name'] ?? $payload['project_slug'] ?? 'unknown';
$issue = $payload['issue'] ?? $payload['data'] ?? [];
$title = $issue['title'] ?? $payload['message'] ?? 'Erreur inconnue';
$culprit = $issue['culprit'] ?? $issue['location'] ?? '';
$url = $issue['web_url'] ?? $issue['permalink'] ?? '';
$count = (int)($issue['count'] ?? $issue['times_seen'] ?? 1);
$level = $issue['level'] ?? 'error';

// Determine notif priority basée sur level
$priority = match ($level) {
    'fatal', 'error' => 'high',
    'warning' => 'warning',
    default => 'info',
};

$body = "[$project] $title";
if ($culprit) $body .= "\nLieu : $culprit";
$body .= "\nOccurrences : $count";
if ($url) $body .= "\n$url";

@shell_exec(
    '/root/bin/notify --project atelier --priority ' . escapeshellarg($priority) . ' '
    . '--title ' . escapeshellarg('GlitchTip alert') . ' '
    . '--body ' . escapeshellarg(substr($body, 0, 1500))
    . ' >/dev/null 2>&1 &'
);

// Log local pour traçabilité
$logFile = '/var/log/glitchtip-webhook.log';
@file_put_contents($logFile, '[' . date('c') . '] level=' . $level . ' project=' . $project . ' title=' . substr($title, 0, 100) . "\n", FILE_APPEND);

http_response_code(200);
echo '{"ok":true}';
