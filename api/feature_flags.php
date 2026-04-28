<?php
// M/2026/04/28/63 — Endpoint feature flags. Lecture publique check_enabled, écriture super_admin.
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/lib/feature_flags.php';
setCorsHeaders();

$action = $_GET['action'] ?? 'check_enabled';

// check_enabled : pour tout user authentifié.
if ($action === 'check_enabled') {
    $user = requireAuth();
    $flagKey = $_GET['flag_key'] ?? '';
    if (!$flagKey) jsonError('flag_key requis', 400);
    $enabled = ff_enabled($flagKey, (int) $user['id']);
    jsonOk(['flag_key' => $flagKey, 'enabled' => $enabled]);
}

// Toutes les autres actions : super_admin only.
$user = requireAuth();
$isSuper = ($user['role'] ?? '') === 'super_admin' || ($user['_origin_role'] ?? '') === 'super_admin';
if (!$isSuper) jsonError('Accès refusé (super_admin requis)', 403);
$superUid = (int) ($user['_origin_user_id'] ?? $user['id']);
$input = getInput();

switch ($action) {

case 'list': {
    $flags = ff_list();
    foreach ($flags as &$f) {
        $f['overrides_count'] = (int) ff_pdo()->query("SELECT COUNT(*) FROM feature_flags_overrides WHERE flag_key = " . ff_pdo()->quote($f['flag_key']))->fetchColumn();
    }
    unset($f);
    jsonOk(['flags' => $flags]);
}

case 'update_default': {
    $key = $input['flag_key'] ?? '';
    $value = (int) ($input['value'] ?? 0);
    if (!$key) jsonError('flag_key requis', 400);
    ff_pdo()->prepare("UPDATE feature_flags SET default_value = ?, updated_by = ? WHERE flag_key = ?")
        ->execute([$value ? 1 : 0, $superUid, $key]);
    jsonOk(['ok' => true]);
}

case 'update_rollout': {
    $key = $input['flag_key'] ?? '';
    $pct = max(0, min(100, (int) ($input['pct'] ?? 0)));
    if (!$key) jsonError('flag_key requis', 400);
    ff_pdo()->prepare("UPDATE feature_flags SET rollout_pct = ?, updated_by = ? WHERE flag_key = ?")
        ->execute([$pct, $superUid, $key]);
    jsonOk(['ok' => true]);
}

case 'add_override': {
    $key = $input['flag_key'] ?? '';
    $userId = isset($input['user_id']) ? (int) $input['user_id'] : null;
    $wsId = isset($input['workspace_id']) ? (int) $input['workspace_id'] : null;
    $enabled = (int) ($input['enabled'] ?? 0);
    if (!$key || (!$userId && !$wsId)) jsonError('flag_key + user_id ou workspace_id requis', 400);
    ff_pdo()->prepare(
        "INSERT INTO feature_flags_overrides (flag_key, user_id, workspace_id, enabled, created_by)
         VALUES (?, ?, ?, ?, ?)
         ON DUPLICATE KEY UPDATE enabled = VALUES(enabled), created_by = VALUES(created_by)"
    )->execute([$key, $userId, $wsId, $enabled ? 1 : 0, $superUid]);
    jsonOk(['ok' => true]);
}

case 'list_overrides': {
    $key = $_GET['flag_key'] ?? '';
    if (!$key) jsonError('flag_key requis', 400);
    jsonOk(['overrides' => ff_overrides($key)]);
}

case 'delete_override': {
    $id = (int) ($input['override_id'] ?? 0);
    if (!$id) jsonError('override_id requis', 400);
    ff_pdo()->prepare("DELETE FROM feature_flags_overrides WHERE id = ?")->execute([$id]);
    jsonOk(['ok' => true]);
}

default:
    jsonError('Action inconnue', 400);
}
