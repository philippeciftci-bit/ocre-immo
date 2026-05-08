<?php
// A2/2026-05-04 — helpers partages des endpoints super-admin
// Auth super_admin + connexion meta + audit log

require_once __DIR__ . '/../../api/db.php';

function admin_jout($d, $code = 200) {
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($d, JSON_UNESCAPED_UNICODE);
    exit;
}

function admin_require_super(): array {
    $user = requireAuth();
    $isSuper = ($user['role'] ?? '') === 'super_admin' || ($user['_origin_role'] ?? '') === 'super_admin';
    if (!$isSuper) admin_jout(['ok' => false, 'error' => 'super_admin_required'], 403);
    $superUid = (int) ($user['_origin_user_id'] ?? $user['id']);
    return ['user' => $user, 'super_uid' => $superUid];
}

function admin_meta_pdo(): PDO {
    static $p = null;
    if ($p) return $p;
    $p = new PDO(
        'mysql:host=' . DB_HOST . ';dbname=ocre_meta;charset=utf8mb4',
        DB_USER, DB_PASS,
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]
    );
    return $p;
}

function admin_audit(int $actorUid, string $action, ?int $targetUid, array $payload = []): void {
    try {
        $pdo = admin_meta_pdo();
        $st = $pdo->prepare(
            "INSERT INTO audit_log (actor_user_id, action, target_type, target_id, payload_json, ip_address)
             VALUES (?, ?, 'user', ?, ?, ?)"
        );
        $st->execute([
            $actorUid,
            substr($action, 0, 64),
            $targetUid,
            json_encode($payload, JSON_UNESCAPED_UNICODE),
            $_SERVER['REMOTE_ADDR'] ?? null,
        ]);
    } catch (Throwable $e) { /* silent */ }
}

function admin_get_agent(int $agentId): ?array {
    $pdo = admin_meta_pdo();
    $st = $pdo->prepare("SELECT id, email, display_name, role, status, slug FROM users WHERE id = ? LIMIT 1");
    $st->execute([$agentId]);
    $r = $st->fetch();
    return $r ?: null;
}
