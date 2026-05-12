<?php
// M/2026/05/12/53 — Sécurité Réglages : liste sessions actives + revoke par session.
require_once __DIR__ . '/db.php';
setCorsHeaders();

$user = requireAuth();
$uid = (int) $user['id'];
$action = $_GET['action'] ?? ($_POST['action'] ?? 'list');

$meta = new PDO('mysql:host=' . DB_HOST . ';dbname=ocre_meta;charset=utf8mb4', DB_USER, DB_PASS, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
]);

// Identifier la session courante via le token transmis (auth_sessions.refresh_token ou jti).
$currentToken = $_SERVER['HTTP_X_SESSION_TOKEN'] ?? '';

function parseUA(string $ua): string {
    if (!$ua) return 'Appareil inconnu';
    if (preg_match('/iPhone/i', $ua)) return 'iPhone Safari';
    if (preg_match('/iPad/i', $ua)) return 'iPad Safari';
    if (preg_match('/Android/i', $ua)) return 'Android';
    if (preg_match('/Macintosh/i', $ua)) return 'Mac';
    if (preg_match('/Windows/i', $ua)) return 'Windows';
    if (preg_match('/Linux/i', $ua)) return 'Linux';
    return 'Navigateur web';
}

switch ($action) {

case 'list': {
    $st = $meta->prepare("SELECT id, jti, refresh_token, user_agent, ip, created_at, last_activity_at, expires_at
        FROM auth_sessions WHERE user_id = ? AND revoked_at IS NULL AND (expires_at IS NULL OR expires_at > NOW())
        ORDER BY COALESCE(last_activity_at, created_at) DESC LIMIT 50");
    $st->execute([$uid]);
    $rows = $st->fetchAll() ?: [];
    $sessions = [];
    foreach ($rows as $r) {
        $isCurrent = $currentToken !== '' && ($r['refresh_token'] === $currentToken || $r['jti'] === $currentToken);
        $sessions[] = [
            'id' => (int) $r['id'],
            'device' => parseUA($r['user_agent'] ?? ''),
            'ip' => $r['ip'] ?? '',
            'created_at' => $r['created_at'],
            'last_activity_at' => $r['last_activity_at'],
            'expires_at' => $r['expires_at'],
            'is_current' => $isCurrent,
        ];
    }
    jsonOk(['sessions' => $sessions]);
}

case 'revoke': {
    $input = getInput();
    $sid = (int) ($input['id'] ?? 0);
    if (!$sid) jsonError('id requis');
    $meta->prepare("UPDATE auth_sessions SET revoked_at = NOW() WHERE id = ? AND user_id = ?")->execute([$sid, $uid]);
    jsonOk(['id' => $sid, 'revoked' => true]);
}

case 'revoke_all_others': {
    // Revoke toutes sessions SAUF la courante (identifiee par token transmis).
    if ($currentToken === '') jsonError('Session courante introuvable', 400);
    $st = $meta->prepare("UPDATE auth_sessions SET revoked_at = NOW()
        WHERE user_id = ? AND revoked_at IS NULL AND refresh_token != ? AND jti != ?");
    $st->execute([$uid, $currentToken, $currentToken]);
    jsonOk(['revoked_count' => $st->rowCount()]);
}

default: jsonError('Action inconnue', 404);
}
