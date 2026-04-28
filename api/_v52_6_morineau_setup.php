<?php
// V52.6 — one-shot IP-whitelist : purge ophelie@ocre.immo + crée morineau.ophelie@gmail.com.
require_once __DIR__ . '/db.php';
$allowed = ['46.225.215.148','127.0.0.1','::1'];
$remote = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? ($_SERVER['REMOTE_ADDR'] ?? '');
$ip = trim(explode(',', $remote)[0]);
if (!in_array($ip, $allowed, true)) { http_response_code(403); exit(json_encode(['ok'=>false,'error'=>'forbidden','seen_ip'=>$ip])); }
header('Content-Type: application/json; charset=utf-8');
$out = ['steps' => []];
try {
    $pdo = db();

    // 1. Liste roles existants
    $roles = $pdo->query("SELECT DISTINCT role FROM users WHERE role IS NOT NULL ORDER BY role")->fetchAll(PDO::FETCH_COLUMN);
    $out['existing_roles'] = $roles;

    // 2. Choix rôle : editor > user > agent (skip admin/visiteur)
    $preferred = ['editor', 'user', 'agent'];
    $roleChoisi = null;
    foreach ($preferred as $r) {
        if (in_array($r, $roles, true)) { $roleChoisi = $r; break; }
    }
    if (!$roleChoisi) $roleChoisi = 'agent'; // fallback même si pas existant
    $out['role_choisi'] = $roleChoisi;

    // 3. Purge ophelie@ocre.immo
    $stmt = $pdo->prepare("SELECT id FROM users WHERE email = 'ophelie@ocre.immo' LIMIT 1");
    $stmt->execute();
    $oldOphelie = $stmt->fetch();
    if ($oldOphelie) {
        $oldId = (int)$oldOphelie['id'];
        $delSess = $pdo->prepare("DELETE FROM sessions WHERE user_id = ?");
        $delSess->execute([$oldId]);
        $out['steps'][] = "deleted sessions count=" . $delSess->rowCount() . " for user_id=$oldId";
        // garder ses dossiers démo intacts pour pouvoir les ré-attribuer
        $delUser = $pdo->prepare("DELETE FROM users WHERE id = ?");
        $delUser->execute([$oldId]);
        $out['steps'][] = "deleted user ophelie@ocre.immo id=$oldId";
        $oldOphelieId = $oldId;
    } else {
        $out['steps'][] = "no user ophelie@ocre.immo";
        $oldOphelieId = null;
    }

    // 4. Crée morineau.ophelie@gmail.com (idempotent)
    $email = 'morineau.ophelie@gmail.com';
    $stmt = $pdo->prepare("SELECT id FROM users WHERE LOWER(email) = LOWER(?) LIMIT 1");
    $stmt->execute([$email]);
    $existing = $stmt->fetch();
    if ($existing) {
        $newId = (int)$existing['id'];
        $upd = $pdo->prepare("UPDATE users SET prenom = ?, nom = ?, role = ?, active = 1, password_hash = 'PLACEHOLDER' WHERE id = ?");
        $upd->execute(['Ophélie', 'Morineau', $roleChoisi, $newId]);
        $out['steps'][] = "user already existed, updated id=$newId";
    } else {
        $ins = $pdo->prepare("INSERT INTO users (email, prenom, nom, role, active, password_hash, created_at) VALUES (?, 'Ophélie', 'Morineau', ?, 1, 'PLACEHOLDER', NOW())");
        $ins->execute([$email, $roleChoisi]);
        $newId = (int)$pdo->lastInsertId();
        $out['steps'][] = "user created id=$newId email=$email role=$roleChoisi";
    }
    $out['new_user_id'] = $newId;

    // 5. Réattribue les 6 dossiers demo (ids 76-81 jadis user_id=2 ophelie@ocre.immo)
    if ($oldOphelieId) {
        $upd = $pdo->prepare("UPDATE clients SET user_id = ? WHERE user_id = ? AND COALESCE(is_demo,0)=1");
        $upd->execute([$newId, $oldOphelieId]);
        $out['steps'][] = "dossiers demo reattribues count=" . $upd->rowCount();
    }

    // Mode "auth email" check (pour info)
    try {
        $st = $pdo->prepare("SELECT setting_value FROM app_settings WHERE setting_key = 'mode_auth_email' LIMIT 1");
        $st->execute();
        $modeAuthEmail = $st->fetchColumn();
        $out['mode_auth_email'] = $modeAuthEmail;
    } catch (Throwable $e) {}

    $out['ok'] = true;
} catch (Throwable $e) {
    $out['ok'] = false;
    $out['error'] = $e->getMessage();
}
echo json_encode($out, JSON_UNESCAPED_UNICODE);
