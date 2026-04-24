<?php
// V18.40 — Dashboard admin. Toutes les actions dans un seul endpoint avec ?action=...
// Tous les handlers exigent requireAdmin() et logguent dans admin_actions.
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/_security.php';
setCorsHeaders();

$admin = requireAdmin();
$action = $_GET['action'] ?? ($_POST['action'] ?? '');
$input = getInput();

function logAdminAction(int $admin_id, string $action, ?int $target_id = null, ?array $meta = null): void {
    try {
        $st = db()->prepare("INSERT INTO admin_actions (admin_user_id, action, target_user_id, meta, ip) VALUES (?, ?, ?, ?, ?)");
        $st->execute([
            $admin_id,
            $action,
            $target_id,
            $meta ? json_encode($meta, JSON_UNESCAPED_UNICODE) : null,
            $_SERVER['REMOTE_ADDR'] ?? '',
        ]);
    } catch (Exception $e) { /* silent */ }
}

switch ($action) {

    case 'users': {
        // Liste enrichie : id/email/nom/is_admin/is_suspended/last_login + counts.
        $rows = db()->query(
            "SELECT u.id, u.email, u.prenom, u.nom, u.role, u.is_admin, u.is_suspended,
                    u.must_change_password, u.last_login, u.created_at, u.active,
                    (SELECT COUNT(*) FROM clients c WHERE c.user_id = u.id) AS dossiers_count,
                    (SELECT COUNT(*) FROM login_attempts la WHERE la.email = u.email AND la.locked_until > NOW()) AS is_locked
             FROM users u
             ORDER BY u.last_login DESC, u.id DESC"
        )->fetchAll(PDO::FETCH_ASSOC);
        jsonOk(['users' => array_map(function($r) {
            return [
                'id' => (int) $r['id'],
                'email' => $r['email'],
                'prenom' => $r['prenom'],
                'nom' => $r['nom'],
                'role' => $r['role'],
                'is_admin' => (bool) (int) ($r['is_admin'] ?? 0),
                'is_suspended' => (bool) (int) ($r['is_suspended'] ?? 0),
                'must_change_password' => (bool) (int) ($r['must_change_password'] ?? 0),
                'active' => (bool) (int) $r['active'],
                'last_login' => $r['last_login'],
                'created_at' => $r['created_at'],
                'dossiers_count' => (int) $r['dossiers_count'],
                'is_locked' => (int) $r['is_locked'] > 0,
            ];
        }, $rows)]);
    }

    case 'unlock': {
        $user_id = (int) ($input['user_id'] ?? 0);
        if (!$user_id) jsonError('user_id requis');
        $st = db()->prepare("SELECT email FROM users WHERE id = ? LIMIT 1");
        $st->execute([$user_id]);
        $u = $st->fetch();
        if (!$u) jsonError('User introuvable', 404);
        $up = db()->prepare("DELETE FROM login_attempts WHERE email = ?");
        $up->execute([$u['email']]);
        $n = $up->rowCount();
        logAdminAction((int) $admin['id'], 'unlock', $user_id, ['email' => $u['email'], 'rows' => $n]);
        jsonOk(['unlocked' => true, 'login_attempts_rows_deleted' => $n]);
    }

    case 'reset_password': {
        $user_id = (int) ($input['user_id'] ?? 0);
        if (!$user_id) jsonError('user_id requis');
        $st = db()->prepare("SELECT id, email FROM users WHERE id = ? LIMIT 1");
        $st->execute([$user_id]);
        $u = $st->fetch();
        if (!$u) jsonError('User introuvable', 404);
        // MDP temp format spec : "Ocre" + 6 chars alphanumérique + "!"
        $chars = 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghjkmnpqrstuvwxyz23456789';
        $rand = '';
        for ($i = 0; $i < 6; $i++) $rand .= $chars[random_int(0, strlen($chars) - 1)];
        $temp = 'Ocre' . $rand . '!';
        $hash = password_hash($temp, PASSWORD_BCRYPT);
        $up = db()->prepare("UPDATE users SET password_hash = ?, must_change_password = 1 WHERE id = ?");
        $up->execute([$hash, $user_id]);
        // Invalidate sessions pour forcer reconnexion.
        try { db()->prepare("DELETE FROM sessions WHERE user_id = ?")->execute([$user_id]); } catch (Exception $e) {}
        // Unlock tentatives aussi.
        try { db()->prepare("DELETE FROM login_attempts WHERE email = ?")->execute([$u['email']]); } catch (Exception $e) {}
        logAdminAction((int) $admin['id'], 'reset_password', $user_id, ['email' => $u['email']]);
        jsonOk([
            'temp_password' => $temp,
            'message' => 'Communique ce mot de passe à l\'utilisateur par un canal sécurisé. Il devra le changer au prochain login.',
        ]);
    }

    case 'suspend': {
        $user_id = (int) ($input['user_id'] ?? 0);
        $suspended = !empty($input['suspended']) ? 1 : 0;
        if (!$user_id) jsonError('user_id requis');
        if ($user_id === (int) $admin['id']) jsonError('Tu ne peux pas te suspendre toi-même', 400);
        $up = db()->prepare("UPDATE users SET is_suspended = ? WHERE id = ?");
        $up->execute([$suspended, $user_id]);
        $sessionsKilled = 0;
        if ($suspended) {
            try {
                $ds = db()->prepare("DELETE FROM sessions WHERE user_id = ?");
                $ds->execute([$user_id]);
                $sessionsKilled = $ds->rowCount();
            } catch (Exception $e) {}
            // Stop any active impersonation ciblant cet user.
            try {
                db()->prepare("UPDATE impersonation_sessions SET stopped_at = NOW() WHERE target_user_id = ? AND stopped_at IS NULL")
                    ->execute([$user_id]);
            } catch (Exception $e) {}
        }
        logAdminAction((int) $admin['id'], $suspended ? 'suspend' : 'unsuspend', $user_id, ['sessions_killed' => $sessionsKilled]);
        jsonOk(['is_suspended' => (bool) $suspended, 'sessions_killed' => $sessionsKilled]);
    }

    case 'impersonate_start': {
        $target_id = (int) ($input['target_user_id'] ?? 0);
        if (!$target_id) jsonError('target_user_id requis');
        if ($target_id === (int) $admin['id']) jsonError('Cible = toi-même', 400);
        $st = db()->prepare("SELECT id, email, is_suspended, active FROM users WHERE id = ? LIMIT 1");
        $st->execute([$target_id]);
        $t = $st->fetch();
        if (!$t) jsonError('User cible introuvable', 404);
        if (!(int) $t['active']) jsonError('User cible inactif', 400);
        if ((int) $t['is_suspended']) jsonError('User cible suspendu', 400);

        // Ferme toute impersonation précédente de cet admin.
        try {
            db()->prepare("UPDATE impersonation_sessions SET stopped_at = NOW() WHERE admin_user_id = ? AND stopped_at IS NULL")
                ->execute([(int) $admin['id']]);
        } catch (Exception $e) {}

        $token = bin2hex(random_bytes(32));
        $expires = date('Y-m-d H:i:s', time() + 3600);
        $ins = db()->prepare(
            "INSERT INTO impersonation_sessions (admin_user_id, target_user_id, token, expires_at) VALUES (?, ?, ?, ?)"
        );
        $ins->execute([(int) $admin['id'], $target_id, $token, $expires]);
        logAdminAction((int) $admin['id'], 'impersonate_start', $target_id, ['email' => $t['email']]);
        jsonOk([
            'impersonation_token' => $token,
            'expires_at' => $expires,
            'target_email' => $t['email'],
        ]);
    }

    case 'impersonate_stop': {
        $token = (string) ($input['token'] ?? ($_SERVER['HTTP_X_IMPERSONATION_TOKEN'] ?? ''));
        if (!$token) jsonError('token requis (body ou header)');
        $st = db()->prepare(
            "UPDATE impersonation_sessions SET stopped_at = NOW()
             WHERE token = ? AND admin_user_id = ? AND stopped_at IS NULL"
        );
        $st->execute([$token, (int) $admin['id']]);
        logAdminAction((int) $admin['id'], 'impersonate_stop', null, ['token_prefix' => substr($token, 0, 8)]);
        jsonOk(['stopped' => $st->rowCount()]);
    }

    case 'metrics': {
        $pdo = db();
        $usersTotal = (int) $pdo->query("SELECT COUNT(*) n FROM users WHERE active = 1")->fetch()['n'];
        $usersActive30d = (int) $pdo->query("SELECT COUNT(*) n FROM users WHERE last_login > (NOW() - INTERVAL 30 DAY)")->fetch()['n'];
        $usersSuspended = (int) $pdo->query("SELECT COUNT(*) n FROM users WHERE is_suspended = 1")->fetch()['n'];
        $usersLockedNow = 0;
        try {
            $usersLockedNow = (int) $pdo->query("SELECT COUNT(DISTINCT email) n FROM login_attempts WHERE locked_until > NOW()")->fetch()['n'];
        } catch (Exception $e) {}

        // Dossiers par profil (non-archivés + non-staged)
        $rows = $pdo->query(
            "SELECT projet, COUNT(*) n FROM clients
             WHERE archived = 0 AND (is_staged IS NULL OR is_staged = 0)
             GROUP BY projet"
        )->fetchAll(PDO::FETCH_ASSOC);
        $dossiersByProfil = [];
        foreach ($rows as $r) $dossiersByProfil[$r['projet']] = (int) $r['n'];
        $dossiersTotal = array_sum($dossiersByProfil);

        // Photos size
        $photosSizeBytes = 0;
        $uploadsBase = realpath(__DIR__ . '/../uploads');
        if ($uploadsBase && is_dir($uploadsBase)) {
            try {
                $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($uploadsBase, FilesystemIterator::SKIP_DOTS));
                foreach ($it as $f) if ($f->isFile()) $photosSizeBytes += $f->getSize();
            } catch (Exception $e) {}
        }

        // Anthropic cost estimé (approximation très grossière)
        $anthropicCalls30d = 0;
        try {
            $c = $pdo->query("SELECT COUNT(*) n FROM access_logs WHERE action IN ('cross_search', 'import_url_extract', 'import_image_extract') AND created_at > (NOW() - INTERVAL 30 DAY)")->fetch();
            if ($c) $anthropicCalls30d = (int) $c['n'];
        } catch (Exception $e) {}
        $anthropicCostUsd30d = round($anthropicCalls30d * 0.02, 2); // ~2¢/call moyenne Haiku

        // Push sent 30d (si table existe)
        $pushSent30d = 0;
        try {
            $c = $pdo->query("SELECT COUNT(*) n FROM push_notifications WHERE sent_at > (NOW() - INTERVAL 30 DAY)")->fetch();
            if ($c) $pushSent30d = (int) $c['n'];
        } catch (Exception $e) {}

        // Login attempts blocked 30d
        $loginBlocks30d = 0;
        try {
            $c = $pdo->query("SELECT COUNT(*) n FROM access_logs WHERE action IN ('login_blocked', 'login_fail') AND created_at > (NOW() - INTERVAL 30 DAY)")->fetch();
            if ($c) $loginBlocks30d = (int) $c['n'];
        } catch (Exception $e) {}

        jsonOk([
            'users_total' => $usersTotal,
            'users_active_30d' => $usersActive30d,
            'users_suspended' => $usersSuspended,
            'users_locked_now' => $usersLockedNow,
            'dossiers_total' => $dossiersTotal,
            'dossiers_by_profil' => $dossiersByProfil,
            'photos_size_mb' => round($photosSizeBytes / 1048576, 1),
            'anthropic_calls_30d' => $anthropicCalls30d,
            'anthropic_cost_estimated_usd_30d' => $anthropicCostUsd30d,
            'push_sent_30d' => $pushSent30d,
            'login_blocks_30d' => $loginBlocks30d,
        ]);
    }

    case 'logs': {
        $user_id = isset($_GET['user_id']) ? (int) $_GET['user_id'] : null;
        $limit = min((int) ($_GET['limit'] ?? 100), 500);
        try {
            if ($user_id) {
                $st = db()->prepare("SELECT id, user_id, action, endpoint, ip, user_agent, meta, created_at FROM access_logs WHERE user_id = ? ORDER BY created_at DESC LIMIT ?");
                $st->bindValue(1, $user_id, PDO::PARAM_INT);
                $st->bindValue(2, $limit, PDO::PARAM_INT);
                $st->execute();
            } else {
                $st = db()->prepare("SELECT id, user_id, action, endpoint, ip, user_agent, meta, created_at FROM access_logs ORDER BY created_at DESC LIMIT ?");
                $st->bindValue(1, $limit, PDO::PARAM_INT);
                $st->execute();
            }
            jsonOk(['logs' => $st->fetchAll(PDO::FETCH_ASSOC)]);
        } catch (Exception $e) {
            jsonOk(['logs' => [], 'note' => 'access_logs table peut-être non-initialisée : ' . $e->getMessage()]);
        }
    }

    case 'admin_logs': {
        $limit = min((int) ($_GET['limit'] ?? 100), 500);
        $st = db()->prepare(
            "SELECT a.id, a.admin_user_id, au.email AS admin_email, a.action, a.target_user_id, tu.email AS target_email, a.meta, a.ip, a.created_at
             FROM admin_actions a
             LEFT JOIN users au ON au.id = a.admin_user_id
             LEFT JOIN users tu ON tu.id = a.target_user_id
             ORDER BY a.created_at DESC LIMIT ?"
        );
        $st->bindValue(1, $limit, PDO::PARAM_INT);
        $st->execute();
        jsonOk(['admin_logs' => $st->fetchAll(PDO::FETCH_ASSOC)]);
    }

    default:
        jsonError('action inconnue : ' . $action, 400);
}
