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
    // M/2026/05/14/65 — UNIQUE template via ocre_signup_welcome_email_html(). Suppression
    // franche HTML inline. Toute modification UI passe par mailer.php seul.
    require_once __DIR__ . '/lib/mailer.php';
    $url = 'https://app.ocre.immo/api/agents_activate.php?token=' . $token;
    $html = ocre_signup_welcome_email_html(
        (string)$target['prenom'],
        $url,
        'Activer mon compte',
        'Bienvenue sur Ocre Immo',
        'Voici votre nouveau lien d\'activation.<br><span style="font-size:13px;color:#6B5642">Lien valide 48 heures.</span>'
    );
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
