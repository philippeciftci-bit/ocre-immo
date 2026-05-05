<?php
// V17.2 Phase 2a — upload photos d'un bien. Stockage ../uploads/<dossier_id>/.
// MIME image only, 30 photos max par dossier, 15 Mo max par fichier (M128).
// M/2026/04/29/3 — pipeline compression WebP auto + thumb 400x400 + table photo_compression_stats.
// M/2026/05/02/17 — M128 : limite portée 8 Mo -> 15 Mo. Compression client (canvas 1920px JPEG q=0.85)
// désormais en amont pour les images > 2 Mo, donc 15 Mo couvre largement les PDF officiels et les
// rares images post-compression toujours volumineuses. Toast erreur dédupliqué côté client.
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/lib/photo_pipeline.php';
setCorsHeaders();

define('UPLOAD_MAX_BYTES', 15 * 1024 * 1024);
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
    // M/2026/05/05/21 — M-Photos-Backend-Count-Fix : ne compter QUE les fichiers ORIGINAUX JPEG/PNG,
    // pas les sous-produits du pipeline (photo_pipeline_compress genere <base>.webp + <base>_thumb.webp
    // pour chaque upload .jpg/.png). Sinon 4 photos uploadees = 12 fichiers comptes => "Limite atteinte" prematuree.
    // M/2026/05/05/22 — M-Photos-Limit-State-Stuck : clearstatcache() pour fresh fs stats apres delete (sinon PHP
    // peut retourner des is_file() obsoletes sur le meme path apres unlink, faussant le count).
    clearstatcache(true);
    $dir = dossierDir($dossier_id);
    $items = [];
    $originalsBase = []; // basenames (sans extension) des fichiers originaux JPEG/PNG, pour identifier les .webp standalone
    foreach (glob($dir . '/*') ?: [] as $path) {
        if (!is_file($path)) continue;
        $name = basename($path);
        if (preg_match('/\.(jpe?g|png)$/i', $name)) {
            $originalsBase[pathinfo($name, PATHINFO_FILENAME)] = true;
        }
    }
    foreach (glob($dir . '/*') ?: [] as $path) {
        if (!is_file($path)) continue;
        $name = basename($path);
        // Exclure thumbs (sous-produit pipeline).
        if (strpos($name, '_thumb.') !== false) continue;
        // Compter JPEG/PNG (originaux) toujours.
        $isJpegPng = preg_match('/\.(jpe?g|png)$/i', $name);
        // Pour .webp : ne compter QUE si pas de sibling JPEG/PNG (i.e. user a uploade un .webp directement).
        // Un .webp avec sibling .jpg/.png = compression du pipeline, on l ignore.
        $isWebp = preg_match('/\.webp$/i', $name);
        if ($isWebp && !$isJpegPng) {
            $base = pathinfo($name, PATHINFO_FILENAME);
            if (!empty($originalsBase[$base])) continue; // sous-produit, on saute
        } elseif (!$isJpegPng && !$isWebp) {
            continue; // autre extension non-photo
        }
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
        if ($f['size'] > UPLOAD_MAX_BYTES) jsonError('Fichier trop volumineux (max 15 Mo)');

        $finfo = new finfo(FILEINFO_MIME_TYPE);
        $mime = $finfo->file($f['tmp_name']);
        if (!in_array($mime, UPLOAD_ALLOWED_MIME, true)) {
            jsonError('Format non supporté (JPEG/PNG/WebP uniquement)');
        }

        $existing = listPhotos($dossier_id);
        // M/2026/05/05/22 — M-Photos-Limit-State-Stuck : message d erreur verbose pour diag (count exact backend).
        // Permet d identifier si le backend pense etre a 30 alors que l UI affiche moins.
        if (count($existing) >= UPLOAD_MAX_PER_DOSSIER) {
            jsonError('Limite atteinte (count=' . count($existing) . ', limit=' . UPLOAD_MAX_PER_DOSSIER . ')', 409);
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

        // M/2026/04/29/3 — compression WebP + thumb (best-effort, n'écrase pas si fail).
        $compResult = ['compressed' => false];
        if (in_array($mime, ['image/jpeg', 'image/png'], true)) {
            $compResult = photo_pipeline_compress($dest, dossierDir($dossier_id) . '/' . pathinfo($name, PATHINFO_FILENAME), (int) $f['size']);
        }

        jsonOk([
            'photo' => [
                'name' => $name,
                'url' => publicBase() . '/' . $dossier_id . '/' . $name,
                'size' => filesize($dest),
                'mtime' => filemtime($dest),
                'webp_url' => $compResult['webp_name'] ? publicBase() . '/' . $dossier_id . '/' . $compResult['webp_name'] : null,
                'thumb_url' => $compResult['thumb_name'] ? publicBase() . '/' . $dossier_id . '/' . $compResult['thumb_name'] : null,
                'compression_ratio' => $compResult['ratio'] ?? null,
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
        $dir = dossierDir($dossier_id);
        $path = $dir . '/' . $name;
        if (!file_exists($path)) jsonError('Fichier introuvable', 404);
        if (!@unlink($path)) jsonError('Suppression impossible', 500);
        // M/2026/05/05/21 — M-Photos-Backend-Count-Fix : hard-delete des sous-produits pipeline (.webp + _thumb.webp)
        // pour eviter accumulation d orphelins qui faussent listPhotos (cause "Limite atteinte" prematuree).
        $base = pathinfo($name, PATHINFO_FILENAME);
        $deleted = [$name];
        foreach ([$base . '.webp', $base . '_thumb.webp'] as $sub) {
            $subPath = $dir . '/' . $sub;
            if ($subPath !== $path && is_file($subPath)) {
                if (@unlink($subPath)) $deleted[] = $sub;
            }
        }
        // M/2026/05/05/22 — M-Photos-Limit-State-Stuck : invalider stats fs apres unlink pour que la prochaine
        // requete upload voie un count frais (sinon is_file() sur les unlink peut retourner true pendant ~1s).
        clearstatcache(true);
        jsonOk(['deleted' => $deleted, 'count_after' => count(listPhotos($dossier_id))]);
    }

    // M/2026/05/05/21 — M-Photos-Backend-Count-Fix : purge orphelins pipeline (.webp + _thumb.webp sans original).
    // Permet a Philippe (et tout user) de nettoyer le residu de tests upload+delete qui sature le compteur 30.
    case 'purge_orphans': {
        $user = requireAuth();
        $input = getInput();
        $dossier_id = (int)($input['dossier_id'] ?? 0);
        if (!$dossier_id) jsonError('dossier_id requis');
        checkOwnership($dossier_id, $user);
        $dir = dossierDir($dossier_id);
        $originalsBase = [];
        foreach (glob($dir . '/*') ?: [] as $path) {
            if (!is_file($path)) continue;
            $name = basename($path);
            if (preg_match('/\.(jpe?g|png)$/i', $name)) {
                $originalsBase[pathinfo($name, PATHINFO_FILENAME)] = true;
            }
        }
        $purged = [];
        foreach (glob($dir . '/*') ?: [] as $path) {
            if (!is_file($path)) continue;
            $name = basename($path);
            $base = pathinfo($name, PATHINFO_FILENAME);
            $isThumb = strpos($name, '_thumb.') !== false;
            $isWebp = preg_match('/\.webp$/i', $name);
            $thumbBase = $isThumb ? preg_replace('/_thumb$/', '', $base) : null;
            // Sous-produit pipeline : .webp ou _thumb.webp dont l original JPEG/PNG n existe plus.
            if ($isThumb && $thumbBase && empty($originalsBase[$thumbBase])) {
                if (@unlink($path)) $purged[] = $name;
            } elseif ($isWebp && !$isThumb && empty($originalsBase[$base])) {
                if (@unlink($path)) $purged[] = $name;
            }
        }
        jsonOk(['purged' => $purged, 'count_after' => count(listPhotos($dossier_id))]);
    }

    // M/2026/04/30/43 — Bloc 13 Documents : PDF + scans CIN/RIB. Stockage /uploads/<id>/documents/.
    case 'upload_doc': {
        $user = requireAuth();
        $dossier_id = (int)($_POST['dossier_id'] ?? 0);
        if (!$dossier_id) jsonError('dossier_id requis');
        checkOwnership($dossier_id, $user);

        $doc_type = preg_replace('/[^a-z0-9_]+/i', '', strtolower((string)($_POST['doc_type'] ?? 'autre')));
        if ($doc_type === '') $doc_type = 'autre';

        if (empty($_FILES['file'])) jsonError('Aucun fichier reçu');
        $f = $_FILES['file'];
        if ($f['error'] !== UPLOAD_ERR_OK) jsonError('Erreur upload code=' . $f['error']);
        // Limite 10 Mo pour les documents (vs 8 Mo photos).
        if ($f['size'] > 10 * 1024 * 1024) jsonError('Fichier trop volumineux (max 10 Mo)');

        $finfo = new finfo(FILEINFO_MIME_TYPE);
        $mime = $finfo->file($f['tmp_name']);
        $allowed = ['application/pdf', 'image/jpeg', 'image/png'];
        if (!in_array($mime, $allowed, true)) jsonError('Format non supporté (PDF/JPG/PNG)');
        $ext = ['application/pdf' => 'pdf', 'image/jpeg' => 'jpg', 'image/png' => 'png'][$mime];

        $dir = dossierDir($dossier_id) . '/documents';
        if (!is_dir($dir)) @mkdir($dir, 0755, true);

        $existing = glob($dir . '/*') ?: [];
        if (count($existing) >= 30) jsonError('Limite 30 documents par dossier', 409);

        $original = preg_replace('/[^A-Za-z0-9._-]+/', '_', basename((string)$f['name']));
        $name = $doc_type . '-' . date('Ymd-His') . '-' . bin2hex(random_bytes(4)) . '.' . $ext;
        $dest = $dir . '/' . $name;
        if (!move_uploaded_file($f['tmp_name'], $dest)) jsonError('Écriture impossible', 500);
        @chmod($dest, 0644);

        jsonOk(['document' => [
            'name' => $name,
            'original_name' => $original,
            'doc_type' => $doc_type,
            'size' => filesize($dest),
            'mime' => $mime,
            'url' => publicBase() . '/' . $dossier_id . '/documents/' . $name,
            'uploaded_at' => date('c'),
        ]]);
    }

    case 'delete_doc': {
        $user = requireAuth();
        $input = getInput();
        $dossier_id = (int)($input['dossier_id'] ?? 0);
        $name = basename((string)($input['name'] ?? ''));
        if (!$dossier_id || !$name) jsonError('dossier_id et name requis');
        if (!preg_match('/^[A-Za-z0-9._-]+\.(pdf|jpe?g|png)$/i', $name)) jsonError('Nom invalide');
        checkOwnership($dossier_id, $user);
        $path = dossierDir($dossier_id) . '/documents/' . $name;
        if (!file_exists($path)) jsonError('Fichier introuvable', 404);
        if (!@unlink($path)) jsonError('Suppression impossible', 500);
        jsonOk(['deleted' => $name]);
    }

    default:
        jsonError('Action inconnue', 404);
}
