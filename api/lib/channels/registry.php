<?php
// M104 — Registry des drivers Channel manager. Resolu par nom.
// Sert au worker + endpoints publish/unpublish.

require_once __DIR__ . '/ChannelDriver.php';
require_once __DIR__ . '/leboncoin.php';
require_once __DIR__ . '/seloger.php';
require_once __DIR__ . '/bienici.php';

function channel_driver(string $name): ?ChannelDriver {
    switch ($name) {
        case 'leboncoin': return new LeBonCoinDriver();
        case 'seloger': return new SeLogerDriver();
        case 'bienici': return new BienIciDriver();
        // M105: idealista, apartments_com
        // M106: avito_ma, mubawab
        default: return null;
    }
}

function channel_available_portals(): array {
    return [
        ['name' => 'leboncoin', 'display' => 'LeBonCoin Pro', 'logo_color' => '#ec5a13', 'region' => 'France', 'status_v' => 'active', 'sub_mission' => 'M104'],
        ['name' => 'seloger', 'display' => 'SeLoger', 'logo_color' => '#c4001d', 'region' => 'France', 'status_v' => 'active', 'sub_mission' => 'M104'],
        ['name' => 'bienici', 'display' => "Bien'ici", 'logo_color' => '#1c2c5b', 'region' => 'France', 'status_v' => 'active', 'sub_mission' => 'M104'],
        ['name' => 'idealista', 'display' => 'Idealista', 'logo_color' => '#ab2222', 'region' => 'Espagne', 'status_v' => 'soon', 'sub_mission' => 'M105'],
        ['name' => 'apartments_com', 'display' => 'Apartments.com', 'logo_color' => '#2c5e1a', 'region' => 'USA', 'status_v' => 'soon', 'sub_mission' => 'M105'],
        ['name' => 'avito_ma', 'display' => 'Avito.ma', 'logo_color' => '#fab50a', 'region' => 'Maroc', 'status_v' => 'soon', 'sub_mission' => 'M106'],
        ['name' => 'mubawab', 'display' => 'Mubawab', 'logo_color' => '#00a651', 'region' => 'Maroc', 'status_v' => 'soon', 'sub_mission' => 'M106'],
    ];
}

function channel_meta_pdo(): PDO {
    static $pdo = null;
    if ($pdo === null) {
        require_once __DIR__ . '/../../db.php';
        $pdo = new PDO(
            'mysql:host=' . DB_HOST . ';dbname=ocre_meta;charset=utf8mb4',
            DB_USER, DB_PASS,
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
        );
    }
    return $pdo;
}

function channel_ensure_schema(): void {
    $db = channel_meta_pdo();
    $db->exec("CREATE TABLE IF NOT EXISTS channel_mappings (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        tenant_slug VARCHAR(64) NOT NULL,
        dossier_id INT UNSIGNED NOT NULL,
        channel_name VARCHAR(32) NOT NULL,
        external_listing_id VARCHAR(128) NULL,
        status ENUM('pending','syncing','published','refused','expired','deleted') NOT NULL DEFAULT 'pending',
        error_message TEXT NULL,
        last_synced_at DATETIME NULL,
        next_sync_at DATETIME NULL,
        retry_count INT UNSIGNED DEFAULT 0,
        views INT UNSIGNED DEFAULT 0,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY uniq_tenant_dossier_channel (tenant_slug, dossier_id, channel_name),
        INDEX idx_status (status),
        INDEX idx_next_sync (next_sync_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $db->exec("CREATE TABLE IF NOT EXISTS channel_logs (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        mapping_id INT UNSIGNED NOT NULL,
        action VARCHAR(32) NOT NULL,
        payload JSON NULL,
        response JSON NULL,
        status_code INT NULL,
        duration_ms INT NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_mapping (mapping_id),
        INDEX idx_created (created_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $db->exec("CREATE TABLE IF NOT EXISTS channel_subscriptions (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        tenant_slug VARCHAR(64) NOT NULL,
        channel_name VARCHAR(32) NOT NULL,
        active TINYINT(1) NOT NULL DEFAULT 0,
        credentials JSON NULL,
        enabled_at DATETIME NULL,
        disabled_at DATETIME NULL,
        UNIQUE KEY uniq_tenant_channel (tenant_slug, channel_name),
        INDEX idx_active (active)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $db->exec("CREATE TABLE IF NOT EXISTS channel_queue (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        mapping_id INT UNSIGNED NOT NULL,
        job_type VARCHAR(32) NOT NULL,
        priority TINYINT NOT NULL DEFAULT 5,
        scheduled_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        picked_at DATETIME NULL,
        worker_id VARCHAR(64) NULL,
        status ENUM('queued','processing','done','failed') NOT NULL DEFAULT 'queued',
        attempts INT UNSIGNED DEFAULT 0,
        last_error TEXT NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_status_scheduled (status, scheduled_at),
        INDEX idx_mapping (mapping_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
}

function channel_get_dossier(string $tenant_slug, int $dossier_id): ?array {
    require_once __DIR__ . '/../../db.php';
    $tenantDb = 'ocre_wsp_' . $tenant_slug;
    try {
        $pdo = new PDO(
            'mysql:host=' . DB_HOST . ';dbname=' . $tenantDb . ';charset=utf8mb4',
            DB_USER, DB_PASS,
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
        );
        // Tenant table is "clients" in this schema
        $st = $pdo->prepare("SELECT * FROM clients WHERE id = ? LIMIT 1");
        $st->execute([$dossier_id]);
        $r = $st->fetch();
        return $r ?: null;
    } catch (Throwable $e) {
        @error_log('[channel_get_dossier] err: ' . $e->getMessage());
        return null;
    }
}

// Convertit un dossier tenant (clients) en listing normalise pour les drivers.
function channel_dossier_to_listing(array $dossier): array {
    $titre = trim(($dossier['nom'] ?? '') . ' ' . ($dossier['prenom'] ?? '')) ?: ('Dossier #' . ($dossier['id'] ?? 0));
    $desc = $dossier['notes'] ?? $dossier['description'] ?? '';
    if (mb_strlen($desc) < 200) {
        $desc .= "\n\n" . 'Bien immobilier propose par un agent du reseau Ocre Immo. Visites organisees sur rendez-vous, dossier disponible sur demande.';
    }
    $price = (float) ($dossier['budget_max'] ?? $dossier['prix_vendeur_ttc'] ?? $dossier['budget_min'] ?? 0);
    $photos = [];
    foreach (range(1, 6) as $i) {
        $url = $dossier['photo_' . $i] ?? null;
        if ($url) $photos[] = $url;
    }
    if (count($photos) === 0 && !empty($dossier['photos'])) {
        $arr = is_string($dossier['photos']) ? @json_decode($dossier['photos'], true) : $dossier['photos'];
        if (is_array($arr)) $photos = $arr;
    }
    return [
        'reference' => 'DOS-' . ($dossier['id'] ?? '0'),
        'title' => $titre,
        'description' => $desc,
        'price' => $price,
        'currency' => $dossier['currency'] ?? 'EUR',
        'transaction_type' => ($dossier['projet'] ?? '') === 'Bailleur' || ($dossier['projet'] ?? '') === 'Locataire' ? 'rent' : 'sale',
        'real_estate_type' => $dossier['type_bien'] ?? 'apartment',
        'category' => 'real_estate',
        'surface_m2' => $dossier['surface_m2'] ?? null,
        'rooms' => $dossier['nb_pieces'] ?? null,
        'bedrooms' => $dossier['nb_chambres'] ?? null,
        'photos' => $photos,
        'location' => [
            'city' => $dossier['ville'] ?? '',
            'zipcode' => $dossier['cp'] ?? '',
            'lat' => $dossier['latitude'] ?? null,
            'lng' => $dossier['longitude'] ?? null,
        ],
        'dpe' => $dossier['dpe'] ?? null,
    ];
}
