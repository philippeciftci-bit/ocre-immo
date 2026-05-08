<?php
// M/2026/05/08/28 — Endpoint set-password post-activation.
// POST JSON {token, password}
//
// Sequence :
//   1. Valider token (pending_activation, non expire)
//   2. Hash password (BCRYPT cost 12, cohere agents_register)
//   3. UPDATE users : password_hash, status='active', activation_token=NULL, activation_token_expires_at=NULL
//   4. Creer session (table sessions, token aleatoire 32 bytes -> 64 hex)
//   5. Retourner {ok:true, token, redirect}
//
// Reponses :
//   200 {ok:true, token, redirect}
//   400 {ok:false, error: TOKEN_INVALID|WEAK_PASSWORD}
//   404 {ok:false, error: TOKEN_NOT_FOUND}
//   410 {ok:false, error: TOKEN_EXPIRED}
//   500 {ok:false, error: SERVER_ERROR, detail:...}

require_once __DIR__ . '/db.php';
setCorsHeaders();

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'METHOD_NOT_ALLOWED']);
    exit;
}

$input = getInput();
$token = trim((string)($input['token'] ?? ''));
$pwd = (string)($input['password'] ?? '');

if ($token === '' || !preg_match('/^[a-f0-9]{32,128}$/', $token)) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'TOKEN_INVALID']);
    exit;
}

if (strlen($pwd) < 8 || !preg_match('/[A-Z]/', $pwd) || !preg_match('/[0-9]/', $pwd)) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'WEAK_PASSWORD']);
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

$st = $pdo->prepare("SELECT id, email, prenom, slug, status, activation_token_expires_at FROM users WHERE activation_token = ? AND archived_at IS NULL LIMIT 1");
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

$hash = password_hash($pwd, PASSWORD_BCRYPT, ['cost' => 12]);

try {
    // M/2026/05/08/50 — set activated_at en plus pour traçabilité.
    $upd = $pdo->prepare("UPDATE users SET password_hash = ?, status = 'active', activation_token = NULL, activation_token_expires_at = NULL, last_login = NOW(), activated_at = NOW() WHERE id = ?");
    $upd->execute([$hash, (int)$user['id']]);
    // M/2026/05/08/52 — filet : INSERT meta workspaces + workspace_members idempotent
    // au cas où agents_activate.php n aurait pas encore créé la ligne meta. Évite spinner infini.
    $userSlug = (string)$user['slug'];
    if ($userSlug !== '') {
        try {
            $dispName = 'Workspace ' . $userSlug;
            $pdo->prepare("INSERT IGNORE INTO workspaces (slug, type, display_name, country_code, created_at) VALUES (?, 'wsp', ?, 'FR', NOW())")
                ->execute([$userSlug, $dispName]);
            $wsId = (int)$pdo->query("SELECT id FROM workspaces WHERE slug = " . $pdo->quote($userSlug) . " LIMIT 1")->fetchColumn();
            if ($wsId > 0) {
                $pdo->prepare("INSERT IGNORE INTO workspace_members (workspace_id, user_id, role, joined_at) VALUES (?, ?, 'owner', NOW())")
                    ->execute([$wsId, (int)$user['id']]);
            }
        } catch (Throwable $_) { /* silencieux, agents_activate l aurait déjà fait normalement */ }
    }
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'SERVER_ERROR', 'detail' => 'update user']);
    exit;
}

// Cree session (auto-login)
$sessionToken = bin2hex(random_bytes(32));
try {
    $ins = $pdo->prepare(
        "INSERT INTO sessions (token, user_id, expires_at, ip, user_agent)
         VALUES (?, ?, DATE_ADD(NOW(), INTERVAL 30 DAY), ?, ?)"
    );
    $ins->execute([
        $sessionToken,
        (int)$user['id'],
        $_SERVER['REMOTE_ADDR'] ?? null,
        substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 255),
    ]);
} catch (Throwable $e) {
    // Session insertion echouee : le user est active mais devra se reconnecter manuellement.
    @error_log('[agents_set_password] session_insert_failed user_id=' . $user['id'] . ' err=' . $e->getMessage());
    http_response_code(200);
    echo json_encode([
        'ok' => true,
        'token' => null,
        'redirect' => '/login/?activated=1',
        'note' => 'Session creation failed, redirect to login',
    ]);
    exit;
}

// M/2026/05/08/56 — redirect vers subdomain tenant <slug>.ocre.immo (DNS wildcard *.ocre.immo).
// Sur app.ocre.immo nginx route fallback "ozkan" → DB ocre_wsp_ozkan inexistante → 503 boucle SPA.
// Le subdomain résout le slug correctement → nginx route vers ocre_wsp_<slug> → DB tenant trouvée.
// Token session passé en query string ?session=... pour handoff cross-subdomain
// (localStorage de app.ocre.immo n'est pas partagé avec <slug>.ocre.immo). SPA boot lit ?session
// + setItem localStorage + clean URL (cf code App() handler M56).
$userSlugForRedirect = (string)$user['slug'];
$redirectUrl = ($userSlugForRedirect !== '' && preg_match('/^[a-z0-9-]+$/', $userSlugForRedirect))
    ? 'https://' . $userSlugForRedirect . '.ocre.immo/?session=' . $sessionToken
    : '/?session=' . $sessionToken;
http_response_code(200);
echo json_encode([
    'ok' => true,
    'token' => $sessionToken,
    'user' => [
        'id' => (int)$user['id'],
        'email' => $user['email'],
        'prenom' => $user['prenom'],
        'slug' => $user['slug'],
    ],
    'redirect' => $redirectUrl,
]);
