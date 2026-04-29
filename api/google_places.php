<?php
// M/2026/04/30/7 — Proxy Google Places API + quota strict par agent par jour.
// Active uniquement si settings.google_places_api_key est defini (sinon 503).
// Quota par defaut : settings.google_places_daily_limit (default 10).
// Reset quota a minuit local Africa/Casablanca (date column = DATE locale).
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/lib/router.php';
setCorsHeaders();
$user = requireAuth();

function ensureGoogleQuotaSchema() {
    static $done = false;
    if ($done) return;
    db()->exec("CREATE TABLE IF NOT EXISTS google_places_quota (
        id INT AUTO_INCREMENT PRIMARY KEY,
        agent_id INT NOT NULL,
        date DATE NOT NULL,
        count INT NOT NULL DEFAULT 0,
        daily_limit INT NOT NULL DEFAULT 10,
        UNIQUE KEY uniq_agent_date (agent_id, date),
        INDEX idx_date (date)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    db()->exec("CREATE TABLE IF NOT EXISTS google_quota_requests (
        id INT AUTO_INCREMENT PRIMARY KEY,
        agent_id INT NOT NULL,
        requested_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        motif TEXT NULL,
        status ENUM('pending','approved','denied') NOT NULL DEFAULT 'pending',
        responded_at DATETIME NULL,
        responder_id INT NULL,
        response_message TEXT NULL,
        granted_extra INT NOT NULL DEFAULT 10,
        INDEX idx_agent_status (agent_id, status),
        INDEX idx_status_date (status, requested_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $done = true;
}
ensureGoogleQuotaSchema();

$action = $_GET['action'] ?? 'autocomplete';
$apiKey = getSetting('google_places_api_key', '');
if (!$apiKey) jsonError('Google Places API non configure (settings.google_places_api_key absent)', 503);

$casaTz = new DateTimeZone('Africa/Casablanca');
$today = (new DateTime('now', $casaTz))->format('Y-m-d');
$globalLimit = (int) getSetting('google_places_daily_limit', 10);
if ($globalLimit <= 0) $globalLimit = 10;

// Helper : recupere le quota courant de l agent (cree la ligne du jour si absente).
function getOrInitQuota($userId, $today, $globalLimit) {
    $st = db()->prepare("SELECT count, daily_limit FROM google_places_quota WHERE agent_id = ? AND date = ? LIMIT 1");
    $st->execute([$userId, $today]);
    $row = $st->fetch();
    if ($row) return ['count' => (int)$row['count'], 'limit' => (int)$row['daily_limit']];
    db()->prepare("INSERT INTO google_places_quota (agent_id, date, count, daily_limit) VALUES (?, ?, 0, ?)")
        ->execute([$userId, $today, $globalLimit]);
    return ['count' => 0, 'limit' => $globalLimit];
}

if ($action === 'autocomplete') {
    $q = trim((string)($_GET['q'] ?? ''));
    if (strlen($q) < 3) jsonError('Query trop courte (min 3 chars)', 400);

    $quota = getOrInitQuota((int)$user['id'], $today, $globalLimit);
    if ($quota['count'] >= $quota['limit']) {
        http_response_code(429);
        echo json_encode(['ok' => false, 'error' => 'quota_exceeded',
            'count' => $quota['count'], 'limit' => $quota['limit']]);
        exit;
    }

    $url = 'https://maps.googleapis.com/maps/api/place/autocomplete/json'
         . '?input=' . urlencode($q)
         . '&key=' . urlencode($apiKey)
         . '&language=fr'
         . '&types=address';
    $ctx = stream_context_create(['http' => ['timeout' => 6, 'ignore_errors' => true]]);
    $resp = @file_get_contents($url, false, $ctx);
    $data = $resp ? json_decode($resp, true) : null;
    if (!$data || ($data['status'] ?? '') !== 'OK') {
        echo json_encode(['ok' => true, 'results' => [],
            'quota' => ['count' => $quota['count'], 'limit' => $quota['limit'], 'remaining' => $quota['limit'] - $quota['count']]]);
        exit;
    }

    $results = array_map(function ($p) {
        return [
            'place_id' => $p['place_id'] ?? '',
            'description' => $p['description'] ?? '',
            'main_text' => $p['structured_formatting']['main_text'] ?? '',
            'secondary_text' => $p['structured_formatting']['secondary_text'] ?? '',
        ];
    }, array_slice($data['predictions'] ?? [], 0, 5));

    db()->prepare("UPDATE google_places_quota SET count = count + 1 WHERE agent_id = ? AND date = ?")
        ->execute([(int)$user['id'], $today]);

    jsonOk([
        'results' => $results,
        'quota' => ['count' => $quota['count'] + 1, 'limit' => $quota['limit'], 'remaining' => $quota['limit'] - $quota['count'] - 1],
    ]);
}

if ($action === 'details') {
    $place_id = trim((string)($_GET['place_id'] ?? ''));
    if (!$place_id) jsonError('place_id requis', 400);
    $url = 'https://maps.googleapis.com/maps/api/place/details/json'
         . '?place_id=' . urlencode($place_id)
         . '&key=' . urlencode($apiKey)
         . '&language=fr'
         . '&fields=geometry,address_components,formatted_address';
    $ctx = stream_context_create(['http' => ['timeout' => 6, 'ignore_errors' => true]]);
    $resp = @file_get_contents($url, false, $ctx);
    $data = $resp ? json_decode($resp, true) : null;
    if (!$data || ($data['status'] ?? '') !== 'OK') jsonError('Place introuvable', 404);

    $r = $data['result'] ?? [];
    $components = [];
    foreach (($r['address_components'] ?? []) as $c) {
        foreach (($c['types'] ?? []) as $t) {
            $components[$t] = $c;
        }
    }
    $get = function ($key, $field = 'long_name') use ($components) {
        return $components[$key][$field] ?? '';
    };
    jsonOk([
        'lat' => $r['geometry']['location']['lat'] ?? null,
        'lng' => $r['geometry']['location']['lng'] ?? null,
        'formatted_address' => $r['formatted_address'] ?? '',
        'street_number' => $get('street_number'),
        'route' => $get('route'),
        'adresse' => trim(($get('street_number') ? $get('street_number') . ' ' : '') . $get('route')),
        'code_postal' => $get('postal_code'),
        'ville' => $get('locality') ?: $get('postal_town') ?: $get('administrative_area_level_2'),
        'quartier' => $get('sublocality') ?: $get('neighborhood') ?: '',
        'pays' => strtoupper($get('country', 'short_name')),
        'pays_name' => $get('country'),
    ]);
}

jsonError('Action inconnue : ' . $action, 400);
