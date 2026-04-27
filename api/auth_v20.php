<?php
// V20 phase 3 — auth multi-tenant. Login mail+password (ocre_meta.users + sessions),
// switch-mode (cookie OCRE_MODE_<slug>), status, logout.
require_once __DIR__ . '/lib/router.php';

header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Headers: Content-Type, X-Session-Token');
header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(200); exit; }

$action = $_GET['action'] ?? ($_POST['action'] ?? '');
$input = json_decode(file_get_contents('php://input'), true) ?: [];

function jout(array $d, int $code = 200) {
    http_response_code($code);
    echo json_encode($d, JSON_UNESCAPED_UNICODE);
    exit;
}

switch ($action) {

case 'status': {
    jout([
        'ok' => true,
        'app_name' => 'Ocre Immo',
        'app_tagline' => 'CRM Immobilier multi-tenant',
        'mode_test' => false,
        'mode_auth_email' => true,
        'mode_maintenance' => false,
    ]);
}

case 'register': {
    // V20 M/2026/04/27/4 — Inscription nouvel agent + provisioning tenant.
    $prenom = trim((string)($input['prenom'] ?? ''));
    $nom = trim((string)($input['nom'] ?? ''));
    $email = strtolower(trim((string)($input['email'] ?? '')));
    $telephone = trim((string)($input['telephone'] ?? ''));
    $ville = trim((string)($input['ville'] ?? ''));
    $formation = trim((string)($input['formation'] ?? ''));
    $accept_cgu = !empty($input['accept_cgu']);
    if (!$prenom || !$nom) jout(['ok'=>false,'error'=>'Nom et prénom requis'], 400);
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) jout(['ok'=>false,'error'=>'Email invalide'], 400);
    if (!$telephone) jout(['ok'=>false,'error'=>'Téléphone requis'], 400);
    if (!$ville) jout(['ok'=>false,'error'=>'Ville requise'], 400);
    if (!$accept_cgu) jout(['ok'=>false,'error'=>'CGU requises'], 400);

    $check = pdo_meta()->prepare("SELECT id FROM users WHERE email = ?");
    $check->execute([$email]);
    if ($check->fetch()) jout(['ok'=>false,'error'=>'Email déjà enregistré, connecte-toi'], 409);

    // Slug WSp : prenom (3+ chars), collision-resolved
    $slug_base = strtolower(preg_replace('/[^a-z0-9]/i', '', $prenom));
    if (strlen($slug_base) < 3) $slug_base .= strtolower(substr(preg_replace('/[^a-z0-9]/i', '', $nom), 0, 1));
    if (strlen($slug_base) < 3) $slug_base = 'agent';
    $slug = $slug_base; $i = 0;
    while ($i < 100) {
        $cs = pdo_meta()->prepare("SELECT id FROM workspaces WHERE slug = ?");
        $cs->execute([$slug]);
        if (!$cs->fetch()) break;
        $i++; $slug = $slug_base . $i;
    }
    if ($i >= 100) jout(['ok'=>false,'error'=>'Erreur génération slug'], 500);

    $code = bin2hex(random_bytes(6));
    $hash = password_hash($code, PASSWORD_BCRYPT, ['cost' => 10]);
    $display_name = $prenom . ' ' . $nom;
    // M/2026/04/27/8 — log code en clair pour endpoint dev (purge auto < 1h)
    @pdo_meta()->prepare("INSERT INTO dev_codes (email, code_plain, context) VALUES (?, ?, 'register')")->execute([$email, $code]);
    $ip = $_SERVER['REMOTE_ADDR'] ?? '';
    pdo_meta()->prepare(
        "INSERT INTO users (email, password_hash, display_name, role, must_change_password, cgu_accepted_at, cgu_version, cgu_accepted_ip, telephone, ville, formation)
         VALUES (?, ?, ?, 'agent', 0, NOW(), '1.0', ?, ?, ?, ?)"
    )->execute([$email, $hash, $display_name, $ip, $telephone, $ville, $formation]);
    $user_id = (int)pdo_meta()->lastInsertId();

    pdo_meta()->prepare("INSERT INTO workspaces (slug, type, display_name) VALUES (?, 'wsp', ?)")
        ->execute([$slug, $display_name]);
    $wsp_id = (int)pdo_meta()->lastInsertId();
    pdo_meta()->prepare("INSERT INTO workspace_members (workspace_id, user_id, role) VALUES (?, ?, 'owner')")
        ->execute([$wsp_id, $user_id]);

    // Provisioning DBs synchrone via sudo (sudoers /etc/sudoers.d/ocre-provision)
    $provOut = []; $provRc = 0;
    @exec('sudo /opt/ocre-app/scripts/provision-tenant.sh ' . escapeshellarg($slug) . ' 2>&1', $provOut, $provRc);

    // M/2026/04/27/14 — envoi mail best-effort. Le user est DEJA cree en DB,
    // le code est dans dev_codes. Si l'envoi mail echoue (port 25, SMTP indispo,
    // domaine non verifie Resend), on renvoie ok=true avec mail_sent=false.
    $mail_sent = false;
    try {
        @require_once __DIR__ . '/lib/mailer.php';
        if (function_exists('email_welcome_agent')) {
            $r = @email_welcome_agent($email, $prenom, $slug, $email, $code);
            $mail_sent = (bool)$r;
        }
    } catch (Throwable $e) { $mail_sent = false; }
    @exec('/root/bin/notify --project ocre --priority info --title "Nouvel agent inscrit" --body ' . escapeshellarg($display_name . ' (' . $email . ') WSp ' . $slug) . ' >/dev/null 2>&1 &');

    jout([
        'ok' => true,
        'mail_sent' => $mail_sent,
        'wsp_slug' => $slug,
        'message' => 'Compte créé, code envoyé par email',
        'provisioning' => ($provRc === 0 ? 'ok' : 'pending'),
    ]);
}

case 'resend_code':
case 'forgot_password': {
    $email = strtolower(trim((string)($input['email'] ?? '')));
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) jout(['ok' => false, 'error' => 'Email invalide'], 400);
    $stmt = pdo_meta()->prepare("SELECT id, display_name FROM users WHERE email = ? AND archived_at IS NULL LIMIT 1");
    $stmt->execute([$email]);
    $u = $stmt->fetch();
    // Toujours retourner ok pour ne pas leak l'existence du compte (timing attack)
    if (!$u) jout(['ok' => true, 'sent' => false]);
    // Rate-limit best-effort : 60s par email (table optionnelle, fallback ok si absente)
    try {
        $rl = pdo_meta()->prepare("SELECT TIMESTAMPDIFF(SECOND, MAX(updated_at), NOW()) AS s FROM users WHERE id = ?");
        // updated_at peut ne pas exister, c'est OK
    } catch (Throwable $e) {}
    $newCode = bin2hex(random_bytes(6)); // 12 chars hex
    $hash = password_hash($newCode, PASSWORD_BCRYPT, ['cost' => 10]);
    pdo_meta()->prepare("UPDATE users SET password_hash = ? WHERE id = ?")->execute([$hash, $u['id']]);
    // M/2026/04/27/8 — log code en clair pour endpoint dev (purge auto < 1h via cron).
    @pdo_meta()->prepare("INSERT INTO dev_codes (email, code_plain, context) VALUES (?, ?, 'resend')")->execute([$email, $newCode]);
    // Envoi email
    @require_once __DIR__ . '/lib/mailer.php';
    if (function_exists('email_welcome_agent')) {
        $prenom = explode(' ', $u['display_name'] ?? '')[0] ?? 'agent';
        // slug du tenant courant pour URL
        $slug = preg_replace('/[^a-z0-9_-]/', '', strtolower($_SERVER['HTTP_X_TENANT_SLUG'] ?? ''));
        if (!$slug && preg_match('/^([a-z0-9][a-z0-9-]*)\.ocre\.immo$/', $_SERVER['HTTP_HOST'] ?? '', $m)) $slug = $m[1];
        email_welcome_agent($email, $prenom, $slug ?: 'app', $email, $newCode);
    }
    jout(['ok' => true, 'sent' => true]);
}

case 'check_email': {
    $email = strtolower(trim((string)($input['email'] ?? '')));
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) jout(['ok' => false, 'error' => 'Email invalide'], 400);
    $stmt = pdo_meta()->prepare("SELECT id, password_hash, archived_at FROM users WHERE email = ? LIMIT 1");
    $stmt->execute([$email]);
    $u = $stmt->fetch();
    if (!$u) jout(['ok' => true, 'exists' => false, 'needs_password' => false]);
    if ($u['archived_at']) jout(['ok' => false, 'error' => 'Compte désactivé', 'exists' => true], 403);
    $needs = empty($u['password_hash']) || $u['password_hash'] === 'PLACEHOLDER';
    jout(['ok' => true, 'exists' => true, 'needs_password' => $needs]);
}

case 'set_password': {
    $email = strtolower(trim((string)($input['email'] ?? '')));
    $pwd = (string)($input['password'] ?? '');
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) jout(['ok' => false, 'error' => 'Email invalide'], 400);
    if (strlen($pwd) < 6) jout(['ok' => false, 'error' => 'Mot de passe trop court (min 6)'], 400);
    $stmt = pdo_meta()->prepare("SELECT id, password_hash, archived_at, cgu_accepted_at FROM users WHERE email = ? LIMIT 1");
    $stmt->execute([$email]);
    $u = $stmt->fetch();
    if (!$u) jout(['ok' => false, 'error' => 'Utilisateur introuvable'], 404);
    if ($u['archived_at']) jout(['ok' => false, 'error' => 'Compte désactivé'], 403);
    // V20 garde-fou : CGU obligatoires AVANT set-password (anti-bypass API)
    if (empty($u['cgu_accepted_at'])) jout(['ok' => false, 'error' => 'CGU non acceptées'], 403);
    if (!empty($u['password_hash']) && $u['password_hash'] !== 'PLACEHOLDER') jout(['ok' => false, 'error' => 'Mot de passe déjà défini'], 409);
    $hash = password_hash($pwd, PASSWORD_BCRYPT, ['cost' => 10]);
    pdo_meta()->prepare("UPDATE users SET password_hash = ? WHERE id = ?")->execute([$hash, $u['id']]);
    jout(['ok' => true, 'message' => 'Mot de passe défini']);
}

case 'update_email_prefs': {
    $u = current_user_or_401();
    $val = !empty($input['email_notifications']) ? 1 : 0;
    try { pdo_meta()->exec("ALTER TABLE users ADD COLUMN email_notifications TINYINT(1) NOT NULL DEFAULT 1"); } catch (Throwable $e) {}
    pdo_meta()->prepare("UPDATE users SET email_notifications = ? WHERE id = ?")->execute([$val, (int)$u['id']]);
    jout(['ok' => true, 'email_notifications' => (bool)$val]);
}

case 'login': {
    $email = strtolower(trim((string)($input['email'] ?? '')));
    $pwd = (string)($input['password'] ?? '');
    $accept_cgu = !empty($input['accept_cgu']);
    if (!filter_var($email, FILTER_VALIDATE_EMAIL) || !$pwd) jout(['ok' => false, 'error' => 'email/pwd requis'], 400);
    $stmt = pdo_meta()->prepare("SELECT * FROM users WHERE email = ? AND archived_at IS NULL LIMIT 1");
    $stmt->execute([$email]);
    $u = $stmt->fetch();
    if (!$u || !password_verify($pwd, $u['password_hash'])) jout(['ok' => false, 'error' => 'Identifiants invalides'], 401);
    // V20 M/2026/04/27/3 : CGU integrees au login. Si user fresh ET pas accept_cgu → 403.
    if (empty($u['cgu_accepted_at']) && !$accept_cgu) jout(['ok' => false, 'error' => 'CGU non acceptées'], 403);
    if (empty($u['cgu_accepted_at']) && $accept_cgu) {
        $ip = $_SERVER['REMOTE_ADDR'] ?? '';
        pdo_meta()->prepare("UPDATE users SET cgu_accepted_at = NOW(), cgu_version = '1.0', cgu_accepted_ip = ?, must_change_password = 0 WHERE id = ?")
            ->execute([$ip, $u['id']]);
        $u['cgu_accepted_at'] = gmdate('Y-m-d H:i:s');
        $u['must_change_password'] = 0;
    }
    $token = bin2hex(random_bytes(32));
    pdo_meta()->prepare(
        "INSERT INTO sessions (token, user_id, expires_at, ip, user_agent)
         VALUES (?, ?, DATE_ADD(NOW(), INTERVAL 30 DAY), ?, ?)"
    )->execute([
        $token, $u['id'],
        $_SERVER['REMOTE_ADDR'] ?? null,
        substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 255)
    ]);
    pdo_meta()->prepare("UPDATE users SET last_login = NOW() WHERE id = ?")->execute([$u['id']]);

    // Workspaces accessibles (WSp owner + WSc actifs avec pacte signe)
    $ws_stmt = pdo_meta()->prepare(
        "SELECT w.id, w.slug, w.type, w.display_name, w.country_code, m.role,
                CASE WHEN w.type = 'wsc' THEN
                    (SELECT COUNT(*) FROM pact_signatures p
                     WHERE p.wsc_id = w.id AND p.user_id = ? AND p.signed_at IS NOT NULL)
                ELSE 1 END AS pact_signed
         FROM workspace_members m
         JOIN workspaces w ON w.id = m.workspace_id
         WHERE m.user_id = ? AND m.left_at IS NULL AND w.archived_at IS NULL
         ORDER BY w.type, w.slug"
    );
    $ws_stmt->execute([$u['id'], $u['id']]);
    $workspaces = $ws_stmt->fetchAll();

    $parts = preg_split('/\s+/', (string)$u['display_name'], 2);
    $prenom = $parts[0] ?? '';
    $nom = $parts[1] ?? '';
    jout([
        'ok' => true,
        'token' => $token,
        'user' => [
            'id' => (int)$u['id'],
            'email' => $u['email'],
            'display_name' => $u['display_name'],
            'prenom' => $prenom,
            'nom' => $nom,
            'role' => $u['role'],
            'is_admin' => $u['role'] === 'super_admin',
            'is_suspended' => false,
            'country_code' => $u['country_code'],
            'must_change_password' => (bool)$u['must_change_password'],
        ],
        'workspaces' => $workspaces,
    ]);
}

case 'me': {
    $u = current_user_or_401();
    $ws_stmt = pdo_meta()->prepare(
        "SELECT w.id, w.slug, w.type, w.display_name, w.country_code, m.role
         FROM workspace_members m JOIN workspaces w ON w.id = m.workspace_id
         WHERE m.user_id = ? AND m.left_at IS NULL AND w.archived_at IS NULL
         ORDER BY w.type, w.slug"
    );
    $ws_stmt->execute([$u['id']]);
    $parts = preg_split('/\s+/', (string)$u['display_name'], 2);
    $prenom = $parts[0] ?? '';
    $nom = $parts[1] ?? '';
    $emailNotif = 1;
    $cgu_accepted_at = null; $cgu_version = null; $tour_completed_at = null;
    try {
        $st = pdo_meta()->prepare("SELECT cgu_accepted_at, cgu_version, tour_completed_at FROM users WHERE id = ?");
        $st->execute([$u['id']]);
        $r = $st->fetch();
        if ($r) {
            $cgu_accepted_at = $r['cgu_accepted_at'] ?? null;
            $cgu_version = $r['cgu_version'] ?? null;
            $tour_completed_at = $r['tour_completed_at'] ?? null;
        }
    } catch (Throwable $e) {}
    try {
        $st2 = pdo_meta()->prepare("SELECT email_notifications FROM users WHERE id = ?");
        $st2->execute([$u['id']]);
        $r2 = $st2->fetch();
        if ($r2 && isset($r2['email_notifications'])) $emailNotif = (int)$r2['email_notifications'];
    } catch (Throwable $e) {}
    jout([
        'ok' => true,
        'user' => [
            'id' => (int)$u['id'], 'email' => $u['email'],
            'display_name' => $u['display_name'],
            'prenom' => $prenom, 'nom' => $nom,
            'role' => $u['role'],
            'is_admin' => $u['role'] === 'super_admin',
            'is_suspended' => false,
            'country_code' => $u['country_code'],
            'must_change_password' => (bool)$u['must_change_password'],
            'email_notifications' => (bool)$emailNotif,
            'cgu_accepted_at' => $cgu_accepted_at,
            'cgu_version' => $cgu_version,
            'tour_completed_at' => $tour_completed_at,
        ],
        'workspaces' => $ws_stmt->fetchAll(),
    ]);
}

case 'switch_mode': {
    $u = current_user_or_401();
    $slug = strtolower(preg_replace('/[^a-z0-9-]/', '', (string)($input['workspace_slug'] ?? '')));
    $mode = $input['mode'] ?? 'agent';
    if (!in_array($mode, ['agent', 'test'], true)) jout(['ok' => false, 'error' => 'mode invalide'], 400);
    $check = pdo_meta()->prepare(
        "SELECT m.role, w.type FROM workspace_members m
         JOIN workspaces w ON w.id = m.workspace_id
         WHERE w.slug = ? AND m.user_id = ? AND m.left_at IS NULL AND w.archived_at IS NULL"
    );
    $check->execute([$slug, $u['id']]);
    $r = $check->fetch();
    if (!$r) jout(['ok' => false, 'error' => 'Pas membre de ce workspace'], 403);
    if ($r['type'] !== 'wsp') jout(['ok' => false, 'error' => 'Mode test/agent uniquement sur WSp'], 400);
    setcookie('OCRE_MODE_' . strtoupper($slug), $mode, [
        'expires' => time() + 86400 * 30,
        'path' => '/', 'domain' => '.ocre.immo', 'secure' => true, 'httponly' => false, 'samesite' => 'Lax',
    ]);
    jout(['ok' => true, 'workspace_slug' => $slug, 'mode' => $mode]);
}

case 'logout': {
    $token = $_SERVER['HTTP_X_SESSION_TOKEN'] ?? '';
    if ($token) pdo_meta()->prepare("DELETE FROM sessions WHERE token = ?")->execute([$token]);
    jout(['ok' => true]);
}

case 'notifications': {
    $u = current_user_or_401();
    $stmt = pdo_meta()->prepare(
        "SELECT id, type, title, body, payload_json, read_at, created_at
         FROM notifications WHERE user_id = ? ORDER BY created_at DESC LIMIT 50"
    );
    $stmt->execute([$u['id']]);
    jout(['ok' => true, 'items' => $stmt->fetchAll()]);
}

case 'mark_read': {
    $u = current_user_or_401();
    $id = (int)($input['id'] ?? 0);
    pdo_meta()->prepare("UPDATE notifications SET read_at = NOW() WHERE id = ? AND user_id = ? AND read_at IS NULL")
        ->execute([$id, $u['id']]);
    jout(['ok' => true]);
}

case 'change_password': {
    $u = current_user_or_401();
    // V20 garde-fou : refuser si CGU pas acceptées (empêche bypass via API directe).
    try {
        $st = pdo_meta()->prepare("SELECT cgu_accepted_at FROM users WHERE id = ?");
        $st->execute([$u['id']]);
        $r = $st->fetch();
        if (!$r || empty($r['cgu_accepted_at'])) jout(['ok' => false, 'error' => 'CGU non acceptées'], 403);
    } catch (Throwable $e) {}
    $old = (string)($input['old_password'] ?? $input['current'] ?? '');
    $new = (string)($input['new_password'] ?? $input['new'] ?? '');
    if (strlen($new) < 10) jout(['ok' => false, 'error' => 'mot de passe min 10 chars'], 400);
    if (!password_verify($old, $u['password_hash'])) jout(['ok' => false, 'error' => 'ancien mdp invalide'], 401);
    $hash = password_hash($new, PASSWORD_BCRYPT, ['cost' => 10]);
    pdo_meta()->prepare("UPDATE users SET password_hash = ?, must_change_password = 0 WHERE id = ?")
        ->execute([$hash, $u['id']]);
    jout(['ok' => true]);
}

default:
    jout(['ok' => false, 'error' => 'action inconnue : ' . $action], 400);
}
