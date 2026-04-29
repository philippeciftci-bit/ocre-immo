<?php
// M/2026/04/29/33 — Sync immediate Drive (POST /api/drive_sync.php?action=now).
// Genere XLSX dossiers + upload Drive (drive.file scope) dans dossier "Ocre immo".
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/lib/drive_token_crypto.php';
require_once __DIR__ . '/lib/mini_xlsx.php';

const DRIVE_FOLDER_NAME = 'Ocre immo';
const DRIVE_KEEP_LAST_N = 12;

function _drive_creds(): array {
    $id = trim((string) @file_get_contents('/root/.secrets/google_oauth_client_id'));
    $secret = trim((string) @file_get_contents('/root/.secrets/google_oauth_client_secret'));
    if (!$id || !$secret) jsonError('Configuration OAuth manquante', 503);
    return [$id, $secret];
}

function drive_refresh_if_needed(int $uid, string $slug): array {
    $st = db()->prepare("SELECT * FROM drive_tokens WHERE user_id=? AND workspace_slug=? LIMIT 1");
    $st->execute([$uid, $slug]);
    $row = $st->fetch();
    if (!$row) jsonError('Drive non connecte', 403);
    $accessTok = drive_token_decrypt($row['access_token']);
    $refreshTok = drive_token_decrypt($row['refresh_token']);
    $exp = strtotime($row['expires_at']);
    if ($exp - time() > 60) return [$accessTok, $refreshTok, $row];
    [$cid, $secret] = _drive_creds();
    $ch = curl_init('https://oauth2.googleapis.com/token');
    curl_setopt_array($ch, [
        CURLOPT_POST => 1,
        CURLOPT_POSTFIELDS => http_build_query([
            'client_id' => $cid, 'client_secret' => $secret,
            'refresh_token' => $refreshTok, 'grant_type' => 'refresh_token',
        ]),
        CURLOPT_RETURNTRANSFER => 1, CURLOPT_TIMEOUT => 15,
    ]);
    $resp = curl_exec($ch); curl_close($ch);
    $j = json_decode((string) $resp, true) ?: [];
    if (empty($j['access_token'])) throw new RuntimeException('refresh failed');
    $newAccess = $j['access_token'];
    $newExp = (new DateTime('+' . max(60, (int) ($j['expires_in'] ?? 3600)) . ' seconds'))->format('Y-m-d H:i:s');
    db()->prepare("UPDATE drive_tokens SET access_token=?, expires_at=? WHERE id=?")
        ->execute([drive_token_encrypt($newAccess), $newExp, $row['id']]);
    return [$newAccess, $refreshTok, $row];
}

function drive_api_get(string $url, string $token): array {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $token],
        CURLOPT_RETURNTRANSFER => 1, CURLOPT_TIMEOUT => 20,
    ]);
    $r = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return [$code, json_decode((string) $r, true) ?: []];
}

function drive_ensure_folder(string $token): string {
    $q = "name='" . str_replace("'", "\\'", DRIVE_FOLDER_NAME) . "' and mimeType='application/vnd.google-apps.folder' and trashed=false";
    [$code, $j] = drive_api_get('https://www.googleapis.com/drive/v3/files?q=' . urlencode($q) . '&spaces=drive&fields=files(id,name)', $token);
    if ($code === 200 && !empty($j['files'])) return $j['files'][0]['id'];
    // Create folder
    $ch = curl_init('https://www.googleapis.com/drive/v3/files');
    curl_setopt_array($ch, [
        CURLOPT_POST => 1,
        CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $token, 'Content-Type: application/json'],
        CURLOPT_POSTFIELDS => json_encode(['name' => DRIVE_FOLDER_NAME, 'mimeType' => 'application/vnd.google-apps.folder']),
        CURLOPT_RETURNTRANSFER => 1, CURLOPT_TIMEOUT => 15,
    ]);
    $r = curl_exec($ch); curl_close($ch);
    $j = json_decode((string) $r, true) ?: [];
    if (empty($j['id'])) throw new RuntimeException('folder create failed');
    return $j['id'];
}

function drive_upload(string $token, string $folderId, string $name, string $bytes, string $mime): string {
    $boundary = 'ocreboundary' . bin2hex(random_bytes(8));
    $meta = json_encode(['name' => $name, 'parents' => [$folderId]]);
    $body = "--$boundary\r\nContent-Type: application/json; charset=UTF-8\r\n\r\n$meta\r\n"
          . "--$boundary\r\nContent-Type: $mime\r\n\r\n$bytes\r\n--$boundary--";
    $ch = curl_init('https://www.googleapis.com/upload/drive/v3/files?uploadType=multipart');
    curl_setopt_array($ch, [
        CURLOPT_POST => 1,
        CURLOPT_HTTPHEADER => [
            'Authorization: Bearer ' . $token,
            'Content-Type: multipart/related; boundary=' . $boundary,
        ],
        CURLOPT_POSTFIELDS => $body,
        CURLOPT_RETURNTRANSFER => 1, CURLOPT_TIMEOUT => 60,
    ]);
    $r = curl_exec($ch); curl_close($ch);
    $j = json_decode((string) $r, true) ?: [];
    if (empty($j['id'])) throw new RuntimeException('upload failed');
    return $j['id'];
}

function drive_prune_old(string $token, string $folderId, int $keep): void {
    $q = "'$folderId' in parents and trashed=false";
    [$code, $j] = drive_api_get('https://www.googleapis.com/drive/v3/files?q=' . urlencode($q) . '&orderBy=createdTime+desc&fields=files(id,name,createdTime)', $token);
    if ($code !== 200 || empty($j['files'])) return;
    $toDelete = array_slice($j['files'], $keep);
    foreach ($toDelete as $f) {
        $ch = curl_init('https://www.googleapis.com/drive/v3/files/' . $f['id']);
        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST => 'DELETE',
            CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $token],
            CURLOPT_RETURNTRANSFER => 1, CURLOPT_TIMEOUT => 10,
        ]);
        curl_exec($ch); curl_close($ch);
    }
}

function drive_sync_for_user(int $uid, string $slug): array {
    [$accessTok, $_, $row] = drive_refresh_if_needed($uid, $slug);
    // Genere XLSX du tableau dossiers (reutilise mini_xlsx).
    $st = db()->prepare("SELECT id, prenom, nom, email, tel, projet, created_at FROM clients WHERE user_id=? AND deleted_at IS NULL ORDER BY id");
    $st->execute([$uid]);
    $rows = [['ID', 'Prénom', 'Nom', 'Email', 'Téléphone', 'Profil', 'Créé']];
    foreach ($st->fetchAll() as $c) {
        $rows[] = [$c['id'], $c['prenom'], $c['nom'], $c['email'], $c['tel'], $c['projet'], $c['created_at']];
    }
    $bytes = mini_xlsx_build([['Dossiers', $rows]]);
    $name = 'ocre-immo_' . $slug . '_' . date('Y-m-d') . '.xlsx';
    $folderId = drive_ensure_folder($accessTok);
    $fileId = drive_upload($accessTok, $folderId, $name, $bytes,
        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    drive_prune_old($accessTok, $folderId, DRIVE_KEEP_LAST_N);
    db()->prepare("UPDATE drive_tokens SET drive_folder_id=?, last_sync_at=NOW(), last_sync_status='success', last_sync_error=NULL, last_sync_file_id=? WHERE id=?")
        ->execute([$folderId, $fileId, $row['id']]);
    return ['file_id' => $fileId, 'name' => $name, 'folder_id' => $folderId];
}

$action = $_GET['action'] ?? '';
$user = requireAuth();
$uid = (int) $user['id'];
$slug = $_v20_slug ?? '';

if ($action === 'now') {
    try {
        $r = drive_sync_for_user($uid, $slug);
        jsonOk(['synced' => true, 'file' => $r]);
    } catch (Throwable $e) {
        $msg = $e->getMessage();
        try {
            db()->prepare("UPDATE drive_tokens SET last_sync_at=NOW(), last_sync_status='error', last_sync_error=? WHERE user_id=? AND workspace_slug=?")
                ->execute([$msg, $uid, $slug]);
        } catch (Throwable $e2) {}
        jsonError('sync failed: ' . $msg, 502);
    }
} else {
    jsonError('action invalide (now)', 400);
}
