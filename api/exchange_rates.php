<?php
// M/2026/04/30/29 — Exchange rates Frankfurter (gratuit, pas de cle API).
// Cache fichier 24h dans /tmp/ocre_exchange_rates_<YYYY-MM-DD>.json.
// Fallback exchangerate.host si frankfurter down.
header('Content-Type: application/json');
header('Cache-Control: max-age=86400');
header('Access-Control-Allow-Origin: *');

const SYMBOLS = 'MAD,USD,TRY,GBP,CHF,JPY,CAD,AUD,AED,SAR,EGP,DZD,TND,QAR,KWD,BHD,SEK,NOK,DKK,CNY';
$today = date('Y-m-d');
$cacheFile = sys_get_temp_dir() . '/ocre_exchange_rates_' . $today . '.json';

if (is_file($cacheFile) && (time() - filemtime($cacheFile)) < 86400) {
    $cached = @file_get_contents($cacheFile);
    if ($cached) { echo $cached; exit; }
}

function fetch_url(string $url): ?array {
    $ctx = stream_context_create(['http' => [
        'timeout' => 5,
        'header' => "User-Agent: OcreImmo/1.0\r\n",
        'ignore_errors' => true,
    ]]);
    $body = @file_get_contents($url, false, $ctx);
    if (!$body) return null;
    $j = json_decode($body, true);
    return is_array($j) ? $j : null;
}

// Tentative 1 : Frankfurter (ECB rates, gratuit, pas de cle).
$data = fetch_url('https://api.frankfurter.app/latest?from=EUR&to=' . urlencode(SYMBOLS));
$source = 'frankfurter';
if (!$data || empty($data['rates'])) {
    // Fallback exchangerate.host.
    $data = fetch_url('https://api.exchangerate.host/latest?base=EUR&symbols=' . urlencode(SYMBOLS));
    $source = 'exchangerate.host';
}

if (!$data || empty($data['rates'])) {
    @error_log('exchange_rates.php: rates_unavailable both APIs failed', 0);
    http_response_code(503);
    echo json_encode(['ok' => false, 'error' => 'rates_unavailable']);
    exit;
}

$out = json_encode([
    'ok' => true,
    'base' => $data['base'] ?? 'EUR',
    'date' => $data['date'] ?? $today,
    'rates' => $data['rates'],
    'source' => $source,
]);
@file_put_contents($cacheFile, $out);
echo $out;
