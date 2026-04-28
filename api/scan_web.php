<?php
// M/2026/04/28/62 — Endpoint Scan Web : delegue aux adapters externes.
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/lib/external_sources/SeLogerAdapter.php';
require_once __DIR__ . '/lib/external_sources/BienIciAdapter.php';
require_once __DIR__ . '/lib/external_sources/FBAdapter.php';
require_once __DIR__ . '/lib/external_sources/InstagramAdapter.php';
setCorsHeaders();

$user = requireAuth();
$uid = (int) $user['id'];
$action = $_GET['action'] ?? 'launch';

function ensureExternalCacheSchema(): void {
    static $done = false;
    if ($done) return;
    try {
        $dsn = 'mysql:host=' . DB_HOST . ';dbname=ocre_meta;charset=utf8mb4';
        $pdo = new PDO($dsn, DB_USER, DB_PASS, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $pdo->exec("CREATE TABLE IF NOT EXISTS external_search_cache (
            id BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
            source VARCHAR(50) NOT NULL,
            query_hash CHAR(64) NOT NULL,
            results LONGTEXT NOT NULL,
            result_count INT DEFAULT 0,
            cached_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            expires_at DATETIME NOT NULL,
            INDEX idx_source_query (source, query_hash, expires_at)
        ) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
    } catch (Throwable $e) {}
    $done = true;
}
ensureExternalCacheSchema();

function metaPdo(): PDO {
    static $p = null;
    if ($p) return $p;
    $p = new PDO('mysql:host=' . DB_HOST . ';dbname=ocre_meta;charset=utf8mb4', DB_USER, DB_PASS, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
    return $p;
}

if ($action === 'launch') {
    $clientId = (int) ($_GET['client_id'] ?? 0);
    $sources = $_GET['sources'] ?? 'seloger,bienici';
    $sources = array_map('trim', explode(',', $sources));

    $criteria = [
        'ville' => $_GET['ville'] ?? '',
        'type' => $_GET['type'] ?? '',
        'budget_max' => (int) ($_GET['budget_max'] ?? 0),
        'budget_min' => (int) ($_GET['budget_min'] ?? 0),
        'surface_min' => (int) ($_GET['surface_min'] ?? 0),
    ];

    if ($clientId) {
        // Récupérer les critères depuis le dossier acheteur.
        $st = db()->prepare("SELECT data FROM clients WHERE id = ? AND user_id = ?");
        $st->execute([$clientId, $uid]);
        $row = $st->fetch();
        if ($row) {
            $data = json_decode($row['data'] ?? '{}', true) ?: [];
            $bien = $data['bien'] ?? [];
            $criteria['ville'] = $criteria['ville'] ?: ($bien['ville'] ?? '');
            $criteria['budget_max'] = $criteria['budget_max'] ?: (int) ($data['budget_max'] ?? 0);
            $criteria['surface_min'] = $criteria['surface_min'] ?: (int) ($bien['surface_hab'] ?? 0);
        }
    }

    $adapters = [
        'seloger' => new SeLogerAdapter(),
        'bienici' => new BienIciAdapter(),
        'fb_marketplace' => new FBAdapter(),
        'instagram' => new InstagramAdapter(),
    ];

    $cacheTtl = ['seloger' => 6 * 3600, 'bienici' => 6 * 3600, 'fb_marketplace' => 24 * 3600, 'instagram' => 86400];
    $output = [];
    $meta = metaPdo();

    foreach ($sources as $src) {
        if (!isset($adapters[$src])) {
            $output[$src] = ['results' => [], 'error' => 'unknown_source'];
            continue;
        }
        $hash = hash('sha256', $src . ':' . json_encode($criteria, JSON_UNESCAPED_UNICODE));
        // Check cache
        $st = $meta->prepare("SELECT results FROM external_search_cache WHERE source = ? AND query_hash = ? AND expires_at > NOW() ORDER BY id DESC LIMIT 1");
        $st->execute([$src, $hash]);
        $cached = $st->fetch();
        if ($cached) {
            $output[$src] = ['results' => json_decode($cached['results'], true) ?: [], 'error' => null, 'cached' => true];
            continue;
        }
        // Fresh fetch
        $res = $adapters[$src]->search($criteria);
        $output[$src] = $res + ['cached' => false];
        // Store cache (même les empty + error pour éviter spam)
        $ttl = $cacheTtl[$src] ?? 3600;
        try {
            $meta->prepare("INSERT INTO external_search_cache (source, query_hash, results, result_count, expires_at) VALUES (?, ?, ?, ?, DATE_ADD(NOW(), INTERVAL $ttl SECOND))")
                ->execute([$src, $hash, json_encode($res['results'] ?? [], JSON_UNESCAPED_UNICODE), count($res['results'] ?? [])]);
        } catch (Throwable $e) {}
    }

    jsonOk(['results' => $output, 'criteria' => $criteria]);
}

if ($action === 'import') {
    $listing = $_POST['listing'] ?? json_decode(file_get_contents('php://input'), true)['listing'] ?? null;
    if (!is_array($listing)) jsonError('listing requis', 400);
    $title = $listing['title'] ?? 'Annonce externe';
    $url = $listing['url'] ?? '';
    $data = [
        'profil_type' => 'Particulier',
        'prix_affiche' => $listing['price'] ?? null,
        'devise' => $listing['currency'] ?? 'EUR',
        'bien' => [
            'type' => 'Appartement',
            'ville' => $listing['location_text'] ?? '',
            'surface_hab' => $listing['surface'] ?? null,
            'chambres_v2' => $listing['rooms'] ?? null,
            'photos_externes' => $listing['photos'] ?? [],
        ],
        'source_externe' => [
            'source' => $listing['source'] ?? '',
            'source_url' => $url,
            'scraped_at' => $listing['scraped_at'] ?? date('c'),
        ],
        'notes' => "Importé de {$listing['source']} le " . date('Y-m-d H:i') . "\nURL: {$url}\n\n" . ($listing['description'] ?? ''),
    ];
    $st = db()->prepare(
        "INSERT INTO clients (user_id, projet, vertical, prenom, nom, data, is_draft, created_at, updated_at)
         VALUES (?, 'Vendeur', 'vente', ?, '', ?, 1, NOW(), NOW())"
    );
    $st->execute([$uid, mb_substr($title, 0, 100), json_encode($data, JSON_UNESCAPED_UNICODE)]);
    jsonOk(['client_id' => (int) db()->lastInsertId()]);
}

jsonError('Action inconnue (launch | import)', 400);
