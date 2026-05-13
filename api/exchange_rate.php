<?php
// M/2026/05/13/40 — Currency popup taux + Frankfurter v2 + cache MySQL + mode manuel.
// Source unique Frankfurter v2 (BCE+banques centrales). Cache MySQL TTL 1h dans
// ocre_meta.exchange_rates_cache. Fallback : derniere valeur cache + is_stale=true.
//
// GET /api/exchange_rate.php?from=EUR&to=USD&refresh=0|1
//
// Reponse 200 : {ok, rate, source, fetched_at, is_manual:false, is_stale, base, quote}
// Reponse 400 : {ok:false, error: 'INVALID_PAIR'} si paire non supportee
// Reponse 503 : {ok:false, error: 'NO_DATA'} si Frankfurter KO ET aucun cache disponible.

require_once __DIR__ . '/db.php';
setCorsHeaders();
header('Content-Type: application/json; charset=utf-8');

// Devises supportees (extensible).
const FX_SUPPORTED = ['EUR','USD','MAD','GBP','CHF','CAD','AUD','JPY','CNY','AED','SAR','TND','DZD','EGP'];
const FX_TTL_SEC = 3600; // 1h
const FX_TIMEOUT_SEC = 5;

$from = preg_replace('/[^A-Z]/', '', strtoupper((string)($_GET['from'] ?? '')));
$to   = preg_replace('/[^A-Z]/', '', strtoupper((string)($_GET['to'] ?? '')));
$refresh = ($_GET['refresh'] ?? '0') === '1';

if (strlen($from) !== 3 || strlen($to) !== 3
    || !in_array($from, FX_SUPPORTED, true) || !in_array($to, FX_SUPPORTED, true)) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'INVALID_PAIR', 'supported' => FX_SUPPORTED]);
    exit;
}

if ($from === $to) {
    echo json_encode([
        'ok' => true, 'rate' => 1.0, 'source' => 'identity',
        'fetched_at' => date('c'), 'is_manual' => false, 'is_stale' => false,
        'base' => $from, 'quote' => $to,
    ]);
    exit;
}

try {
    $pdo = new PDO('mysql:host=' . DB_HOST . ';dbname=ocre_meta;charset=utf8mb4', DB_USER, DB_PASS, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'DB_CONNECT']);
    exit;
}

function fx_cache_lookup(PDO $pdo, string $from, string $to): ?array {
    $st = $pdo->prepare("SELECT rate, source, fetched_at, UNIX_TIMESTAMP(fetched_at) ts FROM exchange_rates_cache WHERE currency_from = ? AND currency_to = ? LIMIT 1");
    $st->execute([$from, $to]);
    return $st->fetch() ?: null;
}

function fx_cache_save(PDO $pdo, string $from, string $to, float $rate, string $source): void {
    $st = $pdo->prepare("INSERT INTO exchange_rates_cache (currency_from, currency_to, rate, source, fetched_at) VALUES (?,?,?,?,NOW()) ON DUPLICATE KEY UPDATE rate = VALUES(rate), source = VALUES(source), fetched_at = NOW()");
    $st->execute([$from, $to, $rate, $source]);
}

function fx_fetch_frankfurter(string $from, string $to): ?array {
    // Frankfurter v2 : https://api.frankfurter.dev/v2/rates?base=EUR&quotes=USD
    // Reponse : array of { date, base, quote, rate }
    $url = 'https://api.frankfurter.dev/v2/rates?base=' . urlencode($from) . '&quotes=' . urlencode($to);
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => FX_TIMEOUT_SEC,
        CURLOPT_USERAGENT => 'OcreImmo/1.0 (https://ocre.immo)',
    ]);
    $body = curl_exec($ch);
    $http = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($http !== 200 || !$body) return null;
    $j = json_decode($body, true);
    if (!is_array($j) || empty($j[0]['rate'])) return null;
    return ['rate' => (float)$j[0]['rate'], 'date' => (string)($j[0]['date'] ?? date('Y-m-d'))];
}

$now = time();
$cached = fx_cache_lookup($pdo, $from, $to);
$cacheFresh = $cached && ($now - (int)$cached['ts']) < FX_TTL_SEC;

// Cache hit fresh + pas de refresh demande -> retour cache.
if ($cacheFresh && !$refresh) {
    echo json_encode([
        'ok' => true,
        'rate' => (float)$cached['rate'],
        'source' => (string)$cached['source'],
        'fetched_at' => date('c', (int)$cached['ts']),
        'is_manual' => false,
        'is_stale' => false,
        'base' => $from,
        'quote' => $to,
        'cache' => 'hit',
    ]);
    exit;
}

// Cache miss ou refresh : fetch Frankfurter v2.
$live = fx_fetch_frankfurter($from, $to);
if ($live !== null) {
    fx_cache_save($pdo, $from, $to, $live['rate'], 'frankfurter_v2');
    echo json_encode([
        'ok' => true,
        'rate' => $live['rate'],
        'source' => 'Frankfurter/BCE',
        'fetched_at' => date('c'),
        'is_manual' => false,
        'is_stale' => false,
        'base' => $from,
        'quote' => $to,
        'cache' => 'miss',
        'rate_date' => $live['date'],
    ]);
    exit;
}

// Fallback : Frankfurter KO -> retour cache stale si dispo.
if ($cached) {
    echo json_encode([
        'ok' => true,
        'rate' => (float)$cached['rate'],
        'source' => (string)$cached['source'],
        'fetched_at' => date('c', (int)$cached['ts']),
        'is_manual' => false,
        'is_stale' => true,
        'base' => $from,
        'quote' => $to,
        'cache' => 'stale',
    ]);
    exit;
}

http_response_code(503);
echo json_encode(['ok' => false, 'error' => 'NO_DATA', 'base' => $from, 'quote' => $to]);
