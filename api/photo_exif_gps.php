<?php
// M/2026/04/30/20 — EXIF GPS extraction + cluster centroide + reverse-geocode.
// Lit les photos uploadees du dossier via /uploads/<dossier_id>/, extrait GPS EXIF,
// retourne centroide si majorite (>=50%) clusterisee (rayon <= 1km).
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/lib/router.php';
setCorsHeaders();
$user = requireAuth();

if (!extension_loaded('exif')) {
    jsonError('Extension PHP exif non chargee sur le serveur', 503, ['reason' => 'exif_unavailable']);
}

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$input = [];
if ($method === 'POST') {
    $raw = file_get_contents('php://input');
    $input = $raw ? (json_decode($raw, true) ?: []) : [];
}
$dossierId = (int)(($input['dossier_id'] ?? $_GET['dossier_id'] ?? 0));
if ($dossierId <= 0) jsonError('dossier_id requis', 400);

// Verifier ownership.
$st = db()->prepare("SELECT id, data FROM clients WHERE id = ? AND user_id = ? AND deleted_at IS NULL LIMIT 1");
$st->execute([$dossierId, (int)$user['id']]);
$dossier = $st->fetch();
if (!$dossier) jsonError('Dossier introuvable', 404);

// Helpers.
function pegps_to_decimal($coord, $hemisphere) {
    if (is_string($coord)) $coord = explode(',', $coord);
    if (!is_array($coord) || count($coord) < 3) return null;
    $vals = [];
    for ($i = 0; $i < 3; $i++) {
        $part = explode('/', (string)($coord[$i] ?? '0/1'));
        $vals[] = count($part) === 2 && (float)$part[1] != 0
            ? floatval($part[0]) / floatval($part[1])
            : floatval($part[0]);
    }
    list($degrees, $minutes, $seconds) = $vals;
    $sign = (strtoupper($hemisphere) === 'W' || strtoupper($hemisphere) === 'S') ? -1 : 1;
    return $sign * ($degrees + $minutes / 60 + $seconds / 3600);
}

function pehaversine($lat1, $lng1, $lat2, $lng2) {
    $R = 6371000;
    $dLat = deg2rad($lat2 - $lat1);
    $dLng = deg2rad($lng2 - $lng1);
    $a = sin($dLat / 2) ** 2 + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLng / 2) ** 2;
    $c = 2 * atan2(sqrt($a), sqrt(1 - $a));
    return $R * $c;
}

// Liste des photos depuis bien.photos[] OU fallback scan disque.
$data = json_decode($dossier['data'] ?? '{}', true) ?: [];
$bienPhotos = [];
if (!empty($data['bien']['photos']) && is_array($data['bien']['photos'])) {
    foreach ($data['bien']['photos'] as $p) {
        $url = is_string($p) ? $p : (is_array($p) ? ($p['url'] ?? '') : '');
        if ($url) $bienPhotos[] = $url;
    }
}

$dossierDir = dirname(__DIR__) . '/uploads/' . $dossierId;
$paths = [];
if (is_dir($dossierDir)) {
    foreach (glob($dossierDir . '/*.{jpg,jpeg,JPG,JPEG}', GLOB_BRACE) ?: [] as $f) {
        // Skip thumbnails et docs identite.
        $base = basename($f);
        if (strpos($base, '_thumb') !== false) continue;
        if (strpos($base, 'doc-identite-') === 0) continue;
        // Si bien.photos defini, ne traiter que celles qui figurent dans la liste.
        if (!empty($bienPhotos)) {
            $matched = false;
            foreach ($bienPhotos as $bp) {
                if (strpos($bp, $base) !== false) { $matched = true; break; }
            }
            if (!$matched) continue;
        }
        $paths[] = $f;
    }
}

if (count($paths) === 0) {
    jsonOk(['ok' => false, 'reason' => 'no_photos', 'with_gps' => 0, 'total' => 0]);
}
if (count($paths) > 50) $paths = array_slice($paths, 0, 50);

$total = count($paths);
$points = [];
foreach ($paths as $path) {
    $exif = @exif_read_data($path, 'GPS');
    if (!$exif || empty($exif['GPSLatitude']) || empty($exif['GPSLongitude'])) continue;
    $lat = pegps_to_decimal($exif['GPSLatitude'], $exif['GPSLatitudeRef'] ?? 'N');
    $lng = pegps_to_decimal($exif['GPSLongitude'], $exif['GPSLongitudeRef'] ?? 'E');
    if ($lat === null || $lng === null) continue;
    if (abs($lat) < 0.0001 && abs($lng) < 0.0001) continue; // 0,0 invalide
    $points[] = [$lat, $lng];
}

$withGps = count($points);
if ($withGps === 0) {
    jsonOk(['ok' => false, 'reason' => 'no_gps', 'with_gps' => 0, 'total' => $total]);
}
if ($withGps / $total < 0.5) {
    jsonOk(['ok' => false, 'reason' => 'no_majority', 'with_gps' => $withGps, 'total' => $total]);
}

// Centroide.
$sumLat = 0; $sumLng = 0;
foreach ($points as $p) { $sumLat += $p[0]; $sumLng += $p[1]; }
$centerLat = $sumLat / $withGps;
$centerLng = $sumLng / $withGps;

// Distance max au centroide.
$maxDist = 0;
foreach ($points as $p) {
    $d = pehaversine($p[0], $p[1], $centerLat, $centerLng);
    if ($d > $maxDist) $maxDist = $d;
}
if ($maxDist > 1000) {
    jsonOk(['ok' => false, 'reason' => 'dispersed', 'with_gps' => $withGps, 'total' => $total,
            'max_distance_m' => round($maxDist)]);
}

// Reverse-geocode du centroide via Nominatim direct (pas par le proxy auth-only).
$address = '';
try {
    $url = 'https://nominatim.openstreetmap.org/reverse?format=json&accept-language=fr&zoom=18'
         . '&lat=' . urlencode((string)$centerLat) . '&lon=' . urlencode((string)$centerLng);
    $ctx = stream_context_create(['http' => [
        'timeout' => 3,
        'header' => "User-Agent: OcreImmo/1.0 (philippe.ciftci@gmail.com)\r\n",
        'ignore_errors' => true,
    ]]);
    $resp = @file_get_contents($url, false, $ctx);
    $j = $resp ? json_decode($resp, true) : null;
    if (is_array($j) && !empty($j['display_name'])) {
        // Tronquer aux 2 premiers segments (rue, ville).
        $segments = array_map('trim', explode(',', (string)$j['display_name']));
        $address = implode(', ', array_slice($segments, 0, 3));
    }
} catch (Exception $e) { /* address vide */ }

jsonOk([
    'ok' => true,
    'lat' => round($centerLat, 6),
    'lng' => round($centerLng, 6),
    'count' => $withGps,
    'total' => $total,
    'max_distance_m' => round($maxDist),
    'address' => $address,
]);
