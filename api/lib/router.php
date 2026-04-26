<?php
// V20 phase 3 — routeur multi-tenant. Resout (workspace, user, mode, db_name) par requete.
require_once __DIR__ . '/../config.php';

function pdo_meta() {
    static $pdo = null;
    if ($pdo !== null) return $pdo;
    $dsn = 'mysql:host=' . DB_HOST . ';dbname=ocre_meta;charset=utf8mb4';
    try {
        $pdo = new PDO($dsn, DB_USER, DB_PASS, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
    } catch (PDOException $e) {
        http_response_code(503);
        header('Content-Type: application/json');
        echo json_encode(['ok' => false, 'error' => 'Service indisponible (meta)']);
        exit;
    }
    return $pdo;
}

function pdo_workspace(string $db_name) {
    static $cache = [];
    if (isset($cache[$db_name])) return $cache[$db_name];
    $dsn = 'mysql:host=' . DB_HOST . ';dbname=' . $db_name . ';charset=utf8mb4';
    try {
        $cache[$db_name] = new PDO($dsn, DB_USER, DB_PASS, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
        return $cache[$db_name];
    } catch (PDOException $e) {
        http_response_code(404);
        header('Content-Type: application/json');
        echo json_encode(['ok' => false, 'error' => 'Workspace DB introuvable']);
        exit;
    }
}

function get_session_user(): ?array {
    $token = $_SERVER['HTTP_X_SESSION_TOKEN'] ?? '';
    if (!$token) return null;
    $stmt = pdo_meta()->prepare(
        "SELECT u.* FROM sessions s
         JOIN users u ON u.id = s.user_id
         WHERE s.token = ? AND s.expires_at > NOW() AND u.archived_at IS NULL
         LIMIT 1"
    );
    $stmt->execute([$token]);
    return $stmt->fetch() ?: null;
}

function current_user_or_401(): array {
    $u = get_session_user();
    if (!$u) {
        http_response_code(401);
        header('Content-Type: application/json');
        echo json_encode(['ok' => false, 'error' => 'Non authentifié']);
        exit;
    }
    return $u;
}

function resolve_tenant_slug(): string {
    $slug = $_SERVER['HTTP_X_TENANT_SLUG'] ?? '';
    if (!$slug) {
        $host = $_SERVER['HTTP_HOST'] ?? '';
        if (preg_match('/^([a-z0-9][a-z0-9-]*)\.ocre\.immo$/', $host, $m)) {
            $slug = $m[1];
        }
    }
    return preg_replace('/[^a-z0-9_-]/', '', strtolower($slug));
}

function resolve_workspace_context(): array {
    $slug = resolve_tenant_slug();
    if (!$slug) {
        http_response_code(400);
        header('Content-Type: application/json');
        echo json_encode(['ok' => false, 'error' => 'No tenant slug']);
        exit;
    }

    $meta = pdo_meta();
    $ws = $meta->prepare("SELECT * FROM workspaces WHERE slug = ? AND archived_at IS NULL LIMIT 1");
    $ws->execute([$slug]);
    $workspace = $ws->fetch();
    if (!$workspace) {
        http_response_code(404);
        header('Content-Type: application/json');
        echo json_encode(['ok' => false, 'error' => 'Workspace not found']);
        exit;
    }

    $user = current_user_or_401();
    $check = $meta->prepare(
        "SELECT role FROM workspace_members
         WHERE workspace_id = ? AND user_id = ? AND left_at IS NULL"
    );
    $check->execute([$workspace['id'], $user['id']]);
    $membership = $check->fetch();

    $is_super_admin = ($user['role'] === 'super_admin');
    if (!$membership && !$is_super_admin) {
        http_response_code(403);
        header('Content-Type: application/json');
        echo json_encode(['ok' => false, 'error' => 'Not a member']);
        exit;
    }

    // Mode test/agent pour WSp via session-key dans ocre_meta.sessions ?
    // Approche simple : key dans sessions table via colonne json (extension future).
    // V20 phase 3 minimal : detecter via cookie OCRE_MODE_<slug>.
    $mode = 'agent';
    if ($workspace['type'] === 'wsp') {
        $cookieKey = 'OCRE_MODE_' . strtoupper($slug);
        $mode = $_COOKIE[$cookieKey] ?? 'agent';
        if (!in_array($mode, ['agent', 'test'], true)) $mode = 'agent';
    }

    $db_name = match ((string)$workspace['type']) {
        'wsp' => 'ocre_wsp_' . $slug . ($mode === 'test' ? '_test' : ''),
        'wsc' => 'ocre_wsc_' . $slug,
        default => '',
    };

    return [
        'workspace' => $workspace,
        'user' => $user,
        'membership' => $membership,
        'is_super_admin' => $is_super_admin,
        'is_readonly' => $is_super_admin && !$membership,
        'mode' => $mode,
        'db_name' => $db_name,
    ];
}

function require_write_access(array $ctx): void {
    if (!empty($ctx['is_readonly'])) {
        http_response_code(403);
        header('Content-Type: application/json');
        echo json_encode(['ok' => false, 'error' => 'Read-only (super-admin sans membership)']);
        exit;
    }
}
