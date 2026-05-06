<?php
// M/2026/05/06/77 — proxy frankfurter.app pour récupérer un taux de change live.
// Cache fichier 30 minutes pour éviter spam API.
// GET /api/currency_rate.php?from=EUR&to=MAD
// Réponse : {"ok": true, "from": "EUR", "to": "MAD", "rate": 10.842, "ts": 1778060000, "source": "frankfurter"}
//   ou : {"ok": false, "error": "..."}

require_once __DIR__ . '/db.php';
setCorsHeaders();

$user = requireAuth();

$from = preg_replace('/[^A-Z]/', '', strtoupper((string)($_GET['from'] ?? '')));
$to   = preg_replace('/[^A-Z]/', '', strtoupper((string)($_GET['to'] ?? '')));

if (strlen($from) !== 3 || strlen($to) !== 3) jsonError('from/to ISO 3 lettres requis', 400);
if ($from === $to) { jsonOk(['from' => $from, 'to' => $to, 'rate' => 1.0, 'ts' => time(), 'source' => 'identity']); }

$ALLOWED = ['EUR','MAD','USD','GBP','CHF','AED','SAR','CAD','JPY','CNY'];
if (!in_array($from, $ALLOWED, true) || !in_array($to, $ALLOWED, true)) jsonError('Devise non supportee', 400);

$cacheDir = '/var/lib/ocre/currency_cache';
if (!is_dir($cacheDir)) @mkdir($cacheDir, 0775, true);
$cacheKey = $from . '_' . $to;
$cacheFile = $cacheDir . '/' . $cacheKey . '.json';
$ttl = 30 * 60;
$now = time();

if (is_file($cacheFile)) {
    $cached = json_decode((string) @file_get_contents($cacheFile), true);
    if (is_array($cached) && isset($cached['rate'], $cached['ts']) && ($now - (int)$cached['ts']) < $ttl) {
        jsonOk(['from' => $from, 'to' => $to, 'rate' => (float)$cached['rate'], 'ts' => (int)$cached['ts'], 'source' => 'cache']);
    }
}

function _fetchFrankfurter(string $from, string $to): ?float {
    $url = 'https://api.frankfurter.app/latest?from=' . urlencode($from) . '&to=' . urlencode($to);
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 5);
    curl_setopt($ch, CURLOPT_USERAGENT, 'OcreImmo/1.0');
    $body = curl_exec($ch);
    $http = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($http !== 200 || !$body) return null;
    $j = json_decode($body, true);
    if (!is_array($j) || empty($j['rates'][$to])) return null;
    return (float) $j['rates'][$to];
}

$rate = _fetchFrankfurter($from, $to);
if ($rate === null) {
    // Fallback EUR pivot si paire directe pas supportee.
    if ($from !== 'EUR' && $to !== 'EUR') {
        $r1 = _fetchFrankfurter($from, 'EUR');
        $r2 = _fetchFrankfurter('EUR', $to);
        if ($r1 !== null && $r2 !== null && $r1 > 0) $rate = $r1 * $r2;
    }
}

if ($rate === null || $rate <= 0) {
    // Fallback offline : taux statique de l'app legacy (fix EUR pivot).
    $FX_VS_EUR = ['EUR' => 1.00, 'MAD' => 10.84, 'USD' => 1.08, 'GBP' => 0.857, 'AED' => 3.97, 'CHF' => 0.93,
                  'SAR' => 4.05, 'CAD' => 1.49, 'JPY' => 165.0, 'CNY' => 7.8];
    if (isset($FX_VS_EUR[$from], $FX_VS_EUR[$to])) {
        $rate = $FX_VS_EUR[$to] / $FX_VS_EUR[$from];
        @file_put_contents($cacheFile, json_encode(['rate' => $rate, 'ts' => $now]));
        jsonOk(['from' => $from, 'to' => $to, 'rate' => $rate, 'ts' => $now, 'source' => 'fallback_static']);
    }
    jsonError('Taux indisponible', 503);
}

@file_put_contents($cacheFile, json_encode(['rate' => $rate, 'ts' => $now]));
jsonOk(['from' => $from, 'to' => $to, 'rate' => $rate, 'ts' => $now, 'source' => 'frankfurter']);
