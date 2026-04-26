<?php
// V20 phase 5 — endpoint custom_fields list / toggle.
require_once __DIR__ . '/lib/router.php';
require_once __DIR__ . '/lib/custom_fields_catalog.php';
header('Content-Type: application/json; charset=utf-8');

$action = $_GET['action'] ?? 'list';

function jout(array $d, int $code = 200) {
    http_response_code($code);
    echo json_encode($d, JSON_UNESCAPED_UNICODE);
    exit;
}

$ctx = resolve_workspace_context();
$pdo = pdo_workspace($ctx['db_name']);

switch ($action) {
case 'list': {
    $enabled = [];
    try {
        foreach ($pdo->query("SELECT field_key, enabled, label_override FROM custom_fields_enabled") as $r) {
            $enabled[$r['field_key']] = ['enabled' => (bool)$r['enabled'], 'label_override' => $r['label_override']];
        }
    } catch (Throwable $e) {}
    $out = [];
    foreach (CUSTOM_FIELDS_CATALOG as $key => $def) {
        $st = $enabled[$key] ?? ['enabled' => false, 'label_override' => null];
        $out[] = array_merge(['key' => $key], $def, $st);
    }
    jout(['ok' => true, 'fields' => $out]);
}

case 'toggle': {
    require_write_access($ctx);
    if ($ctx['mode'] !== 'agent') jout(['ok' => false, 'error' => 'agent mode only'], 403);
    $input = json_decode(file_get_contents('php://input'), true) ?: [];
    $key = (string)($input['field_key'] ?? '');
    $enabled = !empty($input['enabled']) ? 1 : 0;
    $label = $input['label_override'] ?? null;
    if (!isset(CUSTOM_FIELDS_CATALOG[$key])) jout(['ok' => false, 'error' => 'field_key invalide'], 400);
    $pdo->prepare(
        "INSERT INTO custom_fields_enabled (field_key, enabled, label_override) VALUES (?, ?, ?)
         ON DUPLICATE KEY UPDATE enabled=VALUES(enabled), label_override=VALUES(label_override)"
    )->execute([$key, $enabled, $label]);
    jout(['ok' => true]);
}

default:
    jout(['ok' => false, 'error' => 'action inconnue'], 400);
}
