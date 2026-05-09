<?php
// M/2026/05/09/9 (M61) — 5 dernières sessions du user (date / duree / module).
// GET ?user_id=N (cookie ocre_session role=super_admin requis).

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/_session.php';
setCorsHeaders();

$user = getCurrentUserFromCookie();
if (!$user || ($user['role'] ?? '') !== 'super_admin') {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'UNAUTHORIZED']);
    exit;
}

$uid = (int)($_GET['user_id'] ?? 0);
if ($uid <= 0) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'INVALID_USER_ID']);
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
    echo json_encode(['ok' => false, 'error' => 'SERVER_ERROR']);
    exit;
}

$st = $pdo->prepare("
    SELECT created_at, last_seen_at, module, ip_address, user_agent,
           TIMESTAMPDIFF(SECOND, created_at, last_seen_at) AS duration_seconds,
           (revoked_at IS NULL AND expires_at > NOW()) AS is_active
    FROM user_sessions
    WHERE user_id = ?
    ORDER BY created_at DESC
    LIMIT 5
");
$st->execute([$uid]);

$out = [];
foreach ($st->fetchAll() as $r) {
    $out[] = [
        'date' => $r['created_at'],
        'last_seen' => $r['last_seen_at'],
        'duration_seconds' => (int)$r['duration_seconds'],
        'module' => $r['module'] ?: 'app',
        'ip' => $r['ip_address'],
        'user_agent_short' => substr((string)($r['user_agent'] ?? ''), 0, 80),
        'is_active' => (bool)$r['is_active'],
    ];
}

http_response_code(200);
echo json_encode(['ok' => true, 'user_id' => $uid, 'sessions' => $out]);
