<?php
// V18.47 — one-shot IP-whitelist. Ajoute colonnes publication à clients + backfill vertical.
require_once __DIR__ . '/db.php';
$ip = $_SERVER['REMOTE_ADDR'] ?? '';
if ($ip !== '46.225.215.148') { http_response_code(403); exit('Forbidden'); }

$out = [];
$tries = [
    ['is_published', "ALTER TABLE clients ADD COLUMN is_published TINYINT(1) NOT NULL DEFAULT 0"],
    ['published_at', "ALTER TABLE clients ADD COLUMN published_at TIMESTAMP NULL DEFAULT NULL"],
    ['public_slug', "ALTER TABLE clients ADD COLUMN public_slug VARCHAR(191) NULL DEFAULT NULL"],
    ['public_title', "ALTER TABLE clients ADD COLUMN public_title VARCHAR(255) NULL DEFAULT NULL"],
    ['public_description', "ALTER TABLE clients ADD COLUMN public_description TEXT NULL DEFAULT NULL"],
    ['public_visible', "ALTER TABLE clients ADD COLUMN public_visible TINYINT(1) NOT NULL DEFAULT 1"],
    ['public_views_count', "ALTER TABLE clients ADD COLUMN public_views_count INT UNSIGNED NOT NULL DEFAULT 0"],
    ['public_contacts_count', "ALTER TABLE clients ADD COLUMN public_contacts_count INT UNSIGNED NOT NULL DEFAULT 0"],
    ['vertical', "ALTER TABLE clients ADD COLUMN vertical ENUM('vente','location_longue','sejour_court') NULL DEFAULT NULL"],
    ['idx_public_slug', "ALTER TABLE clients ADD UNIQUE INDEX idx_public_slug (public_slug)"],
    ['idx_is_published', "ALTER TABLE clients ADD INDEX idx_is_published (is_published, public_visible)"],
];
foreach ($tries as [$label, $sql]) {
    try { db()->exec($sql); $out[$label] = 'added'; }
    catch (Exception $e) { $out[$label] = 'exists-or-err: ' . substr($e->getMessage(), 0, 120); }
}

// Backfill vertical depuis projet. Note: la colonne s'appelle `projet` dans clients
// ('Vendeur', 'Bailleur', 'Acheteur', 'Locataire'). Cf audit multi-tenant.
try {
    $u1 = db()->prepare("UPDATE clients SET vertical = 'vente' WHERE (projet = 'Vendeur' OR LOWER(projet) = 'vendeur') AND vertical IS NULL");
    $u1->execute(); $out['backfill_vente'] = $u1->rowCount();
    $u2 = db()->prepare("UPDATE clients SET vertical = 'location_longue' WHERE (projet = 'Bailleur' OR LOWER(projet) = 'bailleur') AND vertical IS NULL");
    $u2->execute(); $out['backfill_location_longue'] = $u2->rowCount();
} catch (Exception $e) { $out['backfill_err'] = $e->getMessage(); }

header('Content-Type: application/json; charset=utf-8');
echo json_encode(['ok' => true, 'result' => $out], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
