<?php
// M/2026/04/29/33 — OAuth Google Drive (connect / callback / disconnect / status).
// Credentials lues dans /root/.secrets/google_oauth_client_id et google_oauth_client_secret.
// Si fichiers absents : retourne erreur claire (Philippe doit creer projet GCP).
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/lib/drive_token_crypto.php';

$action = $_GET['action'] ?? $_POST['action'] ?? '';
$user = requireAuth();
$uid = (int) $user['id'];
$slug = $_v20_slug ?? '';

const GOOGLE_OAUTH_CLIENT_ID_PATH = '/root/.secrets/google_oauth_client_id';
const GOOGLE_OAUTH_CLIENT_SECRET_PATH = '/root/.secrets/google_oauth_client_secret';
const GOOGLE_OAUTH_SCOPE = 'https://www.googleapis.com/auth/drive.file';
const GOOGLE_OAUTH_REDIRECT = 'https://app.ocre.immo/api/drive_oauth.php?action=callback';

function _drive_credentials(): array {
    $id = trim((string) @file_get_contents(GOOGLE_OAUTH_CLIENT_ID_PATH));
    $secret = trim((string) @file_get_contents(GOOGLE_OAUTH_CLIENT_SECRET_PATH));
    if (!$id || !$secret) {
        jsonError('Configuration OAuth Google Drive manquante. Contactez l administrateur (creation projet Google Cloud Console requise).', 503);
    }
    return [$id, $secret];
}

switch ($action) {

case 'connect': {
    [$cid, $_] = _drive_credentials();
    $state = drive_oauth_state_create($uid);
    $params = http_build_query([
        'client_id' => $cid,
        'redirect_uri' => GOOGLE_OAUTH_REDIRECT,
        'response_type' => 'code',
        'scope' => GOOGLE_OAUTH_SCOPE,
        'access_type' => 'offline',
        'prompt' => 'consent',
        'state' => $state . ':' . $slug,
    ]);
    header('Location: https://accounts.google.com/o/oauth2/v2/auth?' . $params);
    exit;
}

case 'callback': {
    $code = $_GET['code'] ?? '';
    $stateFull = $_GET['state'] ?? '';
    if (!$code || !$stateFull) { http_response_code(400); echo 'OAuth: code or state missing'; exit; }
    $parts = explode(':', $stateFull, 2);
    $stateToken = $parts[0];
    $callbackSlug = $parts[1] ?? '';
    if (!drive_oauth_state_verify($stateToken, $uid)) { http_response_code(403); echo 'OAuth: state invalid or expired'; exit; }
    [$cid, $secret] = _drive_credentials();

    $ch = curl_init('https://oauth2.googleapis.com/token');
    curl_setopt_array($ch, [
        CURLOPT_POST => 1,
        CURLOPT_POSTFIELDS => http_build_query([
            'code' => $code,
            'client_id' => $cid,
            'client_secret' => $secret,
            'redirect_uri' => GOOGLE_OAUTH_REDIRECT,
            'grant_type' => 'authorization_code',
        ]),
        CURLOPT_RETURNTRANSFER => 1,
        CURLOPT_TIMEOUT => 15,
    ]);
    $resp = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    $j = json_decode((string) $resp, true) ?: [];
    if ($httpCode !== 200 || empty($j['access_token']) || empty($j['refresh_token'])) {
        http_response_code(502); echo 'OAuth: token exchange failed'; exit;
    }

    // Userinfo to capture email
    $ch = curl_init('https://www.googleapis.com/oauth2/v3/userinfo');
    curl_setopt_array($ch, [
        CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $j['access_token']],
        CURLOPT_RETURNTRANSFER => 1,
        CURLOPT_TIMEOUT => 10,
    ]);
    $userResp = curl_exec($ch);
    curl_close($ch);
    $userJ = json_decode((string) $userResp, true) ?: [];
    $email = $userJ['email'] ?? null;

    $expiresAt = (new DateTime('+' . max(60, (int) ($j['expires_in'] ?? 3600)) . ' seconds'))->format('Y-m-d H:i:s');
    $st = db()->prepare("INSERT INTO drive_tokens (user_id, workspace_slug, access_token, refresh_token, expires_at, drive_email)
                         VALUES (?, ?, ?, ?, ?, ?)
                         ON DUPLICATE KEY UPDATE access_token=VALUES(access_token), refresh_token=VALUES(refresh_token),
                         expires_at=VALUES(expires_at), drive_email=VALUES(drive_email)");
    $st->execute([
        $uid, $callbackSlug ?: $slug,
        drive_token_encrypt($j['access_token']),
        drive_token_encrypt($j['refresh_token']),
        $expiresAt, $email,
    ]);

    header('Location: /?drive_connected=1');
    exit;
}

case 'disconnect': {
    $st = db()->prepare("SELECT access_token FROM drive_tokens WHERE user_id=? AND workspace_slug=?");
    $st->execute([$uid, $slug]);
    $row = $st->fetch();
    if ($row) {
        try {
            $tok = drive_token_decrypt($row['access_token']);
            $ch = curl_init('https://oauth2.googleapis.com/revoke?token=' . urlencode($tok));
            curl_setopt_array($ch, [CURLOPT_POST => 1, CURLOPT_RETURNTRANSFER => 1, CURLOPT_TIMEOUT => 10]);
            curl_exec($ch); curl_close($ch);
        } catch (Throwable $e) {}
    }
    db()->prepare("DELETE FROM drive_tokens WHERE user_id=? AND workspace_slug=?")->execute([$uid, $slug]);
    jsonOk(['disconnected' => true]);
    break;
}

case 'status': {
    $st = db()->prepare("SELECT drive_email, last_sync_at, last_sync_status, last_sync_error FROM drive_tokens
                         WHERE user_id=? AND workspace_slug=? LIMIT 1");
    $st->execute([$uid, $slug]);
    $row = $st->fetch();
    if (!$row) { jsonOk(['connected' => false]); break; }
    jsonOk([
        'connected' => true,
        'drive_email' => $row['drive_email'],
        'last_sync_at' => $row['last_sync_at'],
        'last_sync_status' => $row['last_sync_status'],
        'last_sync_error' => $row['last_sync_error'],
        'next_sync_at' => 'lundi 04:00 UTC',
    ]);
    break;
}

default:
    jsonError('action invalide (connect|callback|disconnect|status)', 400);
}
