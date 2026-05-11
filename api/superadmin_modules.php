<?php
// M/2026/05/11/25 — Modules Oi state (toggle ACTIF/SOON/DISABLED).
// Etat persiste dans /var/lib/atelier/ocre_modules_state.json.
//   GET  ?action=list                          → état des 7 modules
//   POST ?action=set_state body {slug, state}  → state: active | soon | disabled
require_once __DIR__ . '/lib/router.php';
require_once __DIR__ . '/lib/sa_audit.php';
require_once __DIR__ . '/lib/audit_logs.php';
header('Content-Type: application/json; charset=utf-8');

function mo_out(array $d, int $code = 200) { http_response_code($code); echo json_encode($d, JSON_UNESCAPED_UNICODE); exit; }

$user = current_user_or_401();
if (($user['role'] ?? '') !== 'super_admin') mo_out(['ok' => false, 'error' => 'super_admin only'], 403);

$STATE_FILE = '/var/lib/atelier/ocre_modules_state.json';
$DEFAULT = [
    'agent'     => ['label' => 'Oi Agent',     'state' => 'active',   'order' => 1],
    'scan'      => ['label' => 'Oi Scan',      'state' => 'soon',     'order' => 2],
    'book'      => ['label' => 'Oi Book',      'state' => 'soon',     'order' => 3],
    'recherche' => ['label' => 'Oi Recherche', 'state' => 'soon',     'order' => 4],
    'capture'   => ['label' => 'Oi Capture',   'state' => 'soon',     'order' => 5],
    'estimer'   => ['label' => 'Oi Estimer',   'state' => 'soon',     'order' => 6],
    'demande'   => ['label' => 'Oi Demande',   'state' => 'disabled', 'order' => 7],
];
$ALLOWED_STATES = ['active', 'soon', 'disabled'];

function mo_load(string $f, array $def): array {
    if (!is_readable($f)) return $def;
    $j = json_decode((string) file_get_contents($f), true);
    if (!is_array($j)) return $def;
    foreach ($def as $k => $v) if (!isset($j[$k])) $j[$k] = $v;
    return $j;
}
function mo_save(string $f, array $s): void {
    @file_put_contents($f, json_encode($s, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
    @chmod($f, 0664);
}

$action = $_GET['action'] ?? 'list';
$state = mo_load($STATE_FILE, $DEFAULT);

if ($action === 'list') {
    $out = [];
    foreach ($state as $slug => $m) $out[] = ['slug' => $slug] + $m;
    usort($out, fn($a, $b) => ($a['order'] ?? 99) <=> ($b['order'] ?? 99));
    mo_out(['ok' => true, 'modules' => $out]);
}

if ($action === 'set_state' && ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    $input = json_decode(file_get_contents('php://input'), true) ?: [];
    $slug = (string) ($input['slug'] ?? '');
    $newState = (string) ($input['state'] ?? '');
    if (!isset($state[$slug])) mo_out(['ok' => false, 'error' => 'unknown_module'], 404);
    if (!in_array($newState, $ALLOWED_STATES, true)) mo_out(['ok' => false, 'error' => 'invalid_state'], 400);
    $old = $state[$slug]['state'];
    $state[$slug]['state'] = $newState;
    mo_save($STATE_FILE, $state);
    sa_audit_meta((int) $user['id'], 'module.set_state', ['slug' => $slug, 'from' => $old, 'to' => $newState]);
    mo_out(['ok' => true, 'slug' => $slug, 'state' => $newState]);
}

mo_out(['ok' => false, 'error' => 'unknown action: ' . $action], 400);
