<?php
// M/2026/04/29/1 + M/2026/05/06/83.3 — Activation compte : valide token + definit
// mot de passe + provisioning auto tenant + session 30j + redirect_url cross-subdomain.
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/lib/tenant_provisioning.php';
header('Content-Type: application/json; charset=utf-8');

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$body = json_decode(file_get_contents('php://input'), true) ?: $_POST;

$meta = new PDO('mysql:host=' . DB_HOST . ';dbname=ocre_meta;charset=utf8mb4', DB_USER, DB_PASS, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
]);

$action = $_GET['action'] ?? ($body['action'] ?? 'check');
$token = $_GET['activation_token'] ?? ($body['activation_token'] ?? '');

if (!$token || !preg_match('/^[a-f0-9]{64}$/', $token)) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'activation_token invalide']);
    exit;
}

$st = $meta->prepare("SELECT id, email, display_name, prenom, slug, status, activation_token_expires_at FROM users WHERE activation_token = ? LIMIT 1");
$st->execute([$token]);
$user = $st->fetch();
if (!$user) { http_response_code(404); echo json_encode(['ok' => false, 'error' => 'token introuvable']); exit; }

// M83.3 idempotence : un 2eme clic sur le meme lien apres set_password reussi
// arrive ici avec status='active' et token NULL — donc plus rien a faire. Mais
// si user.slug est deja set, on regenere une session et renvoie le redirect.
if ($user['status'] === 'active') {
    if (!empty($user['slug']) && $action === 'set_password') {
        // Le client repete set_password apres activation reussie — re-emet session.
        $prov = ocre_provision_tenant_for_user((int)$user['id']);
        if ($prov['ok']) {
            echo json_encode([
                'ok' => true,
                'session_token' => $prov['session_token'],
                'slug' => $prov['slug'],
                'redirect' => $prov['redirect_url'],
                'idempotent' => true,
            ]);
            exit;
        }
    }
    http_response_code(409);
    echo json_encode(['ok' => false, 'error' => 'compte déjà actif']);
    exit;
}
if ($user['status'] !== 'pending_activation') { http_response_code(409); echo json_encode(['ok' => false, 'error' => 'compte non activable']); exit; }
if (strtotime($user['activation_token_expires_at']) < time()) { http_response_code(410); echo json_encode(['ok' => false, 'error' => 'token expiré']); exit; }

if ($action === 'check') {
    echo json_encode([
        'ok' => true,
        'email' => $user['email'],
        'display_name' => $user['display_name'],
        'slug' => $user['slug'],
    ]);
    exit;
}

if ($action === 'set_password' && $method === 'POST') {
    $password = $body['password'] ?? '';
    if (strlen($password) < 10 || !preg_match('/[A-Za-z]/', $password) || !preg_match('/\d/', $password)) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'mot de passe : min 10 chars, lettres + chiffres']);
        exit;
    }
    $hash = password_hash($password, PASSWORD_BCRYPT);
    $meta->prepare("UPDATE users SET password_hash = ?, status = 'active', activation_token = NULL, activation_token_expires_at = NULL WHERE id = ?")
        ->execute([$hash, $user['id']]);

    // M83.3 — provisioning auto tenant + session token cross-subdomain (X-Session-Token).
    $prov = ocre_provision_tenant_for_user((int)$user['id']);
    if (!$prov['ok']) {
        http_response_code(500);
        echo json_encode(['ok' => false, 'error' => $prov['error'] ?? 'Provisioning échoué', 'detail' => $prov['detail'] ?? '']);
        exit;
    }

    echo json_encode([
        'ok' => true,
        'session_token' => $prov['session_token'],
        'slug' => $prov['slug'],
        'redirect' => $prov['redirect_url'],
        'duration_ms' => $prov['duration_ms'] ?? null,
    ]);
    exit;
}

http_response_code(400);
echo json_encode(['ok' => false, 'error' => 'action invalide (check | set_password)']);
