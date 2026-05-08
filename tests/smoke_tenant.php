<?php
// M/2026/05/08/36 — Helper CLI tenant éphémère pour smoke tests.
// Élimine la dépendance au tenant fixe `zefk`. Création/destruction à chaque run.
//
// Actions :
//   php smoke_tenant.php setup            → exporte ENV vars (slug, token, user_id) sur stdout en KEY=VALUE
//   php smoke_tenant.php teardown <slug> <user_id>  → DROP DB + DELETE user/workspace/sessions
//   php smoke_tenant.php cleanup_orphans  → purge tous résidus smoke_*
//
// Convention slug : "smoke-<unix_timestamp>" (matche /^[a-z0-9-]{3,40}$/ requis par _provision).

// M/2026/05/08/36 — smoke tests CLI : on charge depuis /opt/ocre-app/ qui a le .env prod.
// Le path /root/workspace/ocre-immo/ n'a pas de .env (gitignored, prod-only chez OVH).
$base = is_readable('/opt/ocre-app/api/db.php') ? '/opt/ocre-app/api' : __DIR__ . '/../api';
require_once $base . '/db.php';
require_once $base . '/_provision.php';

function _out(array $kv): void {
    foreach ($kv as $k => $v) {
        echo $k . '=' . $v . PHP_EOL;
    }
}

function _meta(): PDO {
    return new PDO(
        'mysql:host=' . DB_HOST . ';dbname=ocre_meta;charset=utf8mb4',
        DB_USER, DB_PASS,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
    );
}

$action = $argv[1] ?? '';

if ($action === 'setup') {
    $ts = time();
    $slug = 'smoke-' . $ts;
    $email = 'smoke+' . $ts . '@ocre-internal.test';
    $pwd = 'SmokeTestPwd2026';
    $pwdHash = password_hash($pwd, PASSWORD_BCRYPT, ['cost' => 10]);

    $meta = _meta();
    try {
        // 1. Provisionner workspace tenant DB
        $prov = provision_agent_workspace($slug, $meta);
        if (!$prov['ok']) {
            fwrite(STDERR, "setup FAIL provision: " . json_encode($prov) . PHP_EOL);
            exit(1);
        }

        // 2. Créer user actif
        $stmt = $meta->prepare(
            "INSERT INTO users
              (email, password_hash, display_name, prenom, nom, role, subscription_status, billing_plan, status,
               telephone, ville, country_code, slug, sensibility_preset, preferences,
               cgu_accepted, cgu_accepted_at, cgu_version, cgu_accepted_ip, cgu_accepted_user_agent,
               rgpd_accepted, rgpd_accepted_at, rgpd_version, rgpd_accepted_ip, rgpd_accepted_user_agent,
               telegram_notifs_enabled, email_notifs_enabled, created_at)
             VALUES (?, ?, 'Smoke Test', 'Smoke', 'TEST', 'super_admin', 'trial', 'decouverte', 'active',
                     '+33000000000', 'Test', 'FR', ?, 'equilibre', '{\"channels_enabled\":{\"email\":false}}',
                     1, NOW(), '1.0', '127.0.0.1', 'smoke',
                     1, NOW(), '1.0', '127.0.0.1', 'smoke',
                     0, 0, NOW())"
        );
        $stmt->execute([$email, $pwdHash, $slug]);
        $userId = (int)$meta->lastInsertId();

        // 3. Insérer la ligne workspace meta (provision_agent_workspace ne le fait pas)
        $wsName = 'Smoke Workspace ' . $ts;
        $insWs = $meta->prepare(
            "INSERT INTO workspaces (slug, type, display_name, country_code, created_at)
             VALUES (?, 'wsp', ?, 'FR', NOW())"
        );
        $insWs->execute([$slug, $wsName]);
        $wsId = (int)$meta->lastInsertId();
        $insMember = $meta->prepare(
            "INSERT INTO workspace_members (workspace_id, user_id, role, joined_at) VALUES (?, ?, 'owner', NOW())"
        );
        $insMember->execute([$wsId, $userId]);

        // 4. Créer session token (auto-login)
        $token = bin2hex(random_bytes(32));
        $insS = $meta->prepare(
            "INSERT INTO sessions (token, user_id, expires_at, ip, user_agent)
             VALUES (?, ?, DATE_ADD(NOW(), INTERVAL 1 HOUR), ?, ?)"
        );
        $insS->execute([$token, $userId, '127.0.0.1', 'smoke-tests-runner']);

        _out([
            'SMOKE_TENANT_SLUG' => $slug,
            'SMOKE_ADMIN_TOKEN' => $token,
            'SMOKE_USER_ID'     => $userId,
            'SMOKE_WS_ID'       => $wsId,
            'SMOKE_EMAIL'       => $email,
        ]);
        exit(0);
    } catch (Throwable $e) {
        fwrite(STDERR, 'setup ERROR: ' . $e->getMessage() . PHP_EOL);
        exit(1);
    }
}

if ($action === 'teardown') {
    $slug = $argv[2] ?? '';
    $userId = (int)($argv[3] ?? 0);
    if ($slug === '' || !preg_match('/^smoke-[0-9]+$/', $slug)) {
        fwrite(STDERR, 'teardown FAIL: invalid slug=' . $slug . PHP_EOL);
        exit(1);
    }
    $meta = _meta();
    $report = ['db_dropped' => false, 'sessions' => 0, 'members' => 0, 'workspaces' => 0, 'users' => 0];
    try {
        $dbName = 'ocre_wsp_' . $slug;
        try { $meta->exec("DROP DATABASE IF EXISTS `$dbName`"); $report['db_dropped'] = true; } catch (Throwable $_) {}
        if ($userId > 0) {
            $r = $meta->prepare("DELETE FROM sessions WHERE user_id = ?"); $r->execute([$userId]); $report['sessions'] = $r->rowCount();
            $r = $meta->prepare("DELETE FROM workspace_members WHERE user_id = ?"); $r->execute([$userId]); $report['members'] = $r->rowCount();
            $r = $meta->prepare("DELETE FROM users WHERE id = ?"); $r->execute([$userId]); $report['users'] = $r->rowCount();
        }
        $r = $meta->prepare("DELETE FROM workspaces WHERE slug = ?"); $r->execute([$slug]); $report['workspaces'] = $r->rowCount();
        echo json_encode($report) . PHP_EOL;
        exit(0);
    } catch (Throwable $e) {
        fwrite(STDERR, 'teardown ERROR: ' . $e->getMessage() . PHP_EOL);
        echo json_encode($report) . PHP_EOL;
        exit(1);
    }
}

if ($action === 'cleanup_orphans') {
    $meta = _meta();
    $report = ['dbs_dropped' => 0, 'sessions' => 0, 'members' => 0, 'workspaces' => 0, 'users' => 0, 'errors' => []];
    try {
        // 1. DROP toutes DBs ocre_wsp_smoke_*
        $dbs = $meta->query("SHOW DATABASES LIKE 'ocre_wsp_smoke-%'")->fetchAll(PDO::FETCH_COLUMN);
        foreach ($dbs as $db) {
            if (preg_match('/^ocre_wsp_smoke-[0-9]+$/', $db)) {
                try { $meta->exec("DROP DATABASE IF EXISTS `$db`"); $report['dbs_dropped']++; }
                catch (Throwable $e) { $report['errors'][] = "drop $db: " . $e->getMessage(); }
            }
        }
        // 2. Cleanup workspace_members + workspaces + users + sessions des smoke users
        $smokeUsers = $meta->query("SELECT id FROM users WHERE email LIKE 'smoke+%@ocre-internal.test'")->fetchAll(PDO::FETCH_COLUMN);
        if (!empty($smokeUsers)) {
            $ph = implode(',', array_fill(0, count($smokeUsers), '?'));
            $r = $meta->prepare("DELETE FROM sessions WHERE user_id IN ($ph)"); $r->execute($smokeUsers); $report['sessions'] = $r->rowCount();
            $r = $meta->prepare("DELETE FROM workspace_members WHERE user_id IN ($ph)"); $r->execute($smokeUsers); $report['members'] = $r->rowCount();
            $r = $meta->prepare("DELETE FROM users WHERE id IN ($ph)"); $r->execute($smokeUsers); $report['users'] = $r->rowCount();
        }
        $r = $meta->prepare("DELETE FROM workspaces WHERE slug LIKE 'smoke-%'"); $r->execute(); $report['workspaces'] = $r->rowCount();
        echo json_encode($report) . PHP_EOL;
        exit(0);
    } catch (Throwable $e) {
        fwrite(STDERR, 'cleanup_orphans ERROR: ' . $e->getMessage() . PHP_EOL);
        echo json_encode($report) . PHP_EOL;
        exit(1);
    }
}

fwrite(STDERR, "Usage: php smoke_tenant.php <setup|teardown SLUG USER_ID|cleanup_orphans>" . PHP_EOL);
exit(2);
