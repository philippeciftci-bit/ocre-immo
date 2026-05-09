<?php
// M/2026/05/09/43 — M89 : helper unifié pour déclencher un push PWA depuis un event métier.
// Wraps push_send.php avec internal_token + try/catch + log /var/log/ocre-push.log.
// Fire-and-forget : ne bloque jamais le flow métier appelant.
//
// Usage :
//   require_once __DIR__ . '/lib/push_notify.php';
//   ocre_push_notify($targetUserId, 'matching', '🎯 Match détecté', 'Bien X ↔ client Y (87%)', '/matches/123');

if (!function_exists('ocre_push_notify')) {

function ocre_push_notify(int $userId, string $type, string $title, string $body, string $url = '/'): bool {
    if ($userId <= 0 || $title === '' || $body === '') return false;
    $tokenFile = '/root/.secrets/ocre_push_internal.token';
    if (!is_readable($tokenFile)) {
        @file_put_contents('/var/log/ocre-push.log', date('c') . " ERR token_unreadable type=$type uid=$userId\n", FILE_APPEND);
        return false;
    }
    $token = trim(@file_get_contents($tokenFile) ?: '');
    if ($token === '') return false;

    $payload = [
        'user_id' => $userId,
        'type' => $type,
        'title' => $title,
        'body' => $body,
        'url' => $url,
        'tag' => 'ocre-' . $type,
        'internal_token' => $token,
    ];

    // En CLI HTTP_HOST est vide → nginx 301 vers https. On utilise un host tenant valide en fallback.
    $host = $_SERVER['HTTP_HOST'] ?? '';
    if ($host === '') $host = 'app.ocre.immo';
    // Détermine schéma : si on est en HTTP local (nginx redirige vers https systematic), utiliser https direct.
    $url = 'https://' . $host . '/api/push_send.php';
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode($payload),
        CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 8,
        CURLOPT_CONNECTTIMEOUT => 3,
        CURLOPT_SSL_VERIFYPEER => false, // loopback HTTPS, on peut tolérer cert
        CURLOPT_SSL_VERIFYHOST => 0,
    ]);
    $resp = curl_exec($ch);
    $err = curl_error($ch);
    $http = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($err || $http >= 400) {
        @file_put_contents('/var/log/ocre-push.log',
            date('c') . " ERR uid=$userId type=$type http=$http err=" . substr((string)$err, 0, 200) . "\n",
            FILE_APPEND);
        return false;
    }
    @file_put_contents('/var/log/ocre-push.log',
        date('c') . " OK uid=$userId type=$type title=" . substr($title, 0, 80) . " resp=" . substr((string)$resp, 0, 200) . "\n",
        FILE_APPEND);
    return true;
}

}
