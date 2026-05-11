<?php
// M/2026/05/11/21 — Super-dashboard auth gate + DB helpers + audit log.
// Réutilise PDO ocre_meta (cohérent avec /opt/ocre-auth) + cookie ocre_jwt cross-subdomain.

require_once '/opt/ocre-auth/lib/auth_db.php';
require_once '/opt/ocre-auth/lib/jwt.php';

function sa_send_json($data, int $code = 200): void {
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function sa_cors(): void {
    // Whitelist stricte : seul admin.ocre.immo peut faire des XHR sur ces endpoints.
    $origin = $_SERVER['HTTP_ORIGIN'] ?? '';
    if ($origin === 'https://admin.ocre.immo') {
        header('Access-Control-Allow-Origin: ' . $origin);
        header('Access-Control-Allow-Credentials: true');
        header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
        header('Access-Control-Allow-Headers: Content-Type');
        header('Vary: Origin');
    }
    if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') { http_response_code(204); exit; }
}

function sa_current_user(): ?array {
    $token = $_COOKIE['ocre_jwt'] ?? '';
    if (!$token) return null;
    $r = jwt_decode($token, true);
    if (!$r['ok']) return null;
    $jti = $r['claims']['jti'];
    $st = auth_db()->prepare("SELECT 1 FROM auth_sessions WHERE jti = ? AND revoked_at IS NULL LIMIT 1");
    $st->execute([$jti]);
    if (!$st->fetch()) return null;
    $userId = (int) $r['claims']['sub'];
    $st2 = auth_db()->prepare("SELECT id, email, first_name, last_name, status, is_super_admin FROM auth_users WHERE id = ? LIMIT 1");
    $st2->execute([$userId]);
    $u = $st2->fetch();
    if (!$u || $u['status'] !== 'active') return null;
    return $u;
}

function sa_require_super_admin(): array {
    $u = sa_current_user();
    if (!$u) sa_send_json(['ok' => false, 'error' => 'unauthenticated'], 401);
    if (!(int) $u['is_super_admin']) sa_send_json(['ok' => false, 'error' => 'forbidden'], 403);
    return $u;
}

function sa_audit(int $actorId, string $action, ?string $target = null, $payload = null): void {
    try {
        $st = auth_db()->prepare(
            "INSERT INTO superadmin_audit (actor_user_id, action, target, payload, ip, user_agent)
             VALUES (?, ?, ?, ?, ?, ?)"
        );
        $st->execute([
            $actorId, $action, $target,
            $payload === null ? null : json_encode($payload, JSON_UNESCAPED_UNICODE),
            auth_client_ip(),
            substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 255),
        ]);
    } catch (Throwable $e) { error_log('sa_audit: ' . $e->getMessage()); }
}
