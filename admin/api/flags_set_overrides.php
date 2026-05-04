<?php
// M/2026/05/04/19 — Panel admin : update flag default + sync emails whitelist (overrides user_id).
// Auth super_admin obligatoire.
require_once __DIR__ . '/../../api/db.php';
require_once __DIR__ . '/../../api/lib/feature_flags.php';
setCorsHeaders();
header('Content-Type: application/json; charset=utf-8');

function jout_admin($d, $code = 200) { http_response_code($code); echo json_encode($d, JSON_UNESCAPED_UNICODE); exit; }

$user = requireAuth();
$isSuper = ($user['role'] ?? '') === 'super_admin' || ($user['_origin_role'] ?? '') === 'super_admin';
if (!$isSuper) jout_admin(['ok' => false, 'error' => 'super_admin required'], 403);
$superUid = (int) ($user['_origin_user_id'] ?? $user['id']);

$body = json_decode(file_get_contents('php://input'), true) ?: [];
$action = $_GET['action'] ?? ($body['action'] ?? 'sync');

if ($action === 'list') {
    $flags = ff_list();
    foreach ($flags as &$f) {
        $f['emails_whitelist'] = ff_emails_for_flag($f['flag_key']);
    }
    unset($f);
    jout_admin(['ok' => true, 'flags' => $flags]);
}

if ($action === 'sync') {
    $flagKey = trim((string) ($body['flag_key'] ?? ''));
    if (!preg_match('/^[A-Za-z0-9_-]{1,100}$/', $flagKey)) jout_admin(['ok' => false, 'error' => 'flag_key invalide'], 400);
    $defaultValue = isset($body['default_value']) ? (int) (!!$body['default_value']) : null;
    $emailsCsv = (string) ($body['emails_csv'] ?? '');
    $emails = array_values(array_filter(array_map('trim', explode(',', $emailsCsv))));
    $pdo = ff_pdo();
    // Update default si fourni.
    if ($defaultValue !== null) {
        $st = $pdo->prepare("UPDATE feature_flags SET default_value = ?, updated_by = ? WHERE flag_key = ?");
        $st->execute([$defaultValue ? 1 : 0, $superUid, $flagKey]);
        if ($st->rowCount() === 0) {
            $pdo->prepare("INSERT INTO feature_flags (flag_key, default_value, updated_by) VALUES (?, ?, ?)")
                ->execute([$flagKey, $defaultValue ? 1 : 0, $superUid]);
        }
    }
    $stats = ff_sync_email_whitelist($flagKey, $emails, $superUid);
    jout_admin(['ok' => true, 'flag_key' => $flagKey, 'default_value' => $defaultValue, 'sync' => $stats]);
}

jout_admin(['ok' => false, 'error' => 'unknown_action'], 400);
