<?php
// M/2026/05/08/30 — Super-admin endpoint : gestion des inscriptions en attente d'activation.
// Architecture résiliente — Philippe ne laisse jamais un agent bloqué silencieusement.
//
// GET ?action=list                  → liste users status=pending_activation avec age + attempts
// POST {action:resend, user_id}      → regen token + send_mail (OVH SMTP)
// POST {action:activate_manual, user_id, telephone?} → genere mdp temporaire + status=active
// POST {action:add_note, user_id, note} → append a superadmin_notes
// POST {action:delete, user_id}      → DELETE user (cas tests/doublons)
// POST {action:history, user_id}     → details: timestamps, providers, statuts, log

require_once __DIR__ . '/lib/router.php';
require_once __DIR__ . '/lib/email_sender.php';
header('Content-Type: application/json; charset=utf-8');

function jout(array $d, int $code = 200) {
    http_response_code($code);
    echo json_encode($d, JSON_UNESCAPED_UNICODE);
    exit;
}

$user = current_user_or_401();
if (($user['role'] ?? '') !== 'super_admin') jout(['ok' => false, 'error' => 'super_admin only'], 403);

$meta = pdo_meta();
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$input = $method === 'POST' ? (function() {
    $raw = file_get_contents('php://input');
    $j = json_decode($raw, true);
    return is_array($j) ? $j : [];
})() : [];
$action = $method === 'GET' ? ($_GET['action'] ?? 'list') : ($input['action'] ?? '');

// LIST -------------------------------------------------------------
if ($action === 'list') {
    $stmt = $meta->prepare(
        "SELECT id, email, prenom, nom, slug, societe, telephone, ville,
                created_at, last_activation_attempt_at, activation_attempts_count,
                last_activation_provider, last_activation_status, superadmin_notes
           FROM users
          WHERE status = 'pending_activation' AND archived_at IS NULL
          ORDER BY created_at DESC LIMIT 500"
    );
    $stmt->execute();
    $rows = $stmt->fetchAll();
    $now = time();
    $list = [];
    foreach ($rows as $r) {
        $createdTs = strtotime((string)$r['created_at']) ?: $now;
        $ageHours = max(0, (int)(($now - $createdTs) / 3600));
        $attempts = (int)$r['activation_attempts_count'];
        if ($attempts > 3 || $ageHours >= 24) $color = 'red';
        elseif ($ageHours >= 1) $color = 'orange';
        else $color = 'green';
        $list[] = [
            'id' => (int)$r['id'],
            'email' => (string)$r['email'],
            'prenom' => (string)$r['prenom'],
            'nom' => (string)$r['nom'],
            'slug' => (string)$r['slug'],
            'agence_nom' => (string)$r['societe'],
            'telephone' => (string)$r['telephone'],
            'ville' => (string)$r['ville'],
            'created_at' => (string)$r['created_at'],
            'last_attempt_at' => (string)($r['last_activation_attempt_at'] ?? ''),
            'attempts' => $attempts,
            'age_hours' => $ageHours,
            'color' => $color,
            'last_provider' => (string)($r['last_activation_provider'] ?? ''),
            'last_status' => (string)($r['last_activation_status'] ?? ''),
            'notes' => (string)($r['superadmin_notes'] ?? ''),
        ];
    }
    jout(['ok' => true, 'count' => count($list), 'pending' => $list]);
}

if ($method !== 'POST') jout(['ok' => false, 'error' => 'method not allowed'], 405);
$userId = (int)($input['user_id'] ?? 0);
if ($userId <= 0) jout(['ok' => false, 'error' => 'user_id required'], 400);

$st = $meta->prepare("SELECT id, email, prenom, nom, slug, status, activation_attempts_count FROM users WHERE id = ? LIMIT 1");
$st->execute([$userId]);
$target = $st->fetch();
if (!$target) jout(['ok' => false, 'error' => 'user not found'], 404);

// RESEND -----------------------------------------------------------
if ($action === 'resend') {
    if ($target['status'] !== 'pending_activation') jout(['ok' => false, 'error' => 'user not pending'], 409);
    $token = bin2hex(random_bytes(32));
    $upd = $meta->prepare(
        "UPDATE users SET activation_token = ?, activation_token_expires_at = DATE_ADD(NOW(), INTERVAL 48 HOUR),
                          activation_attempts_count = activation_attempts_count + 1,
                          last_activation_attempt_at = NOW()
          WHERE id = ?"
    );
    $upd->execute([$token, $userId]);
    // Mail dedouble (court) : evite de require agents_register.php qui execute du code top-level.
    $url = 'https://app.ocre.immo/api/agents_activate.php?token=' . $token;
    $safePrenom = htmlspecialchars((string)$target['prenom'], ENT_QUOTES, 'UTF-8');
    $html = '<html><body style="font-family:-apple-system,BlinkMacSystemFont,sans-serif;color:#3a2e22;background:#FAF6EC;margin:0;padding:0;">'
        . '<div style="max-width:560px;margin:0 auto;padding:32px 24px;background:#fff;border-radius:14px;box-shadow:0 2px 10px rgba(60,40,20,0.08);">'
        . '<h1 style="font-family:\'Cormorant Garamond\',Georgia,serif;font-style:italic;color:#8B5E3C;font-weight:500;margin:0 0 12px;font-size:28px;">Bienvenue sur Oi Agent</h1>'
        . '<p style="font-size:15px;line-height:1.5;">Bonjour <b>' . $safePrenom . '</b>,</p>'
        . '<p style="font-size:15px;line-height:1.5;">Voici votre nouveau lien d\'activation (lien valide 48 heures) :</p>'
        . '<table border="0" cellpadding="0" cellspacing="0" role="presentation" align="center" style="margin:28px auto;">'
        . '<tr><td bgcolor="#10B981" style="border-radius:10px;background-color:#10B981;mso-padding-alt:14px 32px;">'
        . '<a href="' . $url . '" target="_blank" style="display:inline-block;padding:14px 32px;font-family:\'DM Sans\',-apple-system,BlinkMacSystemFont,sans-serif;font-size:16px;font-weight:700;color:#ffffff;text-decoration:none;border-radius:10px;border:1px solid #10B981;line-height:1.2;">Activer mon compte</a>'
        . '</td></tr></table>'
        . '<p style="font-size:11px;color:#999;margin-top:32px;border-top:1px solid #eee;padding-top:16px;">Oi Agent — un produit Ocre · contact@ocre.immo</p>'
        . '</div></body></html>';
    $r = function_exists('send_mail')
        ? send_mail((string)$target['email'], 'Bienvenue sur Oi Agent — Nouveau lien d\'activation', $html)
        : ['ok' => false, 'error' => 'send_mail unavailable', 'message_id' => null, 'provider' => null];
    $providerUsed = $r['provider'] ?? null;
    $statusUsed = $r['ok'] ? 'SENT_RESEND_SA' : ('FAIL_' . substr((string)$r['error'], 0, 40));
    @($meta->prepare("UPDATE users SET last_activation_provider = ?, last_activation_status = ? WHERE id = ?")
        ->execute([$providerUsed, $statusUsed, $userId]));
    @file_put_contents('/var/log/ocre-activation-attempts.log', "[" . date('c') . "] SUPERADMIN_RESEND user_id=$userId email=" . $target['email'] . " result=" . json_encode($r) . "\n", FILE_APPEND);
    jout(['ok' => $r['ok'], 'provider' => $providerUsed, 'status' => $statusUsed, 'error' => $r['error'] ?? null, 'message_id' => $r['message_id'] ?? null]);
}

// ACTIVATE_MANUAL --------------------------------------------------
if ($action === 'activate_manual') {
    if ($target['status'] !== 'pending_activation') jout(['ok' => false, 'error' => 'user not pending'], 409);
    // Genere un mdp temporaire 12 chars random (plus robuste qu'un 8 chars).
    $tempPwd = substr(str_shuffle('ABCDEFGHJKMNPQRSTUVWXYZabcdefghjkmnpqrstuvwxyz23456789'), 0, 12);
    $hash = password_hash($tempPwd, PASSWORD_BCRYPT, ['cost' => 12]);
    $upd = $meta->prepare("UPDATE users SET password_hash = ?, status = 'active', activation_token = NULL, activation_token_expires_at = NULL, last_activation_status = 'ACTIVATED_MANUAL_BY_SUPERADMIN' WHERE id = ?");
    $upd->execute([$hash, $userId]);
    @file_put_contents('/var/log/ocre-activation-attempts.log', "[" . date('c') . "] SUPERADMIN_ACTIVATE_MANUAL user_id=$userId email=" . $target['email'] . " by_superadmin=" . $user['id'] . "\n", FILE_APPEND);
    jout(['ok' => true, 'temp_password' => $tempPwd, 'message' => "Mot de passe temporaire genere. A transmettre verbalement ou via SMS a l'agent (telephone: " . ($input['telephone'] ?? $target['email']) . ")."]);
}

// ADD_NOTE ---------------------------------------------------------
if ($action === 'add_note') {
    $note = trim((string)($input['note'] ?? ''));
    if ($note === '') jout(['ok' => false, 'error' => 'note required'], 400);
    $stamped = '[' . date('c') . ' by sa#' . $user['id'] . '] ' . $note;
    $upd = $meta->prepare("UPDATE users SET superadmin_notes = CONCAT(COALESCE(superadmin_notes,''), ?, '\n') WHERE id = ?");
    $upd->execute([$stamped, $userId]);
    jout(['ok' => true, 'appended' => $stamped]);
}

// DELETE -----------------------------------------------------------
if ($action === 'delete') {
    if ($target['status'] === 'active' && empty($input['confirm_active_delete'])) {
        jout(['ok' => false, 'error' => 'cannot delete active user without confirm_active_delete=true'], 409);
    }
    $del = $meta->prepare("DELETE FROM users WHERE id = ?");
    $del->execute([$userId]);
    @file_put_contents('/var/log/ocre-activation-attempts.log', "[" . date('c') . "] SUPERADMIN_DELETE user_id=$userId email=" . $target['email'] . " by_superadmin=" . $user['id'] . "\n", FILE_APPEND);
    jout(['ok' => true, 'deleted' => true]);
}

// HISTORY ----------------------------------------------------------
if ($action === 'history') {
    $log = '/var/log/ocre-activation-attempts.log';
    $entries = [];
    if (file_exists($log) && is_readable($log)) {
        $f = fopen($log, 'r');
        if ($f) {
            while (($line = fgets($f)) !== false) {
                if (strpos($line, 'user_id=' . $userId) !== false || strpos($line, '"email":"' . $target['email'] . '"') !== false || strpos($line, $target['email']) !== false) {
                    $entries[] = trim($line);
                }
            }
            fclose($f);
        }
    }
    // Limiter aux 50 dernières.
    $entries = array_slice($entries, -50);
    jout(['ok' => true, 'user_id' => $userId, 'email' => $target['email'], 'log_entries' => $entries]);
}

jout(['ok' => false, 'error' => 'unknown action: ' . $action], 400);
