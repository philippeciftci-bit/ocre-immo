<?php
// M/2026/05/08/57 — Endpoint activation magic link (suppression set-password).
// POST JSON {token} → valide token activation, active user, crée session, retourne session_token + slug + redirect.
//
// Sequence :
//   1. Valider token (preg_match hex 32-128, lookup users.activation_token, expiration 48h)
//   2. UPDATE users : status='active', activation_token=NULL, activated_at=NOW()
//   3. INSERT IGNORE workspaces + workspace_members (filet idempotent, équivalent agents_activate.php M52)
//   4. INSERT sessions (token aléatoire 32 bytes -> 64 hex, TTL 30 jours)
//   5. Retourne {ok:true, session_token, slug, user, redirect}
//
// Reponses :
//   200 {ok:true, session_token, slug, user, redirect}
//   400 {ok:false, error: TOKEN_INVALID}
//   404 {ok:false, error: TOKEN_NOT_FOUND}
//   410 {ok:false, error: TOKEN_EXPIRED}
//   409 {ok:false, error: ALREADY_ACTIVE, session_token, slug, redirect}  (idempotent : déjà actif → re-issue session)
//   500 {ok:false, error: SERVER_ERROR, detail:...}

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/_provision.php';
require_once __DIR__ . '/_session.php';
setCorsHeaders();

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'METHOD_NOT_ALLOWED']);
    exit;
}

$input = getInput();
$token = trim((string)($input['token'] ?? ''));

if ($token === '' || !preg_match('/^[a-f0-9]{32,128}$/', $token)) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'TOKEN_INVALID']);
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

$st = $pdo->prepare("SELECT id, email, prenom, nom, slug, status, activation_token_expires_at FROM users WHERE activation_token = ? AND archived_at IS NULL LIMIT 1");
$st->execute([$token]);
$user = $st->fetch();

if (!$user) {
    http_response_code(404);
    echo json_encode(['ok' => false, 'error' => 'TOKEN_NOT_FOUND']);
    exit;
}

$expiresAt = (string)($user['activation_token_expires_at'] ?? '');
if ($expiresAt && strtotime($expiresAt) < time()) {
    http_response_code(410);
    echo json_encode(['ok' => false, 'error' => 'TOKEN_EXPIRED']);
    exit;
}

$userId = (int)$user['id'];
$slug = (string)$user['slug'];

try {
    if ($user['status'] !== 'active') {
        $upd = $pdo->prepare("UPDATE users SET status = 'active', activation_token = NULL, activation_token_expires_at = NULL, activated_at = NOW(), last_login = NOW() WHERE id = ?");
        $upd->execute([$userId]);
    }
    // M/2026/05/08/58 — provisionner DB tenant ocre_wsp_<slug> AVANT de créer la session.
    // Sans ça, le SPA charge sur le subdomain → fetch /api/clients.php → 503 → loader infini.
    // provision_agent_workspace() est idempotent (retourne database_already_exists si déjà créé).
    if ($slug !== '') {
        $prov = provision_agent_workspace($slug, $pdo);
        if (!$prov['ok'] && ($prov['error'] ?? '') !== 'database_already_exists') {
            @error_log('[agents_activate_v2] provision_failed user_id=' . $userId . ' slug=' . $slug . ' detail=' . ($prov['detail'] ?? json_encode($prov)));
            http_response_code(500);
            echo json_encode(['ok' => false, 'error' => 'PROVISION_FAILED', 'detail' => $prov['error'] ?? 'unknown']);
            exit;
        }
        // Filet idempotent : workspaces meta + workspace_members (cas où provision a déjà eu lieu mais meta absent).
        $dispName = 'Workspace ' . $slug;
        $pdo->prepare("INSERT IGNORE INTO workspaces (slug, type, display_name, country_code, created_at) VALUES (?, 'wsp', ?, 'FR', NOW())")
            ->execute([$slug, $dispName]);
        $wsId = (int)$pdo->query("SELECT id FROM workspaces WHERE slug = " . $pdo->quote($slug) . " LIMIT 1")->fetchColumn();
        if ($wsId > 0) {
            $pdo->prepare("INSERT IGNORE INTO workspace_members (workspace_id, user_id, role, joined_at) VALUES (?, ?, 'owner', NOW())")
                ->execute([$wsId, $userId]);
        }
    }
} catch (Throwable $e) {
    @error_log('[agents_activate_v2] activate exception user_id=' . $userId . ' err=' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'SERVER_ERROR', 'detail' => 'activate user']);
    exit;
}

// M/2026/05/09/71 — session legacy table 'sessions' (compat M61) + nouvelle session_token cookie 30j (M71).
$sessionToken = bin2hex(random_bytes(32));
try {
    $ins = $pdo->prepare(
        "INSERT INTO sessions (token, user_id, expires_at, ip, user_agent)
         VALUES (?, ?, DATE_ADD(NOW(), INTERVAL 30 DAY), ?, ?)"
    );
    $ins->execute([
        $sessionToken,
        $userId,
        $_SERVER['REMOTE_ADDR'] ?? null,
        substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 255),
    ]);
} catch (Throwable $e) {
    @error_log('[agents_activate_v2] session_insert_failed user_id=' . $userId . ' err=' . $e->getMessage());
}

// M/2026/05/09/71 — pose cookie ocre_session 30j HttpOnly Secure SameSite Lax Domain=.ocre.immo.
try {
    $cookieToken = createSession($userId, $_SERVER['HTTP_USER_AGENT'] ?? '', $_SERVER['REMOTE_ADDR'] ?? '');
    setSessionCookie($cookieToken);
} catch (Throwable $e) {
    @error_log('[agents_activate_v2] cookie_session_failed user_id=' . $userId . ' err=' . $e->getMessage());
}

$redirectUrl = ($slug !== '' && preg_match('/^[a-z0-9-]+$/', $slug))
    ? 'https://' . $slug . '.ocre.immo/?session=' . $sessionToken
    : '/?session=' . $sessionToken;

http_response_code(200);
echo json_encode([
    'ok' => true,
    'session_token' => $sessionToken,
    'slug' => $slug,
    'user' => [
        'id' => $userId,
        'email' => $user['email'],
        'prenom' => $user['prenom'],
        'nom' => $user['nom'],
        'slug' => $slug,
    ],
    'redirect' => $redirectUrl,
]);
