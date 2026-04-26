<?php
// V20 phase 6 — endpoints WSc : create, sign-pact, get_pact, request-rupture, cancel-rupture.
require_once __DIR__ . '/lib/router.php';
require_once __DIR__ . '/lib/pact_template.php';
header('Content-Type: application/json; charset=utf-8');

$action = $_GET['action'] ?? '';

function jout(array $d, int $code = 200) {
    http_response_code($code);
    echo json_encode($d, JSON_UNESCAPED_UNICODE);
    exit;
}

function notify_user(int $user_id, string $type, string $title, string $body, array $payload = []): void {
    pdo_meta()->prepare(
        "INSERT INTO notifications (user_id, type, title, body, payload_json) VALUES (?, ?, ?, ?, ?)"
    )->execute([$user_id, $type, $title, $body, json_encode($payload, JSON_UNESCAPED_UNICODE)]);
}

function audit(int $actor_id, ?int $workspace_id, string $action, string $target_type = '', $target_id = null, array $payload = []): void {
    pdo_meta()->prepare(
        "INSERT INTO audit_log (actor_user_id, workspace_id, action, target_type, target_id, payload_json, ip_address)
         VALUES (?, ?, ?, ?, ?, ?, ?)"
    )->execute([$actor_id, $workspace_id, $action, $target_type, $target_id, json_encode($payload, JSON_UNESCAPED_UNICODE), $_SERVER['REMOTE_ADDR'] ?? null]);
}

$user = current_user_or_401();
$input = json_decode(file_get_contents('php://input'), true) ?: [];

switch ($action) {

case 'create': {
    $slug = strtolower(preg_replace('/[^a-z0-9-]/', '', (string)($input['slug'] ?? '')));
    $display = trim((string)($input['display_name'] ?? ''));
    $country = strtoupper((string)($input['country_code'] ?? 'FR'));
    $member_emails = $input['member_emails'] ?? [];
    if (!$slug || !$display || !in_array($country, ['FR', 'MA'], true)) jout(['ok' => false, 'error' => 'inputs invalides'], 400);
    if (!is_array($member_emails) || count($member_emails) < 1) jout(['ok' => false, 'error' => 'au moins 1 membre invite requis'], 400);

    $pdo = pdo_meta();
    // Resolve members
    $member_ids = [(int)$user['id']];
    foreach ($member_emails as $em) {
        $em = strtolower(trim((string)$em));
        if ($em === strtolower($user['email'])) continue;
        $st = $pdo->prepare("SELECT id FROM users WHERE email = ? AND archived_at IS NULL LIMIT 1");
        $st->execute([$em]);
        $r = $st->fetch();
        if (!$r) jout(['ok' => false, 'error' => "membre $em introuvable"], 404);
        $member_ids[] = (int)$r['id'];
    }
    $member_ids = array_values(array_unique($member_ids));
    if (count($member_ids) < 2) jout(['ok' => false, 'error' => '2 membres min requis'], 400);

    // Create DB
    $db_name = 'ocre_wsc_' . $slug;
    $appPwd = DB_PASS;
    // Use shell to mysql (safer than runtime PDO CREATE DATABASE)
    $cmd = sprintf(
        "mysql -u %s -p%s -e %s 2>&1",
        escapeshellarg(DB_USER),
        escapeshellarg($appPwd),
        escapeshellarg("CREATE DATABASE IF NOT EXISTS \`$db_name\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci")
    );
    exec($cmd, $out, $rc);
    if ($rc !== 0) jout(['ok' => false, 'error' => 'CREATE DB KO : ' . implode("\n", $out)], 500);
    $schemaFile = __DIR__ . '/migrations/wsp_schema_v20.sql';
    if (is_file($schemaFile)) {
        $cmd2 = sprintf("mysql -u %s -p%s %s < %s 2>&1",
            escapeshellarg(DB_USER), escapeshellarg($appPwd),
            escapeshellarg($db_name), escapeshellarg($schemaFile));
        exec($cmd2, $o2, $r2);
    }

    // workspaces row
    $stmt = $pdo->prepare("INSERT INTO workspaces (slug, type, display_name, country_code) VALUES (?, 'wsc', ?, ?)");
    try { $stmt->execute([$slug, $display, $country]); } catch (Throwable $e) {
        jout(['ok' => false, 'error' => 'slug deja utilise ou erreur DB'], 409);
    }
    $wsc_id = (int)$pdo->lastInsertId();

    // memberships pending (members rejoignent active mais WSc verrouille tant que pacte non signe)
    $insMember = $pdo->prepare("INSERT INTO workspace_members (workspace_id, user_id, role, joined_at) VALUES (?, ?, 'member', NOW())");
    $insPact = $pdo->prepare("INSERT INTO pact_signatures (wsc_id, user_id, doc_version) VALUES (?, ?, 'v1')");
    foreach ($member_ids as $uid) {
        try { $insMember->execute([$wsc_id, $uid]); } catch (Throwable $e) {}
        try { $insPact->execute([$wsc_id, $uid]); } catch (Throwable $e) {}
    }

    // Notifications aux invites (autres que createur)
    foreach ($member_ids as $uid) {
        if ($uid === (int)$user['id']) continue;
        notify_user($uid, 'wsc_invitation',
            'Invitation au workspace partage ' . $display,
            'Tu es invite au WSc "' . $display . '" par ' . ($user['display_name'] ?? $user['email']) . '. Lis et signe le pacte de partenariat pour acceder.',
            ['wsc_slug' => $slug, 'wsc_id' => $wsc_id]
        );
    }
    audit((int)$user['id'], $wsc_id, 'wsc_create', 'workspace', $wsc_id, ['slug' => $slug, 'members' => $member_ids]);

    jout(['ok' => true, 'wsc' => ['id' => $wsc_id, 'slug' => $slug, 'display_name' => $display]]);
}

case 'get_pact': {
    $slug = strtolower(preg_replace('/[^a-z0-9-]/', '', (string)($_GET['slug'] ?? '')));
    $pdo = pdo_meta();
    $st = $pdo->prepare("SELECT * FROM workspaces WHERE slug = ? AND type = 'wsc' AND archived_at IS NULL LIMIT 1");
    $st->execute([$slug]);
    $ws = $st->fetch();
    if (!$ws) jout(['ok' => false, 'error' => 'WSc introuvable'], 404);
    // Check membership
    $m = $pdo->prepare("SELECT 1 FROM workspace_members WHERE workspace_id = ? AND user_id = ? AND left_at IS NULL");
    $m->execute([$ws['id'], $user['id']]);
    if (!$m->fetch() && $user['role'] !== 'super_admin') jout(['ok' => false, 'error' => 'pas membre'], 403);

    // Get all signataires
    $sig = $pdo->prepare(
        "SELECT u.id, u.email, u.display_name, u.country_code, u.pro_card_number, p.signed_at, p.sha256
         FROM pact_signatures p JOIN users u ON u.id = p.user_id
         WHERE p.wsc_id = ? ORDER BY u.id"
    );
    $sig->execute([$ws['id']]);
    $signataires = $sig->fetchAll();
    $html = generate_pact_html($ws, $signataires);
    $sha = hash('sha256', $html);
    jout(['ok' => true, 'wsc' => $ws, 'signataires' => $signataires, 'html' => $html, 'sha256' => $sha]);
}

case 'sign_pact': {
    $slug = strtolower(preg_replace('/[^a-z0-9-]/', '', (string)($input['slug'] ?? '')));
    $sha_confirm = (string)($input['sha256'] ?? '');
    $pdo = pdo_meta();
    $st = $pdo->prepare("SELECT * FROM workspaces WHERE slug = ? AND type = 'wsc' LIMIT 1");
    $st->execute([$slug]);
    $ws = $st->fetch();
    if (!$ws) jout(['ok' => false, 'error' => 'WSc introuvable'], 404);
    // Recompute pact + verify SHA
    $sig = $pdo->prepare(
        "SELECT u.id, u.email, u.display_name, u.country_code, u.pro_card_number, p.signed_at, p.sha256
         FROM pact_signatures p JOIN users u ON u.id = p.user_id
         WHERE p.wsc_id = ? ORDER BY u.id"
    );
    $sig->execute([$ws['id']]);
    $signataires = $sig->fetchAll();
    $html = generate_pact_html($ws, $signataires);
    $sha_computed = hash('sha256', $html);
    if ($sha_computed !== $sha_confirm) jout(['ok' => false, 'error' => 'SHA mismatch (pacte modifie)', 'expected' => $sha_computed], 400);

    $up = $pdo->prepare(
        "UPDATE pact_signatures SET signed_at = NOW(), ip_address = ?, sha256 = ?
         WHERE wsc_id = ? AND user_id = ?"
    );
    $up->execute([$_SERVER['REMOTE_ADDR'] ?? null, $sha_computed, $ws['id'], $user['id']]);
    audit((int)$user['id'], (int)$ws['id'], 'pact_sign', 'pact_signature', null, ['sha256' => $sha_computed]);

    // Si tous signataires ont signe -> notif activation
    $pending = $pdo->prepare("SELECT COUNT(*) FROM pact_signatures WHERE wsc_id = ? AND signed_at IS NULL");
    $pending->execute([$ws['id']]);
    $remaining = (int)$pending->fetchColumn();
    if ($remaining === 0) {
        // Notify all members
        $members = $pdo->prepare("SELECT user_id FROM workspace_members WHERE workspace_id = ? AND left_at IS NULL");
        $members->execute([$ws['id']]);
        foreach ($members->fetchAll() as $m) {
            notify_user((int)$m['user_id'], 'wsc_activated',
                'WSc ' . $ws['display_name'] . ' active',
                'Tous les membres ont signe le pacte. Le partenariat est actif.',
                ['wsc_slug' => $slug]
            );
        }
    }
    jout(['ok' => true, 'remaining_signatures' => $remaining]);
}

case 'request_rupture': {
    $slug = strtolower(preg_replace('/[^a-z0-9-]/', '', (string)($input['slug'] ?? '')));
    $pdo = pdo_meta();
    $st = $pdo->prepare("SELECT * FROM workspaces WHERE slug = ? AND type = 'wsc' AND archived_at IS NULL LIMIT 1");
    $st->execute([$slug]);
    $ws = $st->fetch();
    if (!$ws) jout(['ok' => false, 'error' => 'WSc introuvable'], 404);
    $m = $pdo->prepare("SELECT 1 FROM workspace_members WHERE workspace_id = ? AND user_id = ? AND left_at IS NULL");
    $m->execute([$ws['id'], $user['id']]);
    if (!$m->fetch()) jout(['ok' => false, 'error' => 'pas membre'], 403);
    $existing = $pdo->prepare(
        "SELECT id FROM rupture_requests WHERE wsc_id = ? AND requester_user_id = ?
         AND cancelled_at IS NULL AND executed_at IS NULL LIMIT 1"
    );
    $existing->execute([$ws['id'], $user['id']]);
    if ($existing->fetch()) jout(['ok' => false, 'error' => 'demande deja en cours'], 409);
    // Snapshot DB
    $snapDir = '/var/lib/atelier/ocre-backups/ruptures';
    @mkdir($snapDir, 0700, true);
    $snapPath = $snapDir . '/' . $slug . '_' . $user['id'] . '_' . date('Ymd_His') . '.sql.gz';
    $cmd = sprintf("mysqldump -u %s -p%s --single-transaction --quick %s 2>&1 | gzip > %s",
        escapeshellarg(DB_USER), escapeshellarg(DB_PASS),
        escapeshellarg('ocre_wsc_' . $slug), escapeshellarg($snapPath));
    @exec($cmd);
    $pdo->prepare(
        "INSERT INTO rupture_requests (wsc_id, requester_user_id, scheduled_for, snapshot_path)
         VALUES (?, ?, DATE_ADD(NOW(), INTERVAL 48 HOUR), ?)"
    )->execute([$ws['id'], $user['id'], $snapPath]);
    $rid = (int)$pdo->lastInsertId();

    // Notifs tous les membres
    $members = $pdo->prepare("SELECT user_id FROM workspace_members WHERE workspace_id = ? AND left_at IS NULL");
    $members->execute([$ws['id']]);
    $title = 'Preavis de rupture WSc ' . $ws['display_name'];
    $body = ($user['display_name'] ?? $user['email']) . ' a demande a quitter le WSc. Rupture effective dans 48h. Pendant ce delai, ses dossiers passent en lecture seule pour tous, et elle voit aussi les votres en lecture seule. Annulation possible pendant 48h.';
    foreach ($members->fetchAll() as $mm) {
        notify_user((int)$mm['user_id'], 'rupture_requested', $title, $body, ['wsc_slug' => $slug, 'rupture_id' => $rid]);
    }
    audit((int)$user['id'], (int)$ws['id'], 'rupture_request', 'rupture', $rid, ['snapshot' => $snapPath]);

    jout(['ok' => true, 'rupture_id' => $rid, 'scheduled_for' => date('c', time() + 48 * 3600), 'snapshot_path' => $snapPath]);
}

case 'cancel_rupture': {
    $rid = (int)($input['rupture_id'] ?? 0);
    if (!$rid) jout(['ok' => false, 'error' => 'rupture_id requis'], 400);
    $pdo = pdo_meta();
    $st = $pdo->prepare(
        "SELECT * FROM rupture_requests WHERE id = ? AND requester_user_id = ?
         AND cancelled_at IS NULL AND executed_at IS NULL LIMIT 1"
    );
    $st->execute([$rid, $user['id']]);
    $r = $st->fetch();
    if (!$r) jout(['ok' => false, 'error' => 'introuvable ou deja executee/annulee'], 404);
    if (strtotime($r['scheduled_for']) < time()) jout(['ok' => false, 'error' => 'delai 48h depasse'], 410);
    $pdo->prepare("UPDATE rupture_requests SET cancelled_at = NOW() WHERE id = ?")->execute([$rid]);
    // Notifs
    $members = $pdo->prepare("SELECT user_id FROM workspace_members WHERE workspace_id = ? AND left_at IS NULL");
    $members->execute([$r['wsc_id']]);
    foreach ($members->fetchAll() as $mm) {
        notify_user((int)$mm['user_id'], 'rupture_cancelled',
            'Rupture annulee',
            ($user['display_name'] ?? $user['email']) . ' a annule sa demande, le partenariat continue normalement.',
            ['rupture_id' => $rid]
        );
    }
    audit((int)$user['id'], (int)$r['wsc_id'], 'rupture_cancel', 'rupture', $rid);
    jout(['ok' => true]);
}

default:
    jout(['ok' => false, 'error' => 'action inconnue'], 400);
}
