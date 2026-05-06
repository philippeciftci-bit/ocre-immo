<?php
// M/2026/04/29/1 + M/2026/05/06/83.3 + M/2026/05/07/89 — Activation compte.
// Action par defaut "activate" (M89) : valide token + bascule status=active +
// provisioning auto tenant + session 30j + retourne redirect_url. Le password
// est deja set au register (wizard etape 1), pas de re-set ici. Actions
// "check" (probe info) et "set_password" (re-set legacy) conservees pour
// retro-compat M83.x.
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/lib/tenant_provisioning.php';
header('Content-Type: application/json; charset=utf-8');

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$body = json_decode(file_get_contents('php://input'), true) ?: $_POST;

$meta = new PDO('mysql:host=' . DB_HOST . ';dbname=ocre_meta;charset=utf8mb4', DB_USER, DB_PASS, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
]);

$action = $_GET['action'] ?? ($body['action'] ?? 'activate');
$token = $_GET['activation_token'] ?? ($body['activation_token'] ?? ($_GET['token'] ?? ($body['token'] ?? '')));

if (!$token || !preg_match('/^[a-f0-9]{64}$/', $token)) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'activation_token invalide']);
    exit;
}

$st = $meta->prepare("SELECT id, email, display_name, prenom, slug, status, activation_token_expires_at FROM users WHERE activation_token = ? LIMIT 1");
$st->execute([$token]);
$user = $st->fetch();
if (!$user) { http_response_code(404); echo json_encode(['ok' => false, 'error' => 'token introuvable']); exit; }

// M89 idempotence : status=active. Si slug deja set, re-emet une session pour
// permettre au client de retry l'activation (token reconsommable tant que la
// row existe). Si pas de slug, provisionne en rattrapage.
if ($user['status'] === 'active') {
    if ($action === 'check') {
        echo json_encode([
            'ok' => true,
            'email' => $user['email'],
            'display_name' => $user['display_name'],
            'slug' => $user['slug'],
        ]);
        exit;
    }
    $prov = ocre_provision_tenant_for_user((int)$user['id']);
    if ($prov['ok']) {
        echo json_encode([
            'ok' => true,
            'session_token' => $prov['session_token'],
            'slug' => $prov['slug'],
            'redirect_url' => $prov['redirect_url'],
            'redirect' => $prov['redirect_url'],
            'idempotent' => true,
        ]);
        exit;
    }
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'PROVISIONING_FAILED', 'detail' => $prov['detail'] ?? '']);
    exit;
}
if ($user['status'] !== 'pending_activation') {
    http_response_code(409);
    echo json_encode(['ok' => false, 'error' => 'compte non activable']);
    exit;
}
if (strtotime($user['activation_token_expires_at']) < time()) {
    http_response_code(410);
    echo json_encode(['ok' => false, 'error' => 'TOKEN_EXPIRED']);
    exit;
}

if ($action === 'check') {
    echo json_encode([
        'ok' => true,
        'email' => $user['email'],
        'display_name' => $user['display_name'],
        'slug' => $user['slug'],
    ]);
    exit;
}

// M89 — action par defaut "activate" : bascule status + provisioning auto + session.
// Pas de re-set password (deja set au register wizard etape 1).
if ($action === 'activate') {
    $meta->prepare(
        "UPDATE users SET status = 'active', activation_token = NULL, activation_token_expires_at = NULL,
                          first_login_at = COALESCE(first_login_at, NOW())
         WHERE id = ?"
    )->execute([(int)$user['id']]);

    $prov = ocre_provision_tenant_for_user((int)$user['id']);
    if (!$prov['ok']) {
        http_response_code(500);
        echo json_encode([
            'ok' => false,
            'error' => 'PROVISIONING_FAILED',
            'detail' => $prov['detail'] ?? ($prov['error'] ?? ''),
        ]);
        exit;
    }
    echo json_encode([
        'ok' => true,
        'session_token' => $prov['session_token'],
        'slug' => $prov['slug'],
        'redirect_url' => $prov['redirect_url'],
        'redirect' => $prov['redirect_url'],
        'duration_ms' => $prov['duration_ms'] ?? null,
    ]);
    exit;
}

// Retro-compat M83.x : action set_password (legacy, pour le flow ou l'user re-set son password a l'activation).
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
    $prov = ocre_provision_tenant_for_user((int)$user['id']);
    if (!$prov['ok']) {
        http_response_code(500);
        echo json_encode(['ok' => false, 'error' => 'PROVISIONING_FAILED', 'detail' => $prov['detail'] ?? '']);
        exit;
    }
    echo json_encode([
        'ok' => true,
        'session_token' => $prov['session_token'],
        'slug' => $prov['slug'],
        'redirect_url' => $prov['redirect_url'],
        'redirect' => $prov['redirect_url'],
        'duration_ms' => $prov['duration_ms'] ?? null,
    ]);
    exit;
}

http_response_code(400);
echo json_encode(['ok' => false, 'error' => 'action invalide (activate | check | set_password)']);
