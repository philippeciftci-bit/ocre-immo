<?php
// V20 phase 4 — endpoint workspace : retourne contexte courant + settings branding.
require_once __DIR__ . '/lib/router.php';
header('Content-Type: application/json; charset=utf-8');

$action = $_GET['action'] ?? 'context';

function jout(array $d, int $code = 200) {
    http_response_code($code);
    echo json_encode($d, JSON_UNESCAPED_UNICODE);
    exit;
}

switch ($action) {
case 'context': {
    $ctx = resolve_workspace_context();
    $pdo = pdo_workspace($ctx['db_name']);
    $branding = [];
    try {
        foreach ($pdo->query("SELECT k, v FROM settings_branding") as $r) {
            $branding[$r['k']] = $r['v'];
        }
    } catch (Throwable $e) {}
    jout([
        'ok' => true,
        'workspace' => [
            'id' => (int)$ctx['workspace']['id'],
            'slug' => $ctx['workspace']['slug'],
            'type' => $ctx['workspace']['type'],
            'display_name' => $ctx['workspace']['display_name'],
            'country_code' => $ctx['workspace']['country_code'],
        ],
        'mode' => $ctx['mode'],
        'is_super_admin' => $ctx['is_super_admin'],
        'is_readonly' => $ctx['is_readonly'],
        'membership_role' => $ctx['membership']['role'] ?? null,
        'branding' => $branding,
    ]);
}

case 'set_branding': {
    $ctx = resolve_workspace_context();
    require_write_access($ctx);
    if (!($ctx['membership'] ?? null) || $ctx['membership']['role'] !== 'owner') {
        jout(['ok' => false, 'error' => 'owner only'], 403);
    }
    if ($ctx['workspace']['type'] !== 'wsp') jout(['ok' => false, 'error' => 'WSp only'], 400);
    $input = json_decode(file_get_contents('php://input'), true) ?: [];
    $allowed = ['primary_color', 'logo_path', 'display_name'];
    $pdo = pdo_workspace($ctx['db_name']);
    $stmt = $pdo->prepare("INSERT INTO settings_branding (k, v) VALUES (?, ?) ON DUPLICATE KEY UPDATE v=VALUES(v)");
    foreach ($allowed as $k) {
        if (array_key_exists($k, $input)) $stmt->execute([$k, (string)$input[$k]]);
    }
    jout(['ok' => true]);
}

default:
    jout(['ok' => false, 'error' => 'action inconnue'], 400);
}
