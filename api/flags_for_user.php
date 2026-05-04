<?php
// M/2026/05/04/19 — Endpoint snapshot flags effectif pour le user authentifie.
// Retourne JSON {flags: {KEY: bool, ...}, user_email, fetched_at}.
// Cache HTTP 30s. Fallback DB indispo : flags vide (toutes OFF).
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/lib/feature_flags.php';
setCorsHeaders();
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: private, max-age=30');

try {
    $user = requireAuth();
    $uid = (int) $user['id'];
    $email = $user['email'] ?? '';
    $flags = ff_snapshot_for_user($uid);
    echo json_encode([
        'ok' => true,
        'flags' => $flags,
        'user_email' => $email,
        'user_id' => $uid,
        'fetched_at' => gmdate('c'),
    ], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    http_response_code(503);
    echo json_encode(['ok' => false, 'flags' => (object)[], 'error' => 'db_unavailable']);
}
