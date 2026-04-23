<?php
// V18.18 — extraction d'annonce depuis une image (capture WhatsApp/SMS, photo annonce papier/panneau).
// Pipeline : upload → resize/JPEG → base64 → Claude Haiku vision → JSON → stockage auth.
//
// Endpoints :
//   POST ?action=extract          multipart image= (file) : upload + AI extract, retourne {extracted, image_url}.
//   POST ?action=create_staged    body JSON {extracted, image_path, source_type, source_context}
//                                 : crée un dossier staged avec d.import_type='image'.
//
// Le proxy de lecture est dans api/image.php?id=X (auth user + verify ownership).

require_once __DIR__ . '/db.php';
setCorsHeaders();

const CLAUDE_MODEL = 'claude-haiku-4-5-20251001';
const MAX_IMAGE_BYTES = 10 * 1024 * 1024;   // 10 Mo upload max.
const RESIZE_MAX_PX = 1600;                  // Resize si largeur > 1600.
const JPEG_QUALITY = 85;

function uploadsBase(): string {
    // /home/expergh/www/ocre/app/api/import_image.php → base = /home/expergh/www/ocre/app/uploads/
    return realpath(__DIR__ . '/..') . '/uploads';
}

function ensureUserImportDir(int $user_id): string {
    $base = uploadsBase();
    $dir = $base . '/users/user_' . $user_id . '/imports';
    if (!is_dir($dir)) {
        @mkdir($dir, 0750, true);
    }
    // .htaccess deny all pour empêcher accès direct (lecture via api/image.php).
    $ht = $base . '/.htaccess';
    if (!is_file($ht)) {
        @file_put_contents($ht, "Require all denied\nOrder deny,allow\nDeny from all\n");
    }
    return $dir;
}

function anthropicKey(): ?string {
    $k = getSetting('anthropic_api_key', '');
    if (!$k) $k = getenv('ANTHROPIC_API_KEY') ?: '';
    return $k ?: null;
}

function saveUploadedImage(array $file, int $user_id): array {
    if (($file['error'] ?? -1) !== UPLOAD_ERR_OK) return ['error' => 'Upload échoué (err ' . ($file['error'] ?? -1) . ')'];
    if (($file['size'] ?? 0) > MAX_IMAGE_BYTES) return ['error' => 'Image trop grande (> 10 Mo)'];

    $tmp = $file['tmp_name'] ?? '';
    if (!$tmp || !is_readable($tmp)) return ['error' => 'Fichier temporaire illisible'];

    // Détection mime via finfo (fiable).
    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $mime = finfo_file($finfo, $tmp);
    finfo_close($finfo);
    if (!$mime) return ['error' => 'Mime inconnu'];

    $src = null;
    $srcMime = $mime;
    if ($mime === 'image/jpeg' || $mime === 'image/jpg') {
        $src = @imagecreatefromjpeg($tmp);
    } elseif ($mime === 'image/png') {
        $src = @imagecreatefrompng($tmp);
    } elseif ($mime === 'image/webp' && function_exists('imagecreatefromwebp')) {
        $src = @imagecreatefromwebp($tmp);
    } elseif ($mime === 'image/heic' || $mime === 'image/heif') {
        // iOS Safari convertit normalement en JPEG. Fallback : rejet gracieux.
        if (extension_loaded('imagick')) {
            try {
                $im = new Imagick($tmp);
                $im->setImageFormat('jpeg');
                $jpegTmp = tempnam(sys_get_temp_dir(), 'heic');
                $im->writeImage($jpegTmp);
                $im->clear();
                $src = @imagecreatefromjpeg($jpegTmp);
                @unlink($jpegTmp);
                $srcMime = 'image/jpeg';
            } catch (Exception $e) {
                return ['error' => 'HEIC non géré : convertis en JPEG/PNG depuis ton iPhone (Photos → Partager → Options → JPEG).'];
            }
        } else {
            return ['error' => 'HEIC non géré : convertis en JPEG/PNG depuis ton iPhone (Photos → Partager → Options → JPEG).'];
        }
    } else {
        return ['error' => 'Format non supporté (' . $mime . '). JPEG/PNG/WebP/HEIC attendus.'];
    }
    if (!$src) return ['error' => 'Décodage image impossible'];

    $w = imagesx($src);
    $h = imagesy($src);
    // Resize si largeur > RESIZE_MAX_PX (ratio conservé).
    if ($w > RESIZE_MAX_PX) {
        $newW = RESIZE_MAX_PX;
        $newH = (int) round($h * ($newW / $w));
        $dst = imagecreatetruecolor($newW, $newH);
        imagecopyresampled($dst, $src, 0, 0, 0, 0, $newW, $newH, $w, $h);
        imagedestroy($src);
        $src = $dst;
    }

    // Génère UUID-like filename.
    $uuid = bin2hex(random_bytes(12));
    $dir = ensureUserImportDir($user_id);
    $relPath = 'users/user_' . $user_id . '/imports/' . $uuid . '.jpg';
    $absPath = $dir . '/' . $uuid . '.jpg';

    if (!imagejpeg($src, $absPath, JPEG_QUALITY)) {
        imagedestroy($src);
        return ['error' => 'Écriture JPEG KO'];
    }
    imagedestroy($src);

    $size = filesize($absPath) ?: 0;
    $data = @file_get_contents($absPath);
    if ($data === false) return ['error' => 'Relecture image KO'];

    return [
        'ok' => true,
        'path' => $relPath,
        'abs' => $absPath,
        'base64' => base64_encode($data),
        'mime' => 'image/jpeg',
        'size' => $size,
    ];
}

function claudeExtractFromImage(string $base64, string $mime, string $apiKey): ?array {
    $sys = "Tu extrais d'une IMAGE d'annonce immobilier (capture SMS/WhatsApp, photo annonce papier, panneau, screenshot) les infos en JSON valide uniquement.\n"
         . "Schéma complet :\n"
         . "{titre, description_complete (texte intégral lisible sur l'image, max 5000 chars),\n"
         . " prix (nombre), devise (EUR|MAD|USD),\n"
         . " surface_habitable (m²), surface_terrain (m² distinct),\n"
         . " nombre_pieces, nombre_chambres, nombre_sdb,\n"
         . " etage (number), ascenseur, parking, cave, balcon_terrasse (bool),\n"
         . " dpe_class (A-G), dpe_ges (A-G), annee_construction, neuf_ancien,\n"
         . " types_bien (array : Villa|Appartement|Riad|Maison|Terrain|Commerce|Ferme|Bureau / plateau|Bâtiment industriel),\n"
         . " pays_bien (MA|FR|ES), ville_bien, quartier_bien, code_postal,\n"
         . " annonceur_type ('professionnel'|'particulier'), annonceur_nom,\n"
         . " annonceur_tel_mentionne (E.164 +33/+212 si visible, sinon null),\n"
         . " annonceur_email_mentionne (email si visible, sinon null),\n"
         . " source_type (enum strict : 'sms'|'whatsapp'|'screenshot_web'|'photo_annonce_papier'|'photo_panneau'|'capture_autre'),\n"
         . " source_context (1 phrase résumé : contexte de la capture, ex 'SMS d'un prospecteur à 15h32')}\n\n"
         . "Règles :\n"
         . "- null si non trouvé. '500k'→500000, '1,8M'→1800000.\n"
         . "- source_type : si bulles SMS/chat visibles avec horodatage iOS→'sms' ; WhatsApp (fond vert, avatar rond, coche double)→'whatsapp' ; URL/navigateur visible→'screenshot_web' ; papier tenu main/scan→'photo_annonce_papier' ; panneau 'À VENDRE' rue→'photo_panneau' ; sinon 'capture_autre'.\n"
         . "- Si SMS/WhatsApp visible en haut : extrait tel/nom expéditeur dans annonceur_tel_mentionne/annonceur_nom. Sinon null.\n"
         . "- annonceur_tel_mentionne : SEULEMENT si visible dans le texte de l'image.";

    $payload = [
        'model' => CLAUDE_MODEL,
        'max_tokens' => 2500,
        'system' => $sys,
        'messages' => [[
            'role' => 'user',
            'content' => [
                ['type' => 'image', 'source' => ['type' => 'base64', 'media_type' => $mime, 'data' => $base64]],
                ['type' => 'text', 'text' => "Extrais en JSON selon le schéma."],
            ],
        ]],
    ];
    $ch = curl_init('https://api.anthropic.com/v1/messages');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_UNICODE),
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/json',
            'x-api-key: ' . $apiKey,
            'anthropic-version: 2023-06-01',
        ],
        CURLOPT_TIMEOUT => 60,
    ]);
    $resp = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($code >= 400 || !$resp) return null;
    $j = json_decode($resp, true);
    if (!$j || empty($j['content'])) return null;
    $text = '';
    foreach ($j['content'] as $c) if (($c['type'] ?? '') === 'text') $text .= $c['text'];
    if (preg_match('/\{[\s\S]*\}/', $text, $m)) {
        $parsed = json_decode($m[0], true);
        if (is_array($parsed)) { $parsed['_raw_model'] = CLAUDE_MODEL; return $parsed; }
    }
    return null;
}

$user = requireAuth();
$action = $_GET['action'] ?? ($_POST['action'] ?? '');

switch ($action) {

    case 'extract': {
        if (!isset($_FILES['image'])) jsonError('champ image (multipart) manquant', 400);
        $apiKey = anthropicKey();
        if (!$apiKey) jsonError('Clé Anthropic non configurée — voir /admin/ → API', 503);

        $up = saveUploadedImage($_FILES['image'], (int) $user['id']);
        if (isset($up['error'])) jsonError($up['error'], 400);

        $result = claudeExtractFromImage($up['base64'], $up['mime'], $apiKey);
        if (!$result) {
            // Garde l'image malgré l'échec Claude, permettra retry.
            jsonError('Extraction Claude échouée — réessaie ou ajoute les champs manuellement', 502, [
                'image_path' => $up['path'],
            ]);
        }

        logAction((int) $user['id'], 'import_image_extract', $up['path']);
        jsonOk([
            'extracted' => $result,
            'image_path' => $up['path'],
            'image_url' => '/api/image.php?path=' . urlencode($up['path']),
            'size' => $up['size'],
        ]);
    }

    default:
        jsonError('action inconnue : ' . $action, 400);
}
