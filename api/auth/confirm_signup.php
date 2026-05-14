<?php
// M/2026/05/15/3 + M/2026/05/14/8 — confirm signup public.
// POST { token } -> valide activation_token + status='active' + cookie 30j + redirect URL.
// M/14/8 : garde-fou anti-orphelin : verifie DB tenant + table clients AVANT redirect.
// Si KO -> tente provision-tenant.sh sync, sinon retourne WORKSPACE_NOT_READY (+ notif).

declare(strict_types=1);
header('Content-Type: application/json');

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../_session.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'method']);
    exit;
}

$input = json_decode(file_get_contents('php://input') ?: '{}', true);
$token = trim((string)($input['token'] ?? ''));
$ip = explode(',', $_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['REMOTE_ADDR'] ?? '?')[0];
$ua = $_SERVER['HTTP_USER_AGENT'] ?? '?';

if ($token === '') {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Token manquant']);
    exit;
}

$pdo = new PDO('mysql:host=' . DB_HOST . ';dbname=ocre_meta;charset=utf8mb4', DB_USER, DB_PASS, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);

$st = $pdo->prepare("SELECT id, email, slug, status FROM users WHERE activation_token = ? AND archived_at IS NULL AND activation_token_expires_at > NOW() LIMIT 1");
$st->execute([$token]);
$user = $st->fetch(PDO::FETCH_ASSOC);

if (!$user) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Lien invalide ou expire. Connecte-toi sur ton workspace.']);
    exit;
}

$uid = (int)$user['id'];
$slug = (string)($user['slug'] ?? '');

// M/14/8 : GARDE-FOU anti-orphelin AVANT activate + redirect.
// Verifie DB tenant existe + table 'clients' presente. Sinon tente provision sync.
function _check_tenant_db_ready(string $slug): bool {
    if ($slug === '') return false;
    try {
        $pdo2 = new PDO('mysql:host=' . DB_HOST . ';dbname=information_schema;charset=utf8mb4', DB_USER, DB_PASS, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $dbName = 'ocre_wsp_' . $slug;
        $q = $pdo2->prepare("SELECT COUNT(*) FROM tables WHERE table_schema = ? AND table_name = 'clients'");
        $q->execute([$dbName]);
        return ((int)$q->fetchColumn()) === 1;
    } catch (Throwable $e) {
        return false;
    }
}

if (!_check_tenant_db_ready($slug)) {
    @file_put_contents('/var/log/ocre-signup.log', '[' . date('c') . "] confirm-signup GUARD: DB missing for slug=$slug uid=$uid, retry provision\n", FILE_APPEND);
    $cmd = sprintf('sudo /opt/ocre-app/scripts/provision-tenant.sh %s %d 2>&1', escapeshellarg($slug), $uid);
    $out = []; $rc = 0;
    @exec($cmd, $out, $rc);
    @file_put_contents('/var/log/ocre-signup.log', '[' . date('c') . "] confirm-signup PROVISION-RETRY rc=$rc slug=$slug\n" . implode("\n", $out) . "\n\n", FILE_APPEND);

    if ($rc !== 0 || !_check_tenant_db_ready($slug)) {
        @exec(sprintf('/root/bin/notify --project ocre --priority high --title %s --body %s 2>/dev/null',
            escapeshellarg('WORKSPACE_NOT_READY au confirm-signup'),
            escapeshellarg("slug=$slug uid=$uid rc=$rc tail=" . substr(implode(' | ', array_slice($out, -2)), 0, 300))
        ));
        http_response_code(503);
        echo json_encode([
            'ok' => false,
            'error' => 'WORKSPACE_NOT_READY',
            'message' => 'Ton compte est active mais ton espace n\'est pas pret. Reessaie dans 1 minute ou contacte le support.',
        ]);
        exit;
    }
}

// Activate + consomme token.
$pdo->prepare("UPDATE users SET status='active', activation_token=NULL, activation_token_expires_at=NULL, last_login=NOW(), failed_login_count=0, locked_until=NULL WHERE id=?")
    ->execute([$uid]);

$sessToken = createSession($uid, $ua, $ip);
setSessionCookie($sessToken);

$redirect = $slug !== '' ? "https://{$slug}.ocre.immo/" : '/';

echo json_encode([
    'ok' => true,
    'redirect' => $redirect,
    'session_token' => $sessToken,
]);
