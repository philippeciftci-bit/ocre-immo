<?php
// M/2026/05/12/21 — Design tokens centralises (3 etats champs : vide/valide/invalide + futurs).
// GET  public : retourne JSON des tokens courants (cache 5min) — utilise au boot par vitrine, app, superadmin.
// POST super_admin only : update tokens (panneau Apparence superadmin).
//
// Schema DB ocre_meta.design_tokens auto-cree au premier appel.

require_once __DIR__ . '/lib/router.php';
header('Content-Type: application/json; charset=utf-8');

// M/2026/05/12/30 — CORS allow vitrine WP ocre.immo + auth.ocre.immo (volet manquant M/12/21).
$origin = $_SERVER['HTTP_ORIGIN'] ?? '';
$allowedOrigins = ['https://ocre.immo', 'https://www.ocre.immo', 'https://auth.ocre.immo'];
if (in_array($origin, $allowedOrigins, true)) {
    header('Access-Control-Allow-Origin: ' . $origin);
    header('Access-Control-Allow-Methods: GET, OPTIONS');
    header('Access-Control-Allow-Headers: Content-Type');
}
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') { http_response_code(204); exit; }

function jout(array $d, int $code = 200) {
    http_response_code($code);
    echo json_encode($d, JSON_UNESCAPED_UNICODE);
    exit;
}

$meta = pdo_meta();

// Auto-create table + seed defaults (idempotent).
function ensureDesignTokensSchema(PDO $meta) {
    static $done = false;
    if ($done) return;
    $meta->exec("CREATE TABLE IF NOT EXISTS design_tokens (
        `key` VARCHAR(64) PRIMARY KEY,
        `value` VARCHAR(32) NOT NULL,
        updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        updated_by INT
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    // Seed defaults si absent
    $defaults = [
        'color_field_empty'   => '#FAF6F0',
        'color_field_filled'  => '#E8F5E9',
        'color_field_invalid' => '#FEE8E8',
    ];
    foreach ($defaults as $k => $v) {
        $meta->prepare("INSERT IGNORE INTO design_tokens (`key`, `value`) VALUES (?, ?)")->execute([$k, $v]);
    }
    $done = true;
}
ensureDesignTokensSchema($meta);

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

if ($method === 'GET') {
    // Public : pas d'auth requise (juste du CSS).
    $rows = $meta->query("SELECT `key`, `value` FROM design_tokens")->fetchAll();
    $tokens = [];
    foreach ($rows as $r) { $tokens[$r['key']] = $r['value']; }
    // Cache 5 min cote CDN/browser. Les tokens changent rarement.
    header('Cache-Control: public, max-age=300');
    jout($tokens);
}

if ($method === 'POST') {
    // Auth super_admin obligatoire.
    $user = current_user_or_401();
    if (($user['role'] ?? '') !== 'super_admin') jout(['ok' => false, 'error' => 'super_admin only'], 403);

    $raw = file_get_contents('php://input');
    $input = is_array(($j = json_decode($raw, true))) ? $j : [];
    if (!is_array($input) || count($input) === 0) jout(['ok' => false, 'error' => 'payload empty'], 400);
    if (count($input) > 20) jout(['ok' => false, 'error' => 'too many keys (max 20)'], 400);

    // Validation hex color format strict (#RGB ou #RRGGBB).
    $hexRe = '/^#([A-Fa-f0-9]{3}|[A-Fa-f0-9]{6})$/';
    $allowedKeys = ['color_field_empty', 'color_field_filled', 'color_field_invalid'];
    $updated = [];
    try {
        $meta->beginTransaction();
        foreach ($input as $k => $v) {
            if (!in_array($k, $allowedKeys, true)) continue; // ignore unknown keys
            $v = (string)$v;
            if (!preg_match($hexRe, $v)) {
                $meta->rollBack();
                jout(['ok' => false, 'error' => "invalid hex color for $k: $v"], 400);
            }
            $meta->prepare("INSERT INTO design_tokens (`key`, `value`, updated_by) VALUES (?, ?, ?)
                            ON DUPLICATE KEY UPDATE `value` = VALUES(`value`), updated_by = VALUES(updated_by)")
                ->execute([$k, $v, (int)$user['id']]);
            $updated[$k] = $v;
        }
        $meta->commit();
    } catch (Throwable $e) {
        if ($meta->inTransaction()) $meta->rollBack();
        jout(['ok' => false, 'error' => 'transaction failed: '.$e->getMessage()], 500);
    }
    @file_put_contents('/var/log/ocre-superadmin-actions.log',
        '['.date('c').'] sa#'.$user['id'].' design_tokens_update '.json_encode($updated)."\n", FILE_APPEND);
    jout(['ok' => true, 'updated' => $updated, 'count' => count($updated)]);
}

if ($method === 'DELETE') {
    // Auth super_admin obligatoire : reset aux valeurs par defaut.
    $user = current_user_or_401();
    if (($user['role'] ?? '') !== 'super_admin') jout(['ok' => false, 'error' => 'super_admin only'], 403);

    $meta->exec("DELETE FROM design_tokens WHERE `key` IN ('color_field_empty','color_field_filled','color_field_invalid')");
    // Re-seed
    ensureDesignTokensSchema($meta);
    @file_put_contents('/var/log/ocre-superadmin-actions.log',
        '['.date('c').'] sa#'.$user['id'].' design_tokens_reset_defaults'."\n", FILE_APPEND);
    jout(['ok' => true, 'reset' => true]);
}

jout(['ok' => false, 'error' => 'method not allowed'], 405);
