<?php
require_once __DIR__ . '/config.php';

if (DEBUG) {
    ini_set('display_errors', '1');
    error_reporting(E_ALL);
} else {
    ini_set('display_errors', '0');
    if (LOG_ERRORS) ini_set('log_errors', '1');
}

function setCorsHeaders() {
    $origin = $_SERVER['HTTP_ORIGIN'] ?? '';
    if (in_array($origin, ALLOWED_ORIGINS, true)) {
        header('Access-Control-Allow-Origin: ' . $origin);
    }
    header('Access-Control-Allow-Methods: GET, POST, DELETE, OPTIONS');
    header('Access-Control-Allow-Headers: Content-Type, X-Session-Token, X-Admin-Code');
    header('Access-Control-Allow-Credentials: true');
    header('Vary: Origin');
    if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') {
        http_response_code(204);
        exit;
    }
}

function db() {
    static $pdo = null;
    if ($pdo !== null) return $pdo;
    $dsn = 'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=' . DB_CHARSET;
    try {
        $pdo = new PDO($dsn, DB_USER, DB_PASS, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);
    } catch (PDOException $e) {
        $msg = DEBUG ? ('DB: ' . $e->getMessage()) : 'Service indisponible';
        jsonError($msg, 503);
    }
    return $pdo;
}

function jsonResponse($data, $status = 200) {
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function jsonError($msg, $status = 400, $extra = []) {
    jsonResponse(array_merge(['ok' => false, 'error' => $msg], $extra), $status);
}

function jsonOk($data = []) {
    jsonResponse(array_merge(['ok' => true], $data));
}

function getInput() {
    $raw = file_get_contents('php://input');
    $data = json_decode($raw, true);
    return is_array($data) ? $data : [];
}

// M/2026/04/28/18 — currentUser() meta-first.
// Sessions vivent en ocre_meta (architecture V20). L'ancienne version interrogeait
// d'abord tenant.sessions, qui n'existe pas → PDOException non catchée → 500 sur
// tous les endpoints legacy (matches.php, clients.php, seed.php). Réécrit en
// query unique meta avec vérification de membership/super_admin.
//
// Retour : ligne ocre_meta.users (id = uid meta). Pour super_admin sur un tenant
// dont il n'est pas membre, on substitue le user owner du tenant pour préserver
// le comportement « admin agit comme le owner » (lecture des dossiers, etc.).
function currentUser() {
    $token = $_SERVER['HTTP_X_SESSION_TOKEN'] ?? ($_GET['token'] ?? '');
    if (!$token) return null;

    $dsn = 'mysql:host=' . DB_HOST . ';dbname=ocre_meta;charset=utf8mb4';
    $meta = new PDO($dsn, DB_USER, DB_PASS, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);

    $slug = $_SERVER['HTTP_X_TENANT_SLUG'] ?? '';
    if (!$slug && preg_match('/^([a-z0-9][a-z0-9-]*)\.ocre\.immo$/', $_SERVER['HTTP_HOST'] ?? '', $m)) {
        $slug = $m[1];
    }

    $st = $meta->prepare(
        "SELECT u.*, m.role AS membership_role
         FROM sessions s
         JOIN users u ON u.id = s.user_id
         LEFT JOIN workspaces w ON w.slug = ? AND w.archived_at IS NULL
         LEFT JOIN workspace_members m ON m.workspace_id = w.id AND m.user_id = u.id AND m.left_at IS NULL
         WHERE s.token = ? AND s.expires_at > NOW() AND u.archived_at IS NULL
         LIMIT 1"
    );
    $st->execute([$slug, $token]);
    $row = $st->fetch();
    if (!$row) return null;
    if (!empty($row['is_suspended']) && (int) $row['is_suspended'] === 1) return null;

    $isMember = !empty($row['membership_role']);
    $isSuperAdmin = ($row['role'] === 'super_admin');

    if ($isMember) return $row;

    if ($isSuperAdmin) {
        // Super admin sur un tenant dont il n'est pas membre : agit comme le
        // owner du tenant. Tous les endpoints legacy filtrent par user_id =
        // currentUser['id'] ; il faut donc qu'il s'identifie au owner pour
        // voir/modifier ses dossiers. On marque la substitution via
        // _origin_role pour permettre aux endpoints qui ont besoin du rôle
        // originel (ex: matching.php?action=rejouer_complet super_admin only)
        // de le retrouver.
        $own = $meta->prepare(
            "SELECT u.* FROM workspaces w
             JOIN workspace_members m ON m.workspace_id = w.id AND m.role = 'owner' AND m.left_at IS NULL
             JOIN users u ON u.id = m.user_id
             WHERE w.slug = ? AND w.archived_at IS NULL
             LIMIT 1"
        );
        $own->execute([$slug]);
        $owner = $own->fetch();
        if ($owner) {
            $owner['_origin_role'] = 'super_admin';
            $owner['_origin_user_id'] = (int) $row['id'];
            return $owner;
        }
        return $row;
    }

    return null;
}

function requireAuth() {
    $u = currentUser();
    if (!$u) jsonError('Non authentifié', 401);
    return $u;
}

function requireAdmin() {
    $u = requireAuth();
    // V18.40 — flag is_admin (nouveau) OU role legacy 'admin'. Refuse sous impersonation
    // (un admin impersoné ne peut pas performer des actions admin).
    $isAdmin = (!empty($u['is_admin']) && (int) $u['is_admin'] === 1) || (($u['role'] ?? '') === 'admin');
    if (!$isAdmin) jsonError('Accès refusé (admin requis)', 403);
    if (!empty($u['is_impersonating'])) jsonError('Actions admin interdites sous impersonation', 403);
    return $u;
}

function getSetting($key, $default = null) {
    static $cache = null;
    if ($cache === null) {
        $cache = [];
        try {
            $rows = db()->query("SELECT key_name, value FROM settings")->fetchAll();
            foreach ($rows as $r) $cache[$r['key_name']] = $r['value'];
        } catch (Exception $e) { /* settings table absente ou vide */ }
    }
    return $cache[$key] ?? $default;
}

function setSetting($key, $value) {
    $stmt = db()->prepare(
        "INSERT INTO settings (key_name, value) VALUES (?, ?)
         ON DUPLICATE KEY UPDATE value = VALUES(value)"
    );
    $stmt->execute([$key, (string)$value]);
}

function logAction($user_id, $action, $details = null) {
    try {
        $ip = $_SERVER['REMOTE_ADDR'] ?? '';
        $stmt = db()->prepare(
            "INSERT INTO logs (user_id, action, details, ip) VALUES (?, ?, ?, ?)"
        );
        $stmt->execute([$user_id, $action, $details, $ip]);
    } catch (Exception $e) { /* silent */ }
}
