<?php
// M/2026/05/09/71 — Endpoint check session cookie ocre_session.
// GET (ou POST) → lit cookie, valide token, retourne user ou ok=false.
// Renouvelle expires_at + repose le cookie (sliding 30j).

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/_session.php';
setCorsHeaders();

// M99 — dual-mode SSO (priorite 1) + legacy ocre_session (priorite 2).
$user = getCurrentUserDualMode();
if (!$user) {
    http_response_code(200);
    echo json_encode(['ok' => false]);
    exit;
}

// M99 — SSO valide mais user pas mappe vers tenant : front affiche modal "no_tenant_user".
if (!empty($user['_no_tenant_user'])) {
    http_response_code(200);
    echo json_encode([
        'ok' => false,
        'reason' => 'no_tenant_user',
        '_sso_source' => 'sso',
        'email' => $user['email'] ?? null,
    ]);
    exit;
}
if (!empty($user['_tenant_mismatch'])) {
    http_response_code(200);
    echo json_encode([
        'ok' => false,
        'reason' => 'tenant_mismatch',
        '_sso_source' => 'sso',
        'email' => $user['email'] ?? null,
        'requested_slug' => $user['requested_slug'] ?? null,
    ]);
    exit;
}

$ssoSource = $user['_sso_source'] ?? 'legacy';

// Sliding cookie : repose le cookie legacy uniquement (SSO gere par auth.ocre.immo).
if ($ssoSource === 'legacy') {
    $cookieToken = $_COOKIE[OCRE_SESSION_COOKIE_NAME] ?? '';
    if ($cookieToken !== '') setSessionCookie($cookieToken);
}

// M/2026/05/09/71 — bridge avec session legacy (table sessions) pour compat M61 X-Session-Token.
// Si pas de session legacy active pour ce user, on en cree une.
$legacyToken = null;
try {
    $pdo = new PDO(
        'mysql:host=' . DB_HOST . ';dbname=ocre_meta;charset=utf8mb4',
        DB_USER, DB_PASS,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
    );
    $legacyToken = bin2hex(random_bytes(32));
    $st = $pdo->prepare(
        "INSERT INTO sessions (token, user_id, expires_at, ip, user_agent)
         VALUES (?, ?, DATE_ADD(NOW(), INTERVAL 30 DAY), ?, ?)"
    );
    $st->execute([
        $legacyToken,
        (int)$user['user_id'],
        $_SERVER['REMOTE_ADDR'] ?? null,
        substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 255),
    ]);
} catch (Throwable $e) {
    @error_log('[session_check] legacy_token_failed user_id=' . $user['user_id'] . ' err=' . $e->getMessage());
}

http_response_code(200);
echo json_encode([
    'ok' => true,
    'session_token' => $legacyToken,
    '_sso_source' => $ssoSource,                                  // M99 : 'sso'|'legacy'
    'user' => [
        'id' => $user['user_id'],
        'email' => $user['email'],
        'slug' => $user['slug'],
        'prenom' => $user['prenom'],
        'nom' => $user['nom'],
        'role' => $user['role'],
        'country_code' => $user['country_code'],
    ],
]);
