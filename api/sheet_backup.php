<?php
// V18.16 — Sauvegardes Sheet.
// Double mécanisme : (1) cron quotidien VPS 02:00 UTC crée copies Drive rétention 30.
// (2) Bouton admin "💾 Sauvegarder et partager" déclenche backup + export XLSX + share iOS.
//
// VPS-only (IP 46.225.215.148) :
//   GET  ?action=user_info&user_id=N         → {sheet_id, sync_email, dossiers_count}
//   GET  ?action=all_users_with_sheet        → {user_ids:[...]}
//   POST ?action=log  body {user_id, backup_file_id, backup_name, dossiers_count, web_view_link}
//   POST ?action=mark_deleted  body {user_id, backup_file_id}
//
// User auth (admin only pour V1) :
//   GET  ?action=list                        → liste des backups du user courant
//   GET  ?action=share                       → XLSX binary (Content-Disposition attachment)
//                                              déclenche côté VPS un nouveau Drive copy + export.

require_once __DIR__ . '/db.php';
setCorsHeaders();

// VPS_BRIDGE_BASE : endpoint FastAPI atelier (v18.16 /sheet-backup/*).
const VPS_BRIDGE_BASE = 'https://46-225-215-148.sslip.io/sheet-backup';

function isVpsIp(): bool {
    $ip = $_SERVER['REMOTE_ADDR'] ?? '';
    return in_array($ip, ['46.225.215.148'], true);
}

function ensureBackupSchema() {
    $pdo = db();
    $pdo->exec("CREATE TABLE IF NOT EXISTS sheet_backup_log (
        id INT AUTO_INCREMENT PRIMARY KEY,
        user_id INT NOT NULL,
        backup_file_id VARCHAR(100) NOT NULL,
        backup_name VARCHAR(255) NOT NULL,
        dossiers_count INT NOT NULL DEFAULT 0,
        size_bytes INT NOT NULL DEFAULT 0,
        web_view_link TEXT NULL,
        shared TINYINT NOT NULL DEFAULT 0,
        deleted_at DATETIME NULL,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_user_created (user_id, created_at),
        UNIQUE KEY uniq_file (backup_file_id)
    ) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
    // Migration douce — ajoute size_bytes si table pré-existante v18.16 sans colonne.
    try { $pdo->exec("ALTER TABLE sheet_backup_log ADD COLUMN size_bytes INT NOT NULL DEFAULT 0 AFTER dossiers_count"); }
    catch (Exception $e) { /* colonne déjà présente */ }
}

function getVpsBridgeToken(): string {
    $token = trim((string) getSetting('atelier_vps_token', ''));
    if ($token === '' && defined('ATELIER_VPS_TOKEN')) $token = ATELIER_VPS_TOKEN;
    return $token;
}

function callVpsBridge(string $path, array $params, bool $binary = false) {
    $token = getVpsBridgeToken();
    if ($token === '') {
        jsonError('Bridge VPS non configuré (settings.atelier_vps_token manquant)', 500);
    }
    $params['token'] = $token;
    $url = VPS_BRIDGE_BASE . $path . '?' . http_build_query($params);
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 90,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_HEADER => $binary,
    ]);
    $resp = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $headerSize = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    $err = curl_error($ch);
    curl_close($ch);
    if ($resp === false) {
        jsonError('VPS bridge injoignable : ' . $err, 502);
    }
    if ($binary) {
        $respHeaders = substr($resp, 0, $headerSize);
        $body = substr($resp, $headerSize);
        return [$httpCode, $respHeaders, $body];
    }
    return [$httpCode, null, $resp];
}

ensureBackupSchema();

$action = $_GET['action'] ?? ($_POST['action'] ?? '');
$input  = getInput();

switch ($action) {

    // ─────── VPS-only ───────────────────────────────────────────
    case 'user_info': {
        if (!isVpsIp()) jsonError('VPS only', 403);
        $uid = (int)($_GET['user_id'] ?? 0);
        if (!$uid) jsonError('user_id manquant', 400);
        $stmt = db()->prepare("SELECT id, sync_email, sheet_id FROM users WHERE id = ? LIMIT 1");
        $stmt->execute([$uid]);
        $u = $stmt->fetch();
        if (!$u) jsonError('user inconnu', 404);
        $n = 0;
        try {
            $c = db()->prepare("SELECT COUNT(*) n FROM clients WHERE user_id = ? AND (is_staged IS NULL OR is_staged = 0)");
            $c->execute([$uid]);
            $n = (int) ($c->fetch()['n'] ?? 0);
        } catch (Exception $e) {
            // is_staged pas encore en place (v18.17) — fallback count total.
            $c = db()->prepare("SELECT COUNT(*) n FROM clients WHERE user_id = ?");
            $c->execute([$uid]);
            $n = (int) ($c->fetch()['n'] ?? 0);
        }
        jsonOk([
            'sheet_id' => $u['sheet_id'] ?: null,
            'sync_email' => $u['sync_email'] ?: null,
            'dossiers_count' => $n,
        ]);
    }

    case 'all_users_with_sheet': {
        if (!isVpsIp()) jsonError('VPS only', 403);
        $r = db()->query("SELECT id FROM users WHERE sheet_id IS NOT NULL AND sheet_id <> '' AND sync_email IS NOT NULL AND sync_email <> '' AND active = 1");
        jsonOk(['user_ids' => array_map('intval', array_column($r->fetchAll(), 'id'))]);
    }

    case 'log': {
        if (!isVpsIp()) jsonError('VPS only', 403);
        $uid = (int)($input['user_id'] ?? 0);
        $fid = trim((string)($input['backup_file_id'] ?? ''));
        if (!$uid || $fid === '') jsonError('user_id et backup_file_id requis', 400);
        $stmt = db()->prepare(
            "INSERT INTO sheet_backup_log (user_id, backup_file_id, backup_name, dossiers_count, size_bytes, web_view_link)
             VALUES (?, ?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE backup_name = VALUES(backup_name), size_bytes = VALUES(size_bytes), web_view_link = VALUES(web_view_link)"
        );
        $stmt->execute([
            $uid,
            $fid,
            (string)($input['backup_name'] ?? ''),
            (int)($input['dossiers_count'] ?? 0),
            (int)($input['size_bytes'] ?? 0),
            (string)($input['web_view_link'] ?? ''),
        ]);
        jsonOk(['logged' => true]);
    }

    case 'mark_deleted': {
        if (!isVpsIp()) jsonError('VPS only', 403);
        $fid = trim((string)($input['backup_file_id'] ?? ''));
        if ($fid === '') jsonError('backup_file_id requis', 400);
        $stmt = db()->prepare("UPDATE sheet_backup_log SET deleted_at = NOW() WHERE backup_file_id = ?");
        $stmt->execute([$fid]);
        jsonOk(['marked' => $stmt->rowCount()]);
    }

    // ─────── User auth ──────────────────────────────────────────
    case 'list': {
        $u = requireAuth();
        $stmt = db()->prepare(
            "SELECT id, backup_file_id, backup_name, dossiers_count, size_bytes, shared, created_at
             FROM sheet_backup_log
             WHERE user_id = ? AND deleted_at IS NULL
             ORDER BY created_at DESC
             LIMIT 50"
        );
        $stmt->execute([$u['id']]);
        jsonOk(['backups' => $stmt->fetchAll()]);
    }

    case 'download': {
        // Relit une archive existante depuis le VPS via bridge.
        $u = requireAuth();
        $uid = (int) $u['id'];
        $bid = trim((string)($_GET['backup_id'] ?? ''));
        if ($bid === '') jsonError('backup_id manquant', 400);
        // Vérif appartenance via DB.
        $stmt = db()->prepare("SELECT backup_name FROM sheet_backup_log WHERE user_id = ? AND backup_file_id = ? AND deleted_at IS NULL LIMIT 1");
        $stmt->execute([$uid, $bid]);
        $row = $stmt->fetch();
        if (!$row) jsonError('archive inconnue', 404);

        list($code, $hdrs, $body) = callVpsBridge('/download', ['user_id' => $uid, 'backup_id' => $bid], /*binary=*/true);
        if ($code !== 200) {
            $decoded = json_decode($body, true);
            if (is_array($decoded)) jsonError($decoded['error'] ?? 'VPS error', 500, ['detail' => $decoded['detail'] ?? '']);
            jsonError('VPS error code=' . $code, 500);
        }
        logAction($uid, 'sheet_backup_download', $bid);
        header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        header('Content-Disposition: attachment; filename="' . $row['backup_name'] . '.xlsx"');
        header('Content-Length: ' . strlen($body));
        header('Cache-Control: no-store');
        echo $body;
        exit;
    }

    case 'share': {
        // Amendement F2 : déclenche un Drive copy + export XLSX et proxy le binaire.
        // Côté frontend : navigator.share({files:[file]}) pour iOS share sheet natif.
        $u = requireAuth();
        $uid = (int) $u['id'];
        list($code, $hdrs, $body) = callVpsBridge('/share', ['user_id' => $uid], /*binary=*/true);
        if ($code !== 200) {
            // On essaie de parser un JSON d'erreur.
            $decoded = json_decode($body, true);
            if (is_array($decoded)) jsonError($decoded['error'] ?? 'VPS error', 500, ['detail' => $decoded['detail'] ?? '']);
            jsonError('VPS error code=' . $code, 500);
        }
        // Extract filename depuis Content-Disposition VPS.
        $filename = 'ocre-sauvegarde.xlsx';
        if (is_string($hdrs) && preg_match('/filename="([^"]+)"/i', $hdrs, $m)) {
            $filename = $m[1];
        }
        // Log côté DB (shared=1).
        try {
            if (preg_match('/^X-Backup-Id:\s*(\S+)/mi', (string)$hdrs, $mm)) {
                $bid = trim($mm[1]);
                $stmt = db()->prepare("UPDATE sheet_backup_log SET shared = 1 WHERE backup_file_id = ?");
                $stmt->execute([$bid]);
            }
        } catch (Exception $e) { /* non bloquant */ }
        logAction($uid, 'sheet_backup_share', $filename);

        header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Content-Length: ' . strlen($body));
        header('Cache-Control: no-store');
        echo $body;
        exit;
    }

    default:
        jsonError('action inconnue : ' . $action, 400);
}
