<?php
// M/2026/05/12/30 — Design tokens public GET endpoint exposé sur auth.ocre.immo.
// Wrapper standalone (ocre-auth utilise auth_db.php, pas le router.php d'ocre-app).
// GET public uniquement (lecture des 3 tokens). POST/DELETE non exposés ici (panneau Apparence
// superadmin utilise l'endpoint ocre-app via sa propre auth super_admin).

require_once __DIR__ . '/../lib/auth_db.php';
header('Content-Type: application/json; charset=utf-8');

// CORS allow vitrine WP ocre.immo + www
$origin = $_SERVER['HTTP_ORIGIN'] ?? '';
$allowedOrigins = ['https://ocre.immo', 'https://www.ocre.immo', 'https://auth.ocre.immo'];
if (in_array($origin, $allowedOrigins, true)) {
    header('Access-Control-Allow-Origin: ' . $origin);
    header('Access-Control-Allow-Methods: GET, OPTIONS');
    header('Access-Control-Allow-Headers: Content-Type');
}
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') { http_response_code(204); exit; }
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'GET only on this endpoint']);
    exit;
}

$meta = auth_db();
// Auto-create table + seed defaults si absent (idempotent, identique a /opt/ocre-app/api/design-tokens.php).
$meta->exec("CREATE TABLE IF NOT EXISTS design_tokens (
    `key` VARCHAR(64) PRIMARY KEY,
    `value` VARCHAR(32) NOT NULL,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    updated_by INT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
$defaults = [
    'color_field_empty'   => '#FAF6F0',
    'color_field_filled'  => '#E8F5E9',
    'color_field_invalid' => '#FEE8E8',
];
foreach ($defaults as $k => $v) {
    $meta->prepare("INSERT IGNORE INTO design_tokens (`key`, `value`) VALUES (?, ?)")->execute([$k, $v]);
}

$rows = $meta->query("SELECT `key`, `value` FROM design_tokens")->fetchAll();
$tokens = [];
foreach ($rows as $r) { $tokens[$r['key']] = $r['value']; }
header('Cache-Control: public, max-age=300');
echo json_encode($tokens, JSON_UNESCAPED_UNICODE);
