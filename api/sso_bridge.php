<?php
// M99 — SSO bridge cote Oi Agent.
// - Decode JWT cookie 'ocre_jwt' pose par auth.ocre.immo (M97).
// - Verifie sig HS256 + exp + jti non revoque dans ocre_meta.auth_sessions.
// - Mappe l'auth_user.email vers ocre_meta.users.email -> recupere le user tenant.
// - Verifie que le user a acces au tenant courant (sous-domaine resolu via slug).
//
// Coexistence : ce helper est appele en PRIORITE 1 dans session_check.php.
// Si echec (no_jwt / invalid / user_not_mapped / tenant_mismatch), fallback sur
// le check ocre_session (priorite 2). Pas de break, additif.

require_once __DIR__ . '/db.php';

const SSO_JWT_COOKIE = 'ocre_jwt';
const SSO_SECRET_PATH = '/root/.secrets/ocre_jwt_secret';

function _sso_meta_pdo(): PDO {
    static $pdo = null;
    if ($pdo === null) {
        $pdo = new PDO(
            'mysql:host=' . DB_HOST . ';dbname=ocre_meta;charset=utf8mb4',
            DB_USER, DB_PASS,
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
        );
    }
    return $pdo;
}

function _sso_secret(): ?string {
    static $secret = null;
    if ($secret !== null) return $secret;
    if (!is_readable(SSO_SECRET_PATH)) return null;
    $secret = trim(file_get_contents(SSO_SECRET_PATH));
    return strlen($secret) >= 32 ? $secret : null;
}

function _sso_b64url_decode(string $data): string {
    $pad = strlen($data) % 4;
    if ($pad) $data .= str_repeat('=', 4 - $pad);
    return base64_decode(strtr($data, '-_', '+/'));
}

// Decode + verify JWT HS256. Retourne array claims ou null.
function _sso_decode_jwt(string $token): ?array {
    if (substr_count($token, '.') !== 2) return null;
    $secret = _sso_secret();
    if (!$secret) return null;
    [$h, $p, $s] = explode('.', $token);
    $header = json_decode(_sso_b64url_decode($h), true);
    if (!is_array($header) || ($header['alg'] ?? null) !== 'HS256') return null;
    $payload = json_decode(_sso_b64url_decode($p), true);
    if (!is_array($payload)) return null;
    $expected = hash_hmac('sha256', "$h.$p", $secret, true);
    $sig = _sso_b64url_decode($s);
    if (!hash_equals($expected, $sig)) return null;
    foreach (['sub', 'iat', 'exp', 'jti'] as $k) {
        if (!isset($payload[$k])) return null;
    }
    if ((int) $payload['exp'] < time() - 5) return null;
    return $payload;
}

function _sso_current_tenant_slug(): ?string {
    $slug = $_SERVER['HTTP_X_TENANT_SLUG'] ?? '';
    if (!$slug && !empty($_SERVER['HTTP_HOST']) && preg_match('/^([a-z0-9][a-z0-9-]*)\.ocre\.immo$/', $_SERVER['HTTP_HOST'], $m)) {
        $slug = $m[1];
    }
    return $slug ?: null;
}

// Retourne le user tenant matche par email + verification acces tenant courant.
// $strict_tenant_check : si true, retourne null si user n'a pas acces au tenant courant.
//                       si false, retourne le user meme sans tenant match (utile pour /me sans context tenant).
function getUserFromSsoCookie(bool $strict_tenant_check = true): ?array {
    $token = $_COOKIE[SSO_JWT_COOKIE] ?? '';
    if ($token === '') return null;

    $claims = _sso_decode_jwt($token);
    if (!$claims) return null;

    $authUserId = (int) $claims['sub'];
    $jti = $claims['jti'];

    try {
        $meta = _sso_meta_pdo();
        // Session non revoquee
        $st = $meta->prepare(
            "SELECT 1 FROM auth_sessions WHERE jti = ? AND revoked_at IS NULL LIMIT 1"
        );
        $st->execute([$jti]);
        if (!$st->fetch()) return null;

        // Email + status auth_user
        $st2 = $meta->prepare("SELECT email, status FROM auth_users WHERE id = ? LIMIT 1");
        $st2->execute([$authUserId]);
        $au = $st2->fetch();
        if (!$au || $au['status'] !== 'active') return null;
        $email = $au['email'];

        // Mapping vers users tenant par email (LOWER() pour insensible casse)
        $st3 = $meta->prepare(
            "SELECT u.id, u.email, u.slug, u.prenom, u.nom, u.role, u.country_code
             FROM users u
             WHERE LOWER(u.email) = LOWER(?) AND u.archived_at IS NULL
             LIMIT 1"
        );
        $st3->execute([$email]);
        $u = $st3->fetch();
        if (!$u) {
            // Aucun user tenant pour cet email -> SSO valide mais pas mappe (M99)
            // Retour ['_sso_source'=>'sso','_no_tenant_user'=>true] permet au front
            // d'afficher modal "Aucun module Oi Agent associe a ce compte"
            return [
                '_sso_source' => 'sso',
                '_no_tenant_user' => true,
                'email' => $email,
                'auth_user_id' => $authUserId,
            ];
        }

        if ($strict_tenant_check) {
            $currentSlug = _sso_current_tenant_slug();
            if ($currentSlug) {
                // Acces au tenant via : (a) user.slug == currentSlug (owner historique)
                // OU (b) workspace_members entry active
                if (($u['slug'] ?? null) !== $currentSlug) {
                    $st4 = $meta->prepare(
                        "SELECT 1 FROM workspace_members m
                         JOIN workspaces w ON w.id = m.workspace_id
                         WHERE m.user_id = ? AND w.slug = ? AND m.left_at IS NULL AND w.archived_at IS NULL
                         LIMIT 1"
                    );
                    $st4->execute([$u['id'], $currentSlug]);
                    if (!$st4->fetch()) {
                        // Tenant mismatch — super_admin a acces partout
                        if ($u['role'] !== 'super_admin') {
                            return [
                                '_sso_source' => 'sso',
                                '_tenant_mismatch' => true,
                                'email' => $email,
                                'requested_slug' => $currentSlug,
                            ];
                        }
                    }
                }
            }
        }

        return [
            '_sso_source' => 'sso',
            'session_id' => 0,            // N/A en SSO
            'user_id' => (int) $u['id'],
            'email' => $u['email'],
            'slug' => $u['slug'],
            'prenom' => $u['prenom'],
            'nom' => $u['nom'],
            'role' => $u['role'],
            'country_code' => $u['country_code'],
            'jti' => $jti,
        ];
    } catch (Throwable $e) {
        @error_log('[sso_bridge] err: ' . $e->getMessage());
        return null;
    }
}
