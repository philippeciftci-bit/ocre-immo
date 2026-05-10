<?php
// M104 — GET /api/channel/portals.php
// Retourne array portails disponibles + abonnement tenant courant.

require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../_session.php';
require_once __DIR__ . '/../lib/channels/registry.php';

setCorsHeaders();
$user = getCurrentUserDualMode();
if (!$user || !empty($user['_no_tenant_user']) || !empty($user['_tenant_mismatch'])) jsonError('Non authentifie', 401);
$tenant = $user['slug'];

channel_ensure_schema();
$portals = channel_available_portals();

// En mode STUB M104 : tous les 3 France = "subscribed" pour permettre tests.
// En prod future : lire channel_subscriptions WHERE tenant_slug=? AND active=1.
$db = channel_meta_pdo();
$st = $db->prepare("SELECT channel_name, active FROM channel_subscriptions WHERE tenant_slug=?");
$st->execute([$tenant]);
$subscribed = [];
foreach ($st->fetchAll() as $r) $subscribed[$r['channel_name']] = (bool) $r['active'];

foreach ($portals as &$p) {
    if ($p['status_v'] === 'active') {
        // M104 stub : default abonne true si pas d'entree.
        $p['subscribed'] = $subscribed[$p['name']] ?? true;
        $p['mock_mode'] = true;
    } else {
        $p['subscribed'] = false;
        $p['mock_mode'] = false;
    }
}
unset($p);

jsonResponse(['ok' => true, 'portals' => $portals, 'mock_mode_global' => true]);
