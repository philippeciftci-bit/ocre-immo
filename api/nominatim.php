<?php
// M/2026/04/28/45 — Proxy Nominatim/OSM avec cache 24h dans ocre_meta.nominatim_cache.
// Respecte le rate limit Nominatim (1 req/s) en cachant agressivement les requêtes
// répétées. User-Agent "OcreImmo/1.0 (philippe.ciftci@gmail.com)" obligatoire.
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/lib/router.php';
setCorsHeaders();
// M/2026/05/08/10 — requireAuth() retire : les donnees Nominatim/OSM sont publiques (l autocomplete
// de noms d adresses ne fuite aucune info sensible). Fix bug Philippe 8 mai 01h21 : sa session
// backend etait expiree -> nominatim 401 -> autocomplete adresse cassee. Le proxy reste utile
// pour respecter rate limit Nominatim (1 req/s) + cache 24h. Anti-abus minimal : query >=3 chars
// + cache forte. Si abus detecte plus tard, ajouter rate limit basique par IP.
// $user = requireAuth();  // RETIRE intentionnellement.

$q = trim((string) ($_GET['q'] ?? ''));
$countries = preg_replace('/[^a-z,]/', '', strtolower((string) ($_GET['countrycodes'] ?? '')));
if (strlen($q) < 3) jsonError('query trop courte (min 3 chars)', 400);

function ensureNominatimCache() {
    static $done = false;
    if ($done) return;
    pdo_meta()->exec("CREATE TABLE IF NOT EXISTS nominatim_cache (
        query_key VARCHAR(255) NOT NULL PRIMARY KEY,
        response_json LONGTEXT NOT NULL,
        hit_count INT UNSIGNED NOT NULL DEFAULT 1,
        cached_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        expires_at DATETIME NOT NULL,
        INDEX idx_expires (expires_at)
    ) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
    $done = true;
}

ensureNominatimCache();
$cache_key = strtolower(trim($q)) . '|' . $countries;

// Cache hit ?
$stmt = pdo_meta()->prepare("SELECT response_json FROM nominatim_cache WHERE query_key = ? AND expires_at > NOW()");
$stmt->execute([$cache_key]);
$row = $stmt->fetch();
if ($row) {
    pdo_meta()->prepare("UPDATE nominatim_cache SET hit_count = hit_count + 1 WHERE query_key = ?")->execute([$cache_key]);
    jsonOk(['results' => json_decode($row['response_json'], true), 'cached' => true]);
}

// Cache miss : appel Nominatim.
$url = 'https://nominatim.openstreetmap.org/search?format=json&addressdetails=1&limit=5&q=' . urlencode($q);
if ($countries) $url .= '&countrycodes=' . $countries;
$ctx = stream_context_create([
    'http' => [
        'method' => 'GET',
        'header' => "User-Agent: OcreImmo/1.0 (philippe.ciftci@gmail.com)\r\n",
        'timeout' => 5,
    ],
]);
$body = @file_get_contents($url, false, $ctx);
if ($body === false) jsonError('Échec appel Nominatim', 502);
$data = json_decode($body, true);
if (!is_array($data)) jsonError('Réponse Nominatim invalide', 502);

// Sauve cache 24h.
pdo_meta()->prepare(
    "INSERT INTO nominatim_cache (query_key, response_json, expires_at)
     VALUES (?, ?, DATE_ADD(NOW(), INTERVAL 24 HOUR))
     ON DUPLICATE KEY UPDATE response_json = VALUES(response_json), expires_at = VALUES(expires_at), hit_count = hit_count + 1, cached_at = NOW()"
)->execute([$cache_key, $body]);

jsonOk(['results' => $data, 'cached' => false]);
