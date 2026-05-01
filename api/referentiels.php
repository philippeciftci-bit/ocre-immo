<?php
// M/2026/05/02/1 — M109 endpoint référentiels pays. GET ?country=XX retourne le JSON
// référentiel actif (latest version) pour le pays XX. Si pas de répertoire pays présent,
// fallback sur _default.json. action=list retourne tous les pays disponibles + version.
// action=countries retourne la liste universelle 198 pays (drapeau, phone code, currency).
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: public, max-age=300'); // 5 min cache
header('Access-Control-Allow-Origin: *');

$BASE = __DIR__ . '/../data/referentiels';

function jout($d, $code = 200) {
    http_response_code($code);
    echo json_encode($d, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

$action = $_GET['action'] ?? '';

if ($action === 'countries') {
    $f = $BASE . '/_countries.json';
    if (!is_file($f)) jout(['ok' => false, 'error' => '_countries.json missing'], 500);
    $j = json_decode(file_get_contents($f), true);
    jout(['ok' => true, 'countries' => $j]);
}

if ($action === 'list') {
    $list = [];
    foreach (glob($BASE . '/*', GLOB_ONLYDIR) as $dir) {
        $cc = basename($dir);
        // Latest version = sort desc des fichiers *.json dans le dossier.
        $files = glob($dir . '/*.json');
        if (!$files) continue;
        rsort($files);
        $j = json_decode(file_get_contents($files[0]), true);
        if (!$j) continue;
        $list[$cc] = [
            'country_code'      => $j['country_code']      ?? $cc,
            'version'           => $j['version']           ?? null,
            'validated_at'      => $j['validated_at']      ?? null,
            'next_review_date'  => $j['next_review_date']  ?? null,
            'currency'          => $j['currency']          ?? null,
            'partial'           => !empty($j['validated_partial']),
        ];
    }
    jout(['ok' => true, 'referentiels' => $list]);
}

// Default : country=XX -> JSON référentiel
$cc = strtoupper(preg_replace('/[^A-Za-z]/', '', $_GET['country'] ?? ''));
if (!$cc || strlen($cc) !== 2) jout(['ok' => false, 'error' => 'country (ISO2) required'], 400);

$dir = $BASE . '/' . $cc;
$file = null;
if (is_dir($dir)) {
    $files = glob($dir . '/*.json');
    if ($files) {
        rsort($files);
        $file = $files[0];
    }
}
if (!$file) {
    // Fallback _default.json
    $file = $BASE . '/_default.json';
    if (!is_file($file)) jout(['ok' => false, 'error' => '_default.json missing'], 500);
}
$j = json_decode(file_get_contents($file), true);
if (!$j) jout(['ok' => false, 'error' => 'invalid referentiel JSON'], 500);

// Logging non-bloquant (touche /var/log/ocre-referentiels.log si writable).
@error_log(date('c') . " referentiel country=$cc file=" . basename($file) . "\n", 3, '/var/log/ocre-referentiels.log');

$j['_meta'] = [
    'served_country' => $cc,
    'fallback_default' => !is_dir($dir) || empty(glob($dir . '/*.json')),
    'served_file' => basename($file),
];
jout(['ok' => true, 'referentiel' => $j]);
