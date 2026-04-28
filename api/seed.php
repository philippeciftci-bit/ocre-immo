<?php
// M/2026/04/28/16 — Endpoint dispatch seeder mode test.
// Logique métier : api/lib/seed_helpers.php (réutilisée aussi par le hook signup
// de auth_v20.php).
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/lib/seed_helpers.php';
setCorsHeaders();

$user = requireAuth();
$action = $_GET['action'] ?? ($_POST['action'] ?? '');
$uid = (int) $user['id'];

switch ($action) {

case 'apply_test_seed':
case 'restore_test_seed': {
    // Bouton « Restaurer les dossiers démo » dans les préférences mode test.
    // db() pointe sur ocre_wsp_<slug>_test si le cookie OCRE_MODE_<SLUG>=test.
    $result = applySeedToTenant(db(), $uid);
    jsonOk($result);
}

default:
    jsonError('Action inconnue', 400);
}
