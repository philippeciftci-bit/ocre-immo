<?php
// V18.38 — one-shot IP-whitelist VPS pour les tests E2E.
// Actions :
//   POST ?action=create_test_user body {email, password, prenom, nom}
//   POST ?action=cleanup_test_user body {email}  → DELETE user + ses clients + ses sessions
//   GET  ?action=list_test_clients body {email}  → liste clients du user test
//   POST ?action=reset_login_attempts body {email}  → reset lockout v18.39 (prep)

require_once __DIR__ . '/db.php';
header('Content-Type: application/json; charset=utf-8');

$ip = $_SERVER['REMOTE_ADDR'] ?? '';
if (!in_array($ip, ['46.225.215.148'], true)) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'IP refusée (' . $ip . ')']);
    exit;
}

$action = $_GET['action'] ?? ($_POST['action'] ?? '');
$input = json_decode(file_get_contents('php://input'), true) ?: [];

switch ($action) {

    case 'create_test_user': {
        $email = strtolower(trim((string) ($input['email'] ?? '')));
        $password = (string) ($input['password'] ?? '');
        if (!$email || !str_ends_with($email, '@ocre.immo')) {
            http_response_code(400);
            echo json_encode(['ok' => false, 'error' => 'email test_*@ocre.immo requis']);
            exit;
        }
        // Verifie/crée l'user, set password hash.
        $hash = password_hash($password, PASSWORD_BCRYPT);
        $stmt = db()->prepare("SELECT id FROM users WHERE email = ? LIMIT 1");
        $stmt->execute([$email]);
        $u = $stmt->fetch();
        if ($u) {
            $up = db()->prepare("UPDATE users SET password_hash = ?, active = 1 WHERE id = ?");
            $up->execute([$hash, $u['id']]);
            $user_id = (int) $u['id'];
            $created = false;
        } else {
            $ins = db()->prepare("INSERT INTO users (email, password_hash, prenom, nom, role, active, created_at) VALUES (?, ?, ?, ?, 'agent', 1, NOW())");
            $ins->execute([
                $email,
                $hash,
                substr((string) ($input['prenom'] ?? 'Test'), 0, 100),
                substr((string) ($input['nom'] ?? 'E2E'), 0, 100),
            ]);
            $user_id = (int) db()->lastInsertId();
            $created = true;
        }
        echo json_encode(['ok' => true, 'user_id' => $user_id, 'created' => $created]);
        exit;
    }

    case 'cleanup_test_user': {
        $email = strtolower(trim((string) ($input['email'] ?? '')));
        if (!$email || !str_starts_with($email, 'test_e2e')) {
            http_response_code(400);
            echo json_encode(['ok' => false, 'error' => 'email test_e2e* requis pour cleanup sécurisé']);
            exit;
        }
        $stmt = db()->prepare("SELECT id FROM users WHERE email = ? LIMIT 1");
        $stmt->execute([$email]);
        $u = $stmt->fetch();
        if (!$u) {
            echo json_encode(['ok' => true, 'skipped' => 'user non trouvé']);
            exit;
        }
        $uid = (int) $u['id'];
        $deletedClients = db()->prepare("DELETE FROM clients WHERE user_id = ?");
        $deletedClients->execute([$uid]);
        $cN = $deletedClients->rowCount();
        try {
            db()->prepare("DELETE FROM sessions WHERE user_id = ?")->execute([$uid]);
        } catch (Exception $e) { /* table optionnelle */ }
        try {
            db()->prepare("DELETE FROM logs WHERE user_id = ?")->execute([$uid]);
        } catch (Exception $e) {}
        db()->prepare("DELETE FROM users WHERE id = ?")->execute([$uid]);
        echo json_encode(['ok' => true, 'user_deleted' => $uid, 'clients_deleted' => $cN]);
        exit;
    }

    case 'list_test_clients': {
        $email = strtolower(trim((string) ($input['email'] ?? '')));
        if (!$email || !str_starts_with($email, 'test_e2e')) {
            http_response_code(400);
            echo json_encode(['ok' => false, 'error' => 'email test_e2e* requis']);
            exit;
        }
        $stmt = db()->prepare(
            "SELECT c.id, c.projet, c.is_draft, c.is_staged, c.prenom, c.nom, c.created_at
             FROM clients c JOIN users u ON u.id = c.user_id WHERE u.email = ?"
        );
        $stmt->execute([$email]);
        echo json_encode(['ok' => true, 'clients' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
        exit;
    }

    case 'set_admin': {
        $email = strtolower(trim((string) ($input['email'] ?? '')));
        $is_admin = !empty($input['is_admin']) ? 1 : 0;
        if (!$email || !str_starts_with($email, 'test_e2e')) {
            http_response_code(400);
            echo json_encode(['ok' => false, 'error' => 'email test_e2e* requis']);
            exit;
        }
        $up = db()->prepare("UPDATE users SET is_admin = ? WHERE email = ?");
        $up->execute([$is_admin, $email]);
        echo json_encode(['ok' => true, 'is_admin' => (bool) $is_admin, 'rows' => $up->rowCount()]);
        exit;
    }

    default:
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'action inconnue : ' . $action]);
}
