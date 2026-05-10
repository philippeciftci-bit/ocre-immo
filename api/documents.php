<?php
// M/2026/04/28/31 — Section IV Phase 1 : bibliothèque documents par dossier.
// Catégories : piece_identite | passeport | justif_domicile | pret_bancaire |
// compromis | acte_vente | mandat | autre. Upload sécurisé (whitelist mime + 10 MB max).
require_once __DIR__ . '/db.php';
setCorsHeaders();

$user = requireAuth();
$action = $_GET['action'] ?? ($_POST['action'] ?? '');
$input = getInput();
$uid = (int) $user['id'];

const MAX_UPLOAD_BYTES = 10 * 1024 * 1024; // 10 MB
const ALLOWED_MIME = ['application/pdf','image/jpeg','image/png','image/heic','image/heif',
    'application/msword','application/vnd.openxmlformats-officedocument.wordprocessingml.document',
    'application/vnd.ms-excel','application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'];

function ensureDocumentsSchema() {
    static $done = false;
    if ($done) return;
    try {
        db()->exec("CREATE TABLE IF NOT EXISTS documents (
            id BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
            client_id INT UNSIGNED NOT NULL,
            owner_user_id INT UNSIGNED NOT NULL,
            category VARCHAR(50) NOT NULL DEFAULT 'autre',
            file_name VARCHAR(255) NOT NULL,
            file_path VARCHAR(500) NOT NULL,
            mime_type VARCHAR(100) NULL,
            size_bytes BIGINT UNSIGNED NULL,
            uploaded_by INT UNSIGNED NULL,
            uploaded_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_client_uploaded (client_id, uploaded_at),
            INDEX idx_owner (owner_user_id)
        ) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
    } catch (Throwable $e) {}
    $done = true;
}

function checkClientOwnership(int $clientId, int $uid): bool {
    $stmt = db()->prepare("SELECT id FROM clients WHERE id = ? AND user_id = ? AND deleted_at IS NULL LIMIT 1");
    $stmt->execute([$clientId, $uid]);
    return (bool) $stmt->fetch();
}

ensureDocumentsSchema();

switch ($action) {

case 'list': {
    $clientId = (int) ($_GET['client_id'] ?? $input['client_id'] ?? 0);
    if (!$clientId || !checkClientOwnership($clientId, $uid)) jsonError('client_id requis ou non autorisé', 403);
    $stmt = db()->prepare("SELECT id, client_id, category, file_name, mime_type, size_bytes, uploaded_at
                           FROM documents WHERE client_id = ? AND owner_user_id = ?
                           ORDER BY uploaded_at DESC");
    $stmt->execute([$clientId, $uid]);
    jsonOk(['documents' => $stmt->fetchAll()]);
}

case 'upload': {
    $clientId = (int) ($_POST['client_id'] ?? 0);
    if (!$clientId || !checkClientOwnership($clientId, $uid)) jsonError('client_id requis ou non autorisé', 403);
    if (empty($_FILES['file']) || ($_FILES['file']['error'] ?? 0) !== UPLOAD_ERR_OK) {
        jsonError('Aucun fichier fourni', 400);
    }
    $f = $_FILES['file'];
    if ($f['size'] > MAX_UPLOAD_BYTES) jsonError('Fichier trop volumineux (max 10 MB)', 400);
    $mime = mime_content_type($f['tmp_name']) ?: ($f['type'] ?? '');
    if (!in_array($mime, ALLOWED_MIME, true)) jsonError("Type fichier non autorisé : $mime", 400);
    $category = preg_replace('/[^a-z_]/', '', strtolower((string)($_POST['category'] ?? 'autre'))) ?: 'autre';
    $userName = (string)($_POST['file_name'] ?? $f['name']);
    $cleanName = preg_replace('/[\\/\\\\\'"]/', '_', basename($userName));
    if (strlen($cleanName) > 255) $cleanName = substr($cleanName, 0, 255);
    // Storage path : /opt/ocre-app/uploads/<tenant>/clients/<client_id>/<uuid>_<name>
    $tenant = preg_replace('/[^a-z0-9_-]/', '', strtolower($_SERVER['HTTP_X_TENANT_SLUG']
        ?? (preg_match('/^([a-z0-9-]+)\.ocre\.immo$/', $_SERVER['HTTP_HOST'] ?? '', $mh) ? $mh[1] : 'unknown')));
    $baseDir = '/opt/ocre-app/uploads/' . $tenant . '/clients/' . $clientId;
    if (!is_dir($baseDir)) @mkdir($baseDir, 0775, true);
    $uuid = bin2hex(random_bytes(8));
    $diskPath = $baseDir . '/' . $uuid . '_' . $cleanName;
    if (!@move_uploaded_file($f['tmp_name'], $diskPath)) jsonError('Échec écriture fichier', 500);
    @chmod($diskPath, 0644);
    $relPath = 'uploads/' . $tenant . '/clients/' . $clientId . '/' . $uuid . '_' . $cleanName;
    $stmt = db()->prepare(
        "INSERT INTO documents (client_id, owner_user_id, category, file_name, file_path, mime_type, size_bytes, uploaded_by)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?)"
    );
    $stmt->execute([$clientId, $uid, $category, $cleanName, $relPath, $mime, (int) $f['size'], $uid]);
    $newDocId = (int) db()->lastInsertId();
    // M116d — emit webhook event document.signed (graceful) si category indique document signe (contrat/mandat/compromis)
    if (in_array($category, ['contrat', 'mandat', 'compromis', 'signed', 'signature'], true)) {
        @require_once __DIR__ . '/lib/webhook_emit.php';
        if (function_exists('emit_event')) {
            $tenantSlugW = $_SERVER['HTTP_X_TENANT_SLUG'] ?? (preg_match('/^([a-z0-9-]+)\.ocre\.immo$/', $_SERVER['HTTP_HOST'] ?? '', $mh) ? $mh[1] : '');
            if ($tenantSlugW) emit_event($tenantSlugW, 'document.signed', ['document_id' => $newDocId, 'client_id' => $clientId, 'category' => $category, 'tenant_user_id' => $uid]);
        }
    }
    jsonOk(['id' => $newDocId, 'file_name' => $cleanName]);
}

case 'delete': {
    $id = (int) ($input['id'] ?? $_GET['id'] ?? 0);
    if (!$id) jsonError('id requis', 400);
    $cur = db()->prepare("SELECT * FROM documents WHERE id = ? AND owner_user_id = ?");
    $cur->execute([$id, $uid]);
    $row = $cur->fetch();
    if (!$row) jsonError('Document introuvable', 404);
    $diskPath = '/opt/ocre-app/' . $row['file_path'];
    @unlink($diskPath);
    db()->prepare("DELETE FROM documents WHERE id = ? AND owner_user_id = ?")->execute([$id, $uid]);
    jsonOk(['id' => $id]);
}

case 'download': {
    $id = (int) ($_GET['id'] ?? 0);
    if (!$id) jsonError('id requis', 400);
    $cur = db()->prepare("SELECT * FROM documents WHERE id = ? AND owner_user_id = ?");
    $cur->execute([$id, $uid]);
    $row = $cur->fetch();
    if (!$row) jsonError('Document introuvable', 404);
    $diskPath = '/opt/ocre-app/' . $row['file_path'];
    if (!file_exists($diskPath)) jsonError('Fichier introuvable sur disque', 404);
    header('Content-Type: ' . ($row['mime_type'] ?: 'application/octet-stream'));
    header('Content-Disposition: attachment; filename="' . addslashes($row['file_name']) . '"');
    header('Content-Length: ' . filesize($diskPath));
    readfile($diskPath);
    exit;
}

default:
    jsonError('Action inconnue (list|upload|delete|download)', 400);
}
