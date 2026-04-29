<?php
// M/2026/04/30/9 — detection pays via IP. Endpoint public leger (pas d auth requise).
// Cache fichier 24h pour limiter appels ipapi.co (gratuit jusqu a 30k req/mois sans cle).
header('Content-Type: application/json');
header('Cache-Control: no-store');

$ip = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['REMOTE_ADDR'] ?? '';
$ip = trim(explode(',', (string)$ip)[0]);

if (!filter_var($ip, FILTER_VALIDATE_IP)) {
    echo json_encode(['ok' => false, 'reason' => 'invalid_ip']);
    exit;
}
// IPs privees / reservees : pas la peine d interroger le service (toujours fail).
if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
    echo json_encode(['ok' => false, 'reason' => 'private_ip']);
    exit;
}

$cacheFile = sys_get_temp_dir() . '/ocre_country_' . md5($ip) . '.json';
if (is_file($cacheFile) && (time() - filemtime($cacheFile)) < 86400) {
    $cached = @file_get_contents($cacheFile);
    if ($cached) { echo $cached; exit; }
}

$ctx = stream_context_create(['http' => [
    'timeout' => 3,
    'header' => "User-Agent: OcreImmo/1.0\r\n",
    'ignore_errors' => true,
]]);
$response = @file_get_contents('https://ipapi.co/' . urlencode($ip) . '/json/', false, $ctx);
if (!$response) {
    echo json_encode(['ok' => false, 'reason' => 'service_unreachable']);
    exit;
}
$data = json_decode($response, true);
if (!is_array($data) || empty($data['country_code'])) {
    echo json_encode(['ok' => false, 'reason' => 'no_country']);
    exit;
}

$out = json_encode([
    'ok' => true,
    'country_code' => strtoupper((string)$data['country_code']),
    'country_code_3' => strtoupper((string)($data['country_code_iso3'] ?? '')),
    'country_name' => (string)($data['country_name'] ?? ''),
]);
@file_put_contents($cacheFile, $out);
echo $out;
