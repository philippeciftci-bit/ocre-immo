<?php
require_once __DIR__ . '/db.php';
setCorsHeaders();

$action = $_GET['action'] ?? ($_POST['action'] ?? '');
$input = getInput();

switch ($action) {

    case 'status': {
        $mode_test = getSetting('mode_test', '0') === '1';
        $mode_auth_email = getSetting('mode_auth_email', '1') === '1';
        $mode_maintenance = getSetting('mode_maintenance', '0') === '1';
        jsonOk([
            'mode_test' => $mode_test,
            'mode_auth_email' => $mode_auth_email,
            'mode_maintenance' => $mode_maintenance,
            'app_name' => getSetting('app_name', 'Ocre Immo'),
            'app_tagline' => getSetting('app_tagline', 'CRM immobilier'),
        ]);
    }

    case 'check_email': {
        $email = strtolower(trim($input['email'] ?? ''));
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) jsonError('Email invalide');
        $stmt = db()->prepare("SELECT id, password_hash, active FROM users WHERE email = ? LIMIT 1");
        $stmt->execute([$email]);
        $u = $stmt->fetch();
        if (!$u) jsonOk(['exists' => false, 'needs_password' => false]);
        if ((int)$u['active'] !== 1) jsonError('Compte désactivé', 403, ['exists' => true]);
        $needs = ($u['password_hash'] === 'PLACEHOLDER');
        jsonOk(['exists' => true, 'needs_password' => $needs]);
    }

    case 'set_password': {
        $email = strtolower(trim($input['email'] ?? ''));
        $pwd   = (string)($input['password'] ?? '');
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) jsonError('Email invalide');
        if (strlen($pwd) < 6) jsonError('Mot de passe trop court (min 6)');
        $stmt = db()->prepare("SELECT id, password_hash, active FROM users WHERE email = ? LIMIT 1");
        $stmt->execute([$email]);
        $u = $stmt->fetch();
        if (!$u) jsonError('Utilisateur introuvable', 404);
        if ((int)$u['active'] !== 1) jsonError('Compte désactivé', 403);
        if ($u['password_hash'] !== 'PLACEHOLDER') jsonError('Mot de passe déjà défini', 409);
        $hash = password_hash($pwd, PASSWORD_BCRYPT, ['cost' => BCRYPT_COST]);
        $upd = db()->prepare("UPDATE users SET password_hash = ? WHERE id = ?");
        $upd->execute([$hash, $u['id']]);
        logAction((int)$u['id'], 'set_password');
        jsonOk(['message' => 'Mot de passe défini']);
    }

    case 'login': {
        $email = strtolower(trim($input['email'] ?? ''));
        $pwd   = (string)($input['password'] ?? '');
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) jsonError('Email invalide');
        if (!$pwd) jsonError('Mot de passe requis');
        $stmt = db()->prepare("SELECT * FROM users WHERE email = ? LIMIT 1");
        $stmt->execute([$email]);
        $u = $stmt->fetch();
        if (!$u) jsonError('Identifiants invalides', 401);
        if ((int)$u['active'] !== 1) jsonError('Compte désactivé', 403);
        if ($u['password_hash'] === 'PLACEHOLDER') {
            jsonError('Mot de passe non défini', 403, ['needs_password' => true]);
        }
        if (!password_verify($pwd, $u['password_hash'])) jsonError('Identifiants invalides', 401);
        $token = bin2hex(random_bytes(32));
        $ip = $_SERVER['REMOTE_ADDR'] ?? '';
        $ua = substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 255);
        $stmt = db()->prepare(
            "INSERT INTO sessions (token, user_id, expires_at, ip, user_agent)
             VALUES (?, ?, DATE_ADD(NOW(), INTERVAL ? SECOND), ?, ?)"
        );
        $stmt->execute([$token, $u['id'], SESSION_DURATION, $ip, $ua]);
        db()->prepare("UPDATE users SET last_login = NOW() WHERE id = ?")->execute([$u['id']]);
        logAction((int)$u['id'], 'login');
        jsonOk([
            'token' => $token,
            'user' => [
                'id' => (int)$u['id'], 'email' => $u['email'],
                'prenom' => $u['prenom'], 'nom' => $u['nom'], 'role' => $u['role'],
            ],
        ]);
    }

    case 'test_login': {
        if (getSetting('mode_test', '0') !== '1') jsonError('Mode test désactivé', 403);
        $pwd = (string)($input['password'] ?? '');
        $expected = getSetting('test_password', '');
        if (!$expected || !hash_equals($expected, $pwd)) jsonError('Mot de passe test invalide', 401);
        $stmt = db()->prepare("SELECT * FROM users WHERE email = 'test@ocre.immo' LIMIT 1");
        $stmt->execute();
        $u = $stmt->fetch();
        if (!$u) {
            db()->prepare(
                "INSERT INTO users (email, password_hash, role, prenom, nom, active)
                 VALUES ('test@ocre.immo', 'PLACEHOLDER', 'visiteur', 'Testeur', '', 1)"
            )->execute();
            $uid = (int)db()->lastInsertId();
            $stmt = db()->prepare("SELECT * FROM users WHERE id = ?");
            $stmt->execute([$uid]);
            $u = $stmt->fetch();
        }
        $token = bin2hex(random_bytes(32));
        $ip = $_SERVER['REMOTE_ADDR'] ?? '';
        $ua = substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 255);
        db()->prepare(
            "INSERT INTO sessions (token, user_id, expires_at, ip, user_agent)
             VALUES (?, ?, DATE_ADD(NOW(), INTERVAL 86400 SECOND), ?, ?)"
        )->execute([$token, $u['id'], $ip, $ua]);
        logAction((int)$u['id'], 'test_login');
        jsonOk([
            'token' => $token, 'test_mode' => true,
            'user' => [
                'id' => (int)$u['id'], 'email' => $u['email'],
                'prenom' => $u['prenom'], 'nom' => $u['nom'], 'role' => $u['role'],
            ],
        ]);
    }

    case 'logout': {
        $token = $_SERVER['HTTP_X_SESSION_TOKEN'] ?? '';
        if ($token) db()->prepare("DELETE FROM sessions WHERE token = ?")->execute([$token]);
        jsonOk(['message' => 'Déconnecté']);
    }

    case 'me': {
        $u = requireAuth();
        jsonOk(['user' => [
            'id' => (int)$u['id'], 'email' => $u['email'],
            'prenom' => $u['prenom'], 'nom' => $u['nom'], 'role' => $u['role'],
        ]]);
    }

    default:
        jsonError('Action inconnue', 404);
}
