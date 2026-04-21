<?php
require_once __DIR__ . '/db.php';
setCorsHeaders();

$admin = requireAdmin();
$action = $_GET['action'] ?? ($_POST['action'] ?? '');
$input = getInput();

$code = $input['admin_code'] ?? ($_SERVER['HTTP_X_ADMIN_CODE'] ?? ($_GET['admin_code'] ?? ''));
if (!hash_equals(ADMIN_CODE, (string)$code)) jsonError('Code admin incorrect', 403);

switch ($action) {

    case 'list': {
        $rows = db()->query(
            "SELECT u.id, u.email, u.prenom, u.nom, u.role, u.active, u.created_at, u.last_login,
                    (SELECT COUNT(*) FROM clients WHERE user_id = u.id) AS nb_clients
             FROM users u ORDER BY u.created_at DESC"
        )->fetchAll();
        jsonOk(['users' => $rows]);
    }

    case 'create': {
        $email = strtolower(trim((string)($input['email'] ?? '')));
        $role = (string)($input['role'] ?? 'agent');
        $prenom = substr(trim((string)($input['prenom'] ?? '')), 0, 100);
        $nom = substr(trim((string)($input['nom'] ?? '')), 0, 100);
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) jsonError('Email invalide');
        if (!in_array($role, ['admin', 'agent', 'visiteur'], true)) jsonError('Rôle invalide');
        $chk = db()->prepare("SELECT id FROM users WHERE email = ?");
        $chk->execute([$email]);
        if ($chk->fetch()) jsonError('Email déjà utilisé', 409);
        $stmt = db()->prepare(
            "INSERT INTO users (email, password_hash, role, prenom, nom, active, created_at)
             VALUES (?, 'PLACEHOLDER', ?, ?, ?, 1, NOW())"
        );
        $stmt->execute([$email, $role, $prenom, $nom]);
        $id = (int)db()->lastInsertId();
        logAction((int)$admin['id'], 'user_create', "id=$id email=$email role=$role");
        jsonOk(['user' => ['id' => $id, 'email' => $email, 'role' => $role, 'prenom' => $prenom, 'nom' => $nom, 'active' => 1]]);
    }

    case 'update': {
        $id = (int)($input['id'] ?? 0);
        if (!$id) jsonError('id requis');
        $fields = [];
        $vals = [];
        if (isset($input['prenom'])) { $fields[] = 'prenom = ?'; $vals[] = substr((string)$input['prenom'], 0, 100); }
        if (isset($input['nom']))    { $fields[] = 'nom = ?';    $vals[] = substr((string)$input['nom'], 0, 100); }
        if (isset($input['role'])) {
            if (!in_array($input['role'], ['admin', 'agent', 'visiteur'], true)) jsonError('Rôle invalide');
            $fields[] = 'role = ?'; $vals[] = (string)$input['role'];
        }
        if (isset($input['active'])) { $fields[] = 'active = ?'; $vals[] = (int)((bool)$input['active']); }
        if (!$fields) jsonError('Aucun champ à mettre à jour');
        $vals[] = $id;
        $stmt = db()->prepare("UPDATE users SET " . implode(', ', $fields) . " WHERE id = ?");
        $stmt->execute($vals);
        logAction((int)$admin['id'], 'user_update', "id=$id");
        jsonOk(['updated' => $id]);
    }

    case 'reset_password': {
        $id = (int)($input['id'] ?? 0);
        if (!$id) jsonError('id requis');
        db()->prepare("UPDATE users SET password_hash = 'PLACEHOLDER' WHERE id = ?")->execute([$id]);
        db()->prepare("DELETE FROM sessions WHERE user_id = ?")->execute([$id]);
        logAction((int)$admin['id'], 'user_reset_password', "id=$id");
        jsonOk(['reset' => $id]);
    }

    case 'delete': {
        $id = (int)($input['id'] ?? 0);
        if (!$id) jsonError('id requis');
        if ($id === (int)$admin['id']) jsonError('Impossible de se supprimer soi-même', 403);
        db()->prepare("DELETE FROM users WHERE id = ?")->execute([$id]);
        logAction((int)$admin['id'], 'user_delete', "id=$id");
        jsonOk(['deleted' => $id]);
    }

    case 'logs': {
        $limit = (int)($_GET['limit'] ?? 50);
        if ($limit < 1) $limit = 50;
        if ($limit > 200) $limit = 200;
        $stmt = db()->prepare(
            "SELECT l.id, l.user_id, u.email, l.action, l.details, l.ip, l.created_at
             FROM logs l LEFT JOIN users u ON u.id = l.user_id
             ORDER BY l.created_at DESC LIMIT ?"
        );
        $stmt->bindValue(1, $limit, PDO::PARAM_INT);
        $stmt->execute();
        jsonOk(['logs' => $stmt->fetchAll()]);
    }

    case 'stats': {
        $total_users    = (int)db()->query("SELECT COUNT(*) FROM users WHERE active = 1")->fetchColumn();
        $total_clients  = (int)db()->query("SELECT COUNT(*) FROM clients")->fetchColumn();
        $total_drafts   = (int)db()->query("SELECT COUNT(*) FROM clients WHERE is_draft = 1")->fetchColumn();
        $total_archived = (int)db()->query("SELECT COUNT(*) FROM clients WHERE archived = 1")->fetchColumn();
        $logins_24h = (int)db()->query(
            "SELECT COUNT(*) FROM logs WHERE action = 'login'
             AND created_at > DATE_SUB(NOW(), INTERVAL 1 DAY)"
        )->fetchColumn();
        $by_role = db()->query(
            "SELECT role, COUNT(*) AS n FROM users WHERE active = 1 GROUP BY role"
        )->fetchAll();
        jsonOk([
            'total_users' => $total_users,
            'total_clients' => $total_clients,
            'total_drafts' => $total_drafts,
            'total_archived' => $total_archived,
            'logins_24h' => $logins_24h,
            'by_role' => $by_role,
        ]);
    }

    default:
        jsonError('Action inconnue', 404);
}
