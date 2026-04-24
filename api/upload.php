<?php
// V17.2 Phase 2a — upload photos d'un bien. Stockage ../uploads/<dossier_id>/.
// MIME image only, 30 photos max par dossier, 8 Mo max par fichier.
require_once __DIR__ . '/db.php';
setCorsHeaders();

define('UPLOAD_MAX_BYTES', 8 * 1024 * 1024);
define('UPLOAD_MAX_PER_DOSSIER', 30);
// V17.6 Section III : PDF accepté en plus des images (pour les docs crédit).
define('UPLOAD_ALLOWED_MIME', ['image/jpeg', 'image/png', 'image/webp', 'application/pdf']);
define('UPLOAD_EXT_MAP', [
    'image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp',
    'application/pdf' => 'pdf',
]);

$action = $_GET['action'] ?? ($_POST['action'] ?? '');

function uploadsRoot() {
    // /api/upload.php → racine app = dirname(__DIR__)
    $root = dirname(__DIR__) . '/uploads';
    if (!is_dir($root)) @mkdir($root, 0755, true);
    $ht = $root . '/.htaccess';
    // V17.6 Section III : si htaccess existe mais n'accepte pas pdf → régénère.
    $current = @file_get_contents($ht) ?: '';
    if (!$current || stripos($current, 'pdf') === false) {
        @file_put_contents($ht,
            "# Images + PDF only\n" .
            "<FilesMatch \"\\.(jpg|jpeg|png|webp|pdf)$\">\n" .
            "  Require all granted\n" .
            "</FilesMatch>\n" .
            "<FilesMatch \"\\.(php|phtml|phar|sql|htaccess)$\">\n" .
            "  Require all denied\n" .
            "</FilesMatch>\n" .
            "Options -ExecCGI -Indexes\n" .
            "AddType text/plain .php .phtml .phar\n"
        );
    }
    return $root;
}

function dossierDir($dossier_id) {
    $root = uploadsRoot();
    $dir = $root . '/' . (int)$dossier_id;
    if (!is_dir($dir)) @mkdir($dir, 0755, true);
    return $dir;
}

function publicBase() {
    $base = defined('APP_URL') ? rtrim(APP_URL, '/') : 'https://app.ocre.immo';
    return $base . '/uploads';
}

function checkOwnership($dossier_id, $user) {
    $chk = db()->prepare("SELECT id FROM clients WHERE id = ? AND user_id = ?");
    $chk->execute([$dossier_id, $user['id']]);
    if (!$chk->fetch()) jsonError('Accès refusé', 403);
}

function listPhotos($dossier_id) {
    $dir = dossierDir($dossier_id);
    $items = [];
    foreach (glob($dir . '/*') ?: [] as $path) {
        if (!is_file($path)) continue;
        $name = basename($path);
        if (!preg_match('/\.(jpe?g|png|webp)$/i', $name)) continue;
        $items[] = [
            'name' => $name,
            'url' => publicBase() . '/' . $dossier_id . '/' . $name,
            'size' => filesize($path),
            'mtime' => filemtime($path),
        ];
    }
    usort($items, fn($a, $b) => $b['mtime'] - $a['mtime']);
    return $items;
}

switch ($action) {

    case 'upload': {
        $user = requireAuth();
        $dossier_id = (int)($_POST['dossier_id'] ?? 0);
        if (!$dossier_id) jsonError('dossier_id requis');
        checkOwnership($dossier_id, $user);

        if (empty($_FILES['file'])) jsonError('Aucun fichier reçu');
        $f = $_FILES['file'];
        if ($f['error'] !== UPLOAD_ERR_OK) jsonError('Erreur upload code=' . $f['error']);
        if ($f['size'] > UPLOAD_MAX_BYTES) jsonError('Fichier trop volumineux (max 8 Mo)');

        $finfo = new finfo(FILEINFO_MIME_TYPE);
        $mime = $finfo->file($f['tmp_name']);
        if (!in_array($mime, UPLOAD_ALLOWED_MIME, true)) {
            jsonError('Format non supporté (JPEG/PNG/WebP uniquement)');
        }

        $existing = listPhotos($dossier_id);
        if (count($existing) >= UPLOAD_MAX_PER_DOSSIER) {
            jsonError('Limite atteinte (30 photos max par bien)', 409);
        }

        // V18.39 — quotas globaux : 500 Mo total photos par user (100/dossier déjà couvert
        // par UPLOAD_MAX_PER_DOSSIER=30). Envoie 413 + exit si dépassé.
        require_once __DIR__ . '/_security.php';
        checkPhotoQuota((int) $user['id'], count($existing), (int) $f['size']);

        $ext = UPLOAD_EXT_MAP[$mime];
        // V17.6 Section III : prefix doc-<slug> si document_type fourni (classement par catégorie).
        $doc_type = trim((string)($_POST['document_type'] ?? ''));
        $prefix = '';
        if ($doc_type !== '') {
            $slug = preg_replace('/[^a-z0-9]+/i', '-', $doc_type);
            $slug = trim(strtolower($slug), '-');
            if ($slug !== '') $prefix = 'doc-' . substr($slug, 0, 40) . '-';
        }
        $name = $prefix . date('Ymd-His') . '-' . bin2hex(random_bytes(4)) . '.' . $ext;
        $dest = dossierDir($dossier_id) . '/' . $name;
        if (!move_uploaded_file($f['tmp_name'], $dest)) {
            jsonError('Écriture fichier impossible', 500);
        }
        @chmod($dest, 0644);

        jsonOk([
            'photo' => [
                'name' => $name,
                'url' => publicBase() . '/' . $dossier_id . '/' . $name,
                'size' => filesize($dest),
                'mtime' => filemtime($dest),
            ],
        ]);
    }

    case 'list': {
        $user = requireAuth();
        $dossier_id = (int)($_GET['dossier_id'] ?? 0);
        if (!$dossier_id) jsonError('dossier_id requis');
        checkOwnership($dossier_id, $user);
        jsonOk(['photos' => listPhotos($dossier_id)]);
    }

    case 'delete': {
        $user = requireAuth();
        $input = getInput();
        $dossier_id = (int)($input['dossier_id'] ?? 0);
        $name = basename((string)($input['name'] ?? ''));
        if (!$dossier_id || !$name) jsonError('dossier_id et name requis');
        if (!preg_match('/^[A-Za-z0-9._-]+\.(jpe?g|png|webp)$/i', $name)) {
            jsonError('Nom de fichier invalide');
        }
        checkOwnership($dossier_id, $user);
        $path = dossierDir($dossier_id) . '/' . $name;
        if (!file_exists($path)) jsonError('Fichier introuvable', 404);
        if (!@unlink($path)) jsonError('Suppression impossible', 500);
        jsonOk(['deleted' => $name]);
    }

    default:
        jsonError('Action inconnue', 404);
}
