<?php
// GET /api/modules.php → état des 4 modules Oi.
// POST /api/modules.php → action: toggle_active (modifie un fichier de config simple)
require_once __DIR__ . '/_lib.php';
sa_cors();
$admin = sa_require_super_admin();

$STATE_FILE = '/var/lib/atelier/ocre_modules_state.json';
$DEFAULT = [
    'agent'   => ['label' => 'Oi Agent',   'active' => true,  'badge' => 'ACTIF',   'soon' => null],
    'scan'    => ['label' => 'Oi Scan',    'active' => false, 'badge' => 'BIENTÔT', 'soon' => 'Le module diagnostic bâtiment arrive très bientôt.'],
    'book'    => ['label' => 'Oi Book',    'active' => false, 'badge' => 'Q3 2026', 'soon' => 'Gestion locative — disponible Q3 2026.'],
    'demande' => ['label' => 'Oi Demande', 'active' => false, 'badge' => 'Q4 2026', 'soon' => 'Module demande mandant — Q4 2026.'],
];
function sa_modules_load(string $f, array $def): array {
    if (!is_readable($f)) return $def;
    $j = json_decode((string) file_get_contents($f), true);
    return is_array($j) ? array_replace_recursive($def, $j) : $def;
}
function sa_modules_save(string $f, array $state): void {
    @file_put_contents($f, json_encode($state, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
    @chmod($f, 0664);
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $data = json_decode(file_get_contents('php://input'), true) ?: [];
    $action = (string)($data['action'] ?? '');
    $key = (string)($data['module'] ?? '');
    $state = sa_modules_load($STATE_FILE, $DEFAULT);
    if (!isset($state[$key])) sa_send_json(['ok' => false, 'error' => 'unknown_module'], 400);
    if ($action === 'toggle_active') {
        $state[$key]['active'] = !$state[$key]['active'];
        $state[$key]['badge'] = $state[$key]['active'] ? 'ACTIF' : ($state[$key]['badge'] === 'ACTIF' ? 'PAUSE' : $state[$key]['badge']);
        sa_modules_save($STATE_FILE, $state);
        sa_audit((int)$admin['id'], 'module.toggle_active', $key, ['active' => $state[$key]['active']]);
        sa_send_json(['ok' => true, 'module' => $key, 'active' => $state[$key]['active']]);
    }
    sa_send_json(['ok' => false, 'error' => 'unknown_action'], 400);
}

$state = sa_modules_load($STATE_FILE, $DEFAULT);
$out = [];
foreach ($state as $key => $m) $out[] = ['key' => $key] + $m;
sa_send_json(['ok' => true, 'modules' => $out]);
