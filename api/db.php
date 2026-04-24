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

function currentUser() {
    $token = $_SERVER['HTTP_X_SESSION_TOKEN'] ?? ($_GET['token'] ?? '');
    if (!$token) return null;
    $stmt = db()->prepare(
        "SELECT u.* FROM sessions s
         JOIN users u ON u.id = s.user_id
         WHERE s.token = ? AND s.expires_at > NOW() AND u.active = 1
         LIMIT 1"
    );
    $stmt->execute([$token]);
    $row = $stmt->fetch();
    if (!$row) return null;
    // V18.39 — block suspended users at the door.
    if (!empty($row['is_suspended']) && (int) $row['is_suspended'] === 1) return null;

    // V18.40 — impersonation : si X-Impersonation-Token valide + cette session appartient à un
    // admin, retourner l'user cible avec flags is_impersonating/impersonated_by. Chaque requête
    // auth'ée sous impersonation logge une action 'impersonate_action' dans admin_actions.
    $impToken = $_SERVER['HTTP_X_IMPERSONATION_TOKEN'] ?? '';
    if ($impToken && !empty($row['is_admin']) && (int) $row['is_admin'] === 1) {
        $st = db()->prepare(
            "SELECT s.admin_user_id, s.target_user_id, u.* FROM impersonation_sessions s
             JOIN users u ON u.id = s.target_user_id
             WHERE s.token = ? AND s.expires_at > NOW() AND s.stopped_at IS NULL
                   AND s.admin_user_id = ? AND u.active = 1 AND (u.is_suspended IS NULL OR u.is_suspended = 0)
             LIMIT 1"
        );
        $st->execute([$impToken, $row['id']]);
        $imp = $st->fetch();
        if ($imp) {
            $imp['is_impersonating'] = true;
            $imp['impersonated_by'] = (int) $imp['admin_user_id'];
            // Log de l'action imitée (best-effort). Ne bloque pas.
            try {
                $logStmt = db()->prepare(
                    "INSERT INTO admin_actions (admin_user_id, action, target_user_id, meta, ip)
                     VALUES (?, 'impersonate_action', ?, ?, ?)"
                );
                $meta = json_encode([
                    'endpoint' => substr((string) ($_SERVER['REQUEST_URI'] ?? ''), 0, 120),
                    'method' => $_SERVER['REQUEST_METHOD'] ?? '',
                ], JSON_UNESCAPED_UNICODE);
                $logStmt->execute([(int) $imp['admin_user_id'], (int) $imp['target_user_id'], $meta, $_SERVER['REMOTE_ADDR'] ?? '']);
            } catch (Exception $e) { /* silent */ }
            return $imp;
        }
    }
    return $row;
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
