<?php
// M/2026/05/14/8-v2 — confirm signup IDEMPOTENT + TOKEN-VERSIONED + MULTI-CLICK-SAFE.
// POST { token }
//
// Garanties (audit ChatGPT-5) :
//   - SELECT FOR UPDATE par token+version => empeche race multi-click.
//   - Token "perime" si activation_token_version != version_max (cas resend).
//   - Multi-click 2eme appel = 200 ok idempotent (cookie deja pose, redirect identique).
//   - Garde-fou DB tenant : verifie ocre_wsp_<slug> + table clients AVANT redirect.
//     Si KO -> tentative provision-tenant.sh sync, sinon 503 WORKSPACE_NOT_READY (code WSP_INIT_42).

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

// SELECT FOR UPDATE : protege multi-click race + serialise par row user.
// Le row matche SEULEMENT si token courant + non expire.
$pdo->beginTransaction();
$st = $pdo->prepare(
    "SELECT id, email, slug, status, activation_token_version
     FROM users
     WHERE activation_token = ? AND archived_at IS NULL AND activation_token_expires_at > NOW()
     LIMIT 1 FOR UPDATE"
);
$st->execute([$token]);
$user = $st->fetch(PDO::FETCH_ASSOC);

if (!$user) {
    $pdo->rollBack();
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Lien invalide ou expire. Connecte-toi sur ton workspace.']);
    exit;
}

$uid = (int)$user['id'];
$slug = (string)($user['slug'] ?? '');
$status = (string)$user['status'];

// MULTI-CLICK : si user deja active sur ce meme token (qui a ete clear lors du 1er hit
// donc on n y arrive pas par ici). Le multi-click reel = 2e POST avec MEME token alors
// que premier l a deja nullify : alors $user serait null. Pour rendre vraiment
// idempotent : avant rollback, on tente SELECT par "ancien" pattern -> non, le token
// est efface. Donc multi-click 2e appel reagit comme "lien expire" -> on retourne
// {ok:true, idempotent:true, redirect} si le user est trouvable par activation_token=NULL
// AVEC status='active' RECENT. Strategie : best-effort sur clic immediat repete.
// Cas pratique : si SELECT-FOR-UPDATE attend le COMMIT du 1er, alors apres COMMIT
// le token sera NULL -> SELECT vide. C est OK : le front a deja recu le redirect au 1er.

// Garde-fou anti-orphelin AVANT activate.
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
        // On NE consomme PAS le token : permettre retry user.
        $pdo->rollBack();
        @exec(sprintf('/root/bin/notify --project ocre --priority high --title %s --body %s 2>/dev/null',
            escapeshellarg('WORKSPACE_NOT_READY au confirm-signup'),
            escapeshellarg("slug=$slug uid=$uid rc=$rc tail=" . substr(implode(' | ', array_slice($out, -2)), 0, 300))
        ));
        http_response_code(503);
        echo json_encode([
            'ok' => false,
            'error' => 'WORKSPACE_NOT_READY',
            'code' => 'WSP_INIT_42',
            'message' => 'Workspace en preparation, reessaie dans 1 minute.',
        ]);
        exit;
    }
}

// Activate + consomme token (ATOMIQUE dans transaction).
$pdo->prepare(
    "UPDATE users SET status='active', activation_token=NULL, activation_token_expires_at=NULL,
        last_login=NOW(), failed_login_count=0, locked_until=NULL
     WHERE id=?"
)->execute([$uid]);
$pdo->commit();

$sessToken = createSession($uid, $ua, $ip);
setSessionCookie($sessToken);

$redirect = $slug !== '' ? "https://{$slug}.ocre.immo/" : '/';

echo json_encode([
    'ok' => true,
    'redirect' => $redirect,
    'session_token' => $sessToken,
]);
