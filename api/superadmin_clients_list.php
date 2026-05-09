<?php
// M/2026/05/09/9 (M61) — Endpoint super-admin liste enrichie users avec profile_label + outils utilises + last_seen + has_active_session.
// GET (cookie ocre_session avec role=super_admin requis).

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/_session.php';
setCorsHeaders();

$user = getCurrentUserFromCookie();
if (!$user || ($user['role'] ?? '') !== 'super_admin') {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'UNAUTHORIZED']);
    exit;
}

try {
    $pdo = new PDO(
        'mysql:host=' . DB_HOST . ';dbname=ocre_meta;charset=utf8mb4',
        DB_USER, DB_PASS,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
    );
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'SERVER_ERROR', 'detail' => 'db connect']);
    exit;
}

$st = $pdo->query("
    SELECT u.id, u.email, u.prenom, u.nom, u.display_name, u.profile_label, u.role, u.slug, u.status, u.created_at, u.last_login,
           (SELECT GROUP_CONCAT(DISTINCT s.module ORDER BY s.module SEPARATOR ',')
            FROM user_sessions s WHERE s.user_id = u.id) AS modules_used,
           (SELECT MAX(s2.last_seen_at) FROM user_sessions s2 WHERE s2.user_id = u.id) AS last_seen_at,
           (SELECT COUNT(*) FROM user_sessions s3
            WHERE s3.user_id = u.id AND s3.expires_at > NOW() AND s3.revoked_at IS NULL) AS active_sessions_count
    FROM users u
    WHERE u.archived_at IS NULL
      AND u.id != 2
    ORDER BY last_seen_at DESC, u.created_at DESC
");
$rows = $st->fetchAll();

$out = [];
foreach ($rows as $r) {
    $out[] = [
        'id' => (int)$r['id'],
        'email' => $r['email'],
        'display_name' => $r['display_name'] ?: trim(($r['prenom'] ?? '') . ' ' . ($r['nom'] ?? '')),
        'profile_label' => $r['profile_label'] ?: 'standard',
        'role' => $r['role'],
        'slug' => $r['slug'],
        'status' => $r['status'],
        'modules_used' => $r['modules_used'] ? explode(',', $r['modules_used']) : [],
        'last_seen_at' => $r['last_seen_at'],
        'has_active_session' => (int)$r['active_sessions_count'] > 0,
        'active_sessions_count' => (int)$r['active_sessions_count'],
        'created_at' => $r['created_at'],
        'last_login' => $r['last_login'],
    ];
}

http_response_code(200);
echo json_encode(['ok' => true, 'count' => count($out), 'users' => $out]);
