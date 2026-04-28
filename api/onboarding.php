<?php
// M/2026/04/28/64 — Onboarding state : detection 1ere connexion + complete + reset.
require_once __DIR__ . '/db.php';
setCorsHeaders();

$user = requireAuth();
$action = $_GET['action'] ?? 'state';
$uid = (int) $user['id'];

function ensureOnboardingCols(): PDO {
    static $meta = null;
    if ($meta) return $meta;
    $meta = new PDO('mysql:host=' . DB_HOST . ';dbname=ocre_meta;charset=utf8mb4', DB_USER, DB_PASS, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
    try {
        $cols = $meta->query("SHOW COLUMNS FROM users")->fetchAll(PDO::FETCH_COLUMN);
        if (!in_array('first_login_at', $cols, true)) $meta->exec("ALTER TABLE users ADD COLUMN first_login_at DATETIME NULL");
        if (!in_array('onboarding_completed', $cols, true)) $meta->exec("ALTER TABLE users ADD COLUMN onboarding_completed TINYINT(1) NOT NULL DEFAULT 0");
        if (!in_array('onboarding_progress', $cols, true)) $meta->exec("ALTER TABLE users ADD COLUMN onboarding_progress LONGTEXT NULL");
    } catch (Throwable $e) {}
    return $meta;
}
$meta = ensureOnboardingCols();

switch ($action) {

case 'state': {
    $st = $meta->prepare("SELECT first_login_at, onboarding_completed, onboarding_progress, display_name, email FROM users WHERE id = ?");
    $st->execute([$uid]);
    $r = $st->fetch();
    if (!$r) jsonError('user introuvable', 404);
    $progress = $r['onboarding_progress'] ? (json_decode($r['onboarding_progress'], true) ?: []) : [];
    jsonOk([
        'is_first_login' => empty($r['first_login_at']),
        'onboarding_completed' => (int) $r['onboarding_completed'] === 1,
        'progress' => $progress,
        'display_name' => $r['display_name'] ?? '',
    ]);
}

case 'mark_first_login': {
    $meta->prepare("UPDATE users SET first_login_at = COALESCE(first_login_at, NOW()) WHERE id = ?")->execute([$uid]);
    jsonOk(['ok' => true]);
}

case 'complete': {
    $meta->prepare("UPDATE users SET onboarding_completed = 1, first_login_at = COALESCE(first_login_at, NOW()) WHERE id = ?")->execute([$uid]);
    jsonOk(['ok' => true]);
}

case 'reset': {
    $meta->prepare("UPDATE users SET onboarding_completed = 0, onboarding_progress = NULL WHERE id = ?")->execute([$uid]);
    jsonOk(['ok' => true]);
}

case 'update_progress': {
    $input = getInput();
    $progress = $input['progress'] ?? [];
    $meta->prepare("UPDATE users SET onboarding_progress = ? WHERE id = ?")
        ->execute([json_encode($progress, JSON_UNESCAPED_UNICODE), $uid]);
    jsonOk(['ok' => true]);
}

default:
    jsonError('Action inconnue (state | mark_first_login | complete | reset | update_progress)', 400);
}
