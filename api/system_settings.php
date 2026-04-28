<?php
// M/2026/04/28/38 — Configuration globale ocre_meta.system_settings.
// Lecture libre (toute session valide). Update super_admin uniquement.
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/lib/router.php';
setCorsHeaders();

$user = requireAuth();
$action = $_GET['action'] ?? ($_POST['action'] ?? '');
$input = getInput();

function ensureSystemSettings() {
    static $done = false;
    if ($done) return;
    pdo_meta()->exec("CREATE TABLE IF NOT EXISTS system_settings (
        setting_key VARCHAR(100) NOT NULL PRIMARY KEY,
        setting_value TEXT NOT NULL,
        setting_type ENUM('int','string','bool','json') NOT NULL DEFAULT 'string',
        description TEXT NULL,
        updated_by INT UNSIGNED NULL,
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
    $defaults = [
        ['max_photos_per_dossier',    '20',   'int',    'Nombre maximum de photos par dossier'],
        ['max_photo_size_mb',         '25',   'int',    "Taille max d'une photo (Mo) avant compression"],
        ['photo_target_width_px',     '1920', 'int',    'Largeur cible après compression WebP (px)'],
        ['photo_quality_pct',         '80',   'int',    'Qualité WebP en pourcentage (1-100)'],
        ['photo_thumb_size_px',       '400',  'int',    'Taille des miniatures carrées (px)'],
        ['max_documents_per_dossier', '50',   'int',    'Nombre maximum de documents par dossier'],
        ['max_document_size_mb',      '10',   'int',    "Taille max d'un document (Mo)"],
    ];
    $stmt = pdo_meta()->prepare(
        "INSERT IGNORE INTO system_settings (setting_key, setting_value, setting_type, description)
         VALUES (?, ?, ?, ?)"
    );
    foreach ($defaults as $d) $stmt->execute($d);
    $done = true;
}

ensureSystemSettings();

switch ($action) {

case 'get': {
    $rows = pdo_meta()->query("SELECT setting_key, setting_value, setting_type, description, updated_at FROM system_settings ORDER BY setting_key")->fetchAll();
    $settings = [];
    foreach ($rows as $r) {
        $v = $r['setting_value'];
        if ($r['setting_type'] === 'int') $v = (int) $v;
        elseif ($r['setting_type'] === 'bool') $v = ($v === '1' || $v === 'true');
        elseif ($r['setting_type'] === 'json') $v = json_decode($v, true);
        $settings[$r['setting_key']] = ['value' => $v, 'type' => $r['setting_type'], 'description' => $r['description'], 'updated_at' => $r['updated_at']];
    }
    jsonOk(['settings' => $settings]);
}

case 'update': {
    $isSuper = ($user['role'] ?? '') === 'super_admin' || ($user['_origin_role'] ?? '') === 'super_admin';
    if (!$isSuper) jsonError('Accès refusé (super_admin requis)', 403);
    $key = (string) ($input['setting_key'] ?? '');
    $value = $input['setting_value'] ?? null;
    if (!$key) jsonError('setting_key requis', 400);
    $cur = pdo_meta()->prepare("SELECT setting_type FROM system_settings WHERE setting_key = ?");
    $cur->execute([$key]);
    $row = $cur->fetch();
    if (!$row) jsonError('Clé inconnue', 404);
    $stored = is_string($value) ? $value : json_encode($value, JSON_UNESCAPED_UNICODE);
    $upd = pdo_meta()->prepare(
        "UPDATE system_settings SET setting_value = ?, updated_by = ? WHERE setting_key = ?"
    );
    $upd->execute([$stored, (int) ($user['_origin_user_id'] ?? $user['id']), $key]);
    jsonOk(['setting_key' => $key, 'setting_value' => $stored]);
}

default:
    jsonError('Action inconnue (get | update)', 400);
}
