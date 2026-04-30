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

// M/2026/04/30/29 — strategie multi-source : Frankfurter (ECB) puis merge exchangerate.host
// pour combler les devises absentes (Frankfurter ne couvre pas MAD/TND/DZD/EGP/SAR/QAR/KWD/BHD).
// Fallback hardcode si les 2 APIs muettes (rates approximatifs pour eviter NULL).
$data = fetch_url('https://api.frankfurter.app/latest?from=EUR&to=' . urlencode(SYMBOLS));
$source = 'frankfurter';
$rates = ($data && !empty($data['rates'])) ? $data['rates'] : [];

// Si pas tous les symboles, complete via exchangerate.host.
$missing = array_diff(explode(',', SYMBOLS), array_keys($rates));
if (!empty($missing)) {
    $alt = fetch_url('https://api.exchangerate.host/latest?base=EUR&symbols=' . urlencode(implode(',', $missing)));
    if ($alt && !empty($alt['rates'])) {
        foreach ($alt['rates'] as $k => $v) {
            if (!isset($rates[$k]) && is_numeric($v) && $v > 0) $rates[$k] = $v;
        }
        if (!$data) {
            $data = ['base' => 'EUR', 'date' => $alt['date'] ?? $today];
            $source = 'exchangerate.host';
        } else {
            $source = 'frankfurter+exchangerate.host';
        }
    }
}

// Fallback hardcode pour les devises critiques (taux indicatifs avril 2026, mis a jour
// au prochain hit cache miss avec API live). Evite NULL en frontend.
$hardcoded = [
    'MAD' => 10.85, 'TND' => 3.40, 'DZD' => 145.0, 'EGP' => 53.0,
    'SAR' => 4.05, 'QAR' => 3.93, 'KWD' => 0.331, 'BHD' => 0.405, 'AED' => 3.97,
];
foreach ($hardcoded as $k => $v) {
    if (empty($rates[$k])) $rates[$k] = $v;
}

if (empty($rates)) {
    @error_log('exchange_rates.php: rates_unavailable both APIs failed', 0);
    http_response_code(503);
    echo json_encode(['ok' => false, 'error' => 'rates_unavailable']);
    exit;
}
if (!$data) $data = ['base' => 'EUR', 'date' => $today];
$data['rates'] = $rates;

$out = json_encode([
    'ok' => true,
    'base' => $data['base'] ?? 'EUR',
    'date' => $data['date'] ?? $today,
    'rates' => $data['rates'],
    'source' => $source,
]);
@file_put_contents($cacheFile, $out);
echo $out;
