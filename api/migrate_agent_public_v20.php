<?php
// V20 — one-shot IP-whitelist. Ajoute 16 colonnes profil public + 2 index à users.
require_once __DIR__ . '/db.php';
$ip = $_SERVER['REMOTE_ADDR'] ?? '';
if ($ip !== '46.225.215.148') { http_response_code(403); exit('Forbidden'); }

$out = [];
$cols = [
    ['photo_url', "ALTER TABLE users ADD COLUMN photo_url VARCHAR(500) NULL DEFAULT NULL"],
    ['slug', "ALTER TABLE users ADD COLUMN slug VARCHAR(191) NULL DEFAULT NULL"],
    ['tagline', "ALTER TABLE users ADD COLUMN tagline VARCHAR(255) NULL DEFAULT NULL"],
    ['bio', "ALTER TABLE users ADD COLUMN bio TEXT NULL DEFAULT NULL"],
    ['telephone_pro', "ALTER TABLE users ADD COLUMN telephone_pro VARCHAR(50) NULL DEFAULT NULL"],
    ['email_pro', "ALTER TABLE users ADD COLUMN email_pro VARCHAR(191) NULL DEFAULT NULL"],
    ['whatsapp_pro', "ALTER TABLE users ADD COLUMN whatsapp_pro VARCHAR(50) NULL DEFAULT NULL"],
    ['zones_intervention', "ALTER TABLE users ADD COLUMN zones_intervention JSON NULL DEFAULT NULL"],
    ['specialites', "ALTER TABLE users ADD COLUMN specialites JSON NULL DEFAULT NULL"],
    ['carte_pro_numero', "ALTER TABLE users ADD COLUMN carte_pro_numero VARCHAR(100) NULL DEFAULT NULL"],
    ['carte_pro_prefecture', "ALTER TABLE users ADD COLUMN carte_pro_prefecture VARCHAR(191) NULL DEFAULT NULL"],
    ['carte_pro_date_fin', "ALTER TABLE users ADD COLUMN carte_pro_date_fin DATE NULL DEFAULT NULL"],
    ['rcp_assureur', "ALTER TABLE users ADD COLUMN rcp_assureur VARCHAR(191) NULL DEFAULT NULL"],
    ['rcp_numero_police', "ALTER TABLE users ADD COLUMN rcp_numero_police VARCHAR(100) NULL DEFAULT NULL"],
    ['rcp_montant_garantie', "ALTER TABLE users ADD COLUMN rcp_montant_garantie VARCHAR(50) NULL DEFAULT NULL"],
    ['statut_public', "ALTER TABLE users ADD COLUMN statut_public ENUM('brouillon','actif','suspendu') NOT NULL DEFAULT 'brouillon'"],
    ['idx_user_slug', "ALTER TABLE users ADD UNIQUE INDEX idx_user_slug (slug)"],
    ['idx_statut_public', "ALTER TABLE users ADD INDEX idx_statut_public (statut_public)"],
];
foreach ($cols as [$label, $sql]) {
    try { db()->exec($sql); $out[$label] = 'added'; }
    catch (Exception $e) { $out[$label] = 'exists: ' . substr($e->getMessage(), 0, 80); }
}

header('Content-Type: application/json; charset=utf-8');
echo json_encode(['ok' => true, 'result' => $out], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
