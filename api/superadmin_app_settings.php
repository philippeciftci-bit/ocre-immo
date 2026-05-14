<?php
// M/2026/05/11/39 — Endpoint app_settings global (Variant A/B + taux EUR/MAD).
//   GET  ?action=get                       → liste tous les settings (PUBLIC, lu par SPA tenant au boot)
//   POST ?action=set body {key, value}     → met a jour 1 setting (super_admin only, whitelist keys)

require_once __DIR__ . '/lib/router.php';
require_once __DIR__ . '/lib/sa_audit.php';
header('Content-Type: application/json; charset=utf-8');

function asj(array $d, int $code = 200) { http_response_code($code); echo json_encode($d, JSON_UNESCAPED_UNICODE); exit; }

// CORS pour SPA tenants + superadmin
$origin = $_SERVER['HTTP_ORIGIN'] ?? '';
if (preg_match('#^https://[a-z0-9-]+\.ocre\.immo$#', $origin)) {
    header('Access-Control-Allow-Origin: ' . $origin);
    header('Access-Control-Allow-Credentials: true');
    header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
    header('Access-Control-Allow-Headers: Content-Type');
}
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') { http_response_code(204); exit; }

$action = $_GET['action'] ?? 'get';

require_once __DIR__ . '/db.php';
$meta = pdo_meta();

// Garantit la table (idempotent au cas ou environnement neuf)
try {
    $meta->exec("CREATE TABLE IF NOT EXISTS app_settings (
        setting_key VARCHAR(64) PRIMARY KEY,
        setting_value TEXT NOT NULL,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
    $meta->exec("INSERT IGNORE INTO app_settings (setting_key, setting_value) VALUES ('price_display_variant', 'A'), ('exchange_rate_eur_mad', '10.84')");
} catch (Throwable $e) { /* swallow */ }

if ($action === 'get') {
    // PUBLIC : pas d'auth requise (SPA tenant lit au boot pour configurer DualCurrencyPair)
    $rows = $meta->query("SELECT setting_key, setting_value FROM app_settings")->fetchAll(PDO::FETCH_KEY_PAIR);
    // Defaults applies si DB vide
    $defaults = ['price_display_variant' => 'A', 'exchange_rate_eur_mad' => '10.84'];
    $settings = array_merge($defaults, $rows);
    asj(['ok' => true, 'settings' => $settings, 'generated_at' => date('c')]);
}

if ($action === 'set' && ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    // Gate super_admin only
    $user = current_user_or_401();
    if (($user['role'] ?? '') !== 'super_admin') asj(['ok' => false, 'error' => 'super_admin only'], 403);

    $input = json_decode(file_get_contents('php://input'), true) ?: [];
    $key = (string) ($input['key'] ?? '');
    $value = (string) ($input['value'] ?? '');

    // Whitelist keys + validation valeur
    $WHITELIST = [
        'price_display_variant' => ['A', 'B'],
        'exchange_rate_eur_mad' => null, // numeric check below
        'autofill_pill_colors'  => null, // JSON object {bg,border,text} 3 hex colors, validated below
    ];
    if (!array_key_exists($key, $WHITELIST)) asj(['ok' => false, 'error' => 'unknown_key'], 400);
    if ($WHITELIST[$key] !== null && !in_array($value, $WHITELIST[$key], true)) asj(['ok' => false, 'error' => 'invalid_value', 'allowed' => $WHITELIST[$key]], 400);
    if ($key === 'exchange_rate_eur_mad') {
        $f = (float) $value;
        if ($f <= 0 || $f > 100) asj(['ok' => false, 'error' => 'invalid_rate'], 400);
        $value = (string) $f;
    }
    // M/2026/05/14/32 — validation autofill_pill_colors : JSON object 3 fields hex couleurs.
    if ($key === 'autofill_pill_colors') {
        $obj = json_decode($value, true);
        if (!is_array($obj) || !isset($obj['bg'], $obj['border'], $obj['text'])) {
            asj(['ok' => false, 'error' => 'invalid_value', 'detail' => 'expected JSON {bg,border,text}'], 400);
        }
        $hex = '/^#[0-9A-Fa-f]{3,8}$/';
        foreach (['bg', 'border', 'text'] as $f) {
            if (!preg_match($hex, $obj[$f])) {
                asj(['ok' => false, 'error' => 'invalid_color', 'detail' => $f . ' must match #RGB|#RRGGBB|#RRGGBBAA'], 400);
            }
        }
        $value = json_encode($obj);
    }

    try {
        $st = $meta->prepare("INSERT INTO app_settings (setting_key, setting_value) VALUES (?, ?) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)");
        $st->execute([$key, $value]);
        sa_audit_meta((int) $user['id'], 'app_settings.set', ['key' => $key, 'value' => $value]);
        asj(['ok' => true, 'key' => $key, 'value' => $value]);
    } catch (Throwable $e) { asj(['ok' => false, 'error' => $e->getMessage()], 500); }
}

asj(['ok' => false, 'error' => 'unknown_action: ' . $action], 400);
