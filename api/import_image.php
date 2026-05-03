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
    // V45 — refonte exhaustive : extraction de TOUT le contenu visible (équipements,
    // conditions, négociation, périodicité, repères, description verbatim, OCR brut).
    $sys = "Tu extrais d'une IMAGE d'annonce immobilier (capture Facebook Marketplace, WhatsApp, SMS, fiche agence, photo papier, panneau, screenshot) TOUT le contenu visible. Tu retournes UNIQUEMENT un JSON valide, sans markdown.\n\n"
         . "RÈGLE D'OR : Aucune information visible ne doit être ignorée. Si tu vois un mot-clé d'équipement (hammam, piscine, climatisation, jardin, terrasse, parking, cheminée, fontaine, patio, vue, ascenseur…), tu l'ajoutes au tableau equipements. Si le texte mentionne 'À LOUER', 'À VENDRE', 'EN LOCATION', tu remplis transaction.type. Si une période apparaît (par mois, par an, par nuit), tu remplis transaction.periode et prix.periode.\n\n"
         . "Cherche activement : chambre/pièce/dormitorio/bedroom = pareil. Salle de bain/sdb/bathroom = pareil. Caution/dépôt de garantie = pareil.\n\n"
         . "SCHÉMA EXHAUSTIF :\n"
         . "{\n"
         . "  // V45 — schéma exhaustif\n"
         . "  transaction: {type: 'location_longue'|'location_courte'|'vente'|'investissement'|'colocation'|null, periode: 'mois'|'annee'|'nuit'|'semaine'|null, negociable: bool|null},\n"
         . "  bien_meta: {type: 'riad'|'villa'|'appartement'|'maison'|'terrain'|'local_commercial'|'loft'|'duplex'|'penthouse'|'autre'|null, etat: 'neuf'|'renove'|'a_renover'|'meuble'|'semi_meuble'|'vide'|null, surface_m2: number|null, terrain_m2: number|null, chambres: number|null, salles_de_bains: number|null, salons: number|null, etages: number|null, etage_du_bien: number|null, ascenseur: bool|null, equipements: ['climatisation','hammam','piscine','jardin','terrasse','parking','cave','cheminee','cuisine_equipee','buanderie','patio','fontaine','vue_atlas','vue_mer','ascenseur',...]},\n"
         . "  localisation: {ville: str|null, quartier: str|null, secteur: str|null, reperes: [str], adresse_precise: str|null, pays: 'Maroc'|'France'|'Espagne'|str|null},\n"
         . "  prix_meta: {montant: number|null, devise: 'MAD'|'EUR'|'USD', periode: 'mois'|'annee'|'nuit'|'semaine'|'total', negociable: bool|null, prix_au_m2: number|null},\n"
         . "  conditions: {caution_mois: number|null, caution_montant: number|null, avance_mois: number|null, frais_agence_pct: number|null, frais_agence_montant: number|null, charges: number|null, disponibilite: 'immediate'|'date_specifique'|null, date_disponibilite: str|null, duree_min: str|null, animaux_acceptes: bool|null},\n"
         . "  contact: {nom: str|null, telephone: str|null, email: str|null, whatsapp: str|null, type: 'agence'|'particulier'|null, agence_nom: str|null, agence_url: str|null},\n"
         . "  description_libre: 'verbatim du texte de l\\'annonce, en intégralité, sans coupure',\n"
         . "  source: {type: 'facebook_marketplace'|'whatsapp'|'sms'|'panneau'|'fiche_agence'|'annonce_web'|'email'|'audio'|'note_manuelle'|'autre', url: str|null, date_capture: 'ISO 8601', auteur_apparent: str|null},\n"
         . "  confidence: 'high'|'medium'|'low',\n"
         . "  raw_text_visible: 'TOUT le texte OCR détecté dans le document, brut, ligne par ligne',\n\n"
         . "  // ─── champs LEGACY (à remplir AUSSI pour rétrocompat frontend v44 et antérieurs) ───\n"
         . "  titre: str|null, description_complete: str|null,\n"
         . "  prix: number|null, devise: 'EUR'|'MAD'|'USD'|null,\n"
         . "  surface_habitable: number|null, surface_terrain: number|null,\n"
         . "  nombre_pieces: number|null, nombre_chambres: number|null, nombre_sdb: number|null,\n"
         . "  etage: number|null, ascenseur: bool|null, parking: bool|null, cave: bool|null, balcon_terrasse: bool|null,\n"
         . "  dpe_class: str|null, dpe_ges: str|null, annee_construction: number|null, neuf_ancien: 'neuf'|'ancien'|null,\n"
         . "  types_bien: [str], pays_bien: 'MA'|'FR'|'ES'|null, ville_bien: str|null, quartier_bien: str|null, code_postal: str|null,\n"
         . "  annonceur_type: 'professionnel'|'particulier'|null, annonceur_nom: str|null,\n"
         . "  annonceur_tel_mentionne: str|null, annonceur_email_mentionne: str|null,\n"
         . "  source_type: 'sms'|'whatsapp'|'screenshot_web'|'photo_annonce_papier'|'photo_panneau'|'capture_autre',\n"
         . "  source_context: str,\n"
         . "  layout_type: 'single_photo'|'collage_multiple'|'conversation_with_photos'|'text_only',\n"
         . "  visible_photos_count: number\n"
         . "}\n\n"
         . "RÈGLES DE NORMALISATION :\n"
         . "- null si vraiment introuvable, mais cherche d'abord intensivement.\n"
         . "- '500k'→500000, '1,8M'→1800000, '60 000 DH'→60000 (devise MAD), '€'/'EUR'→'EUR'.\n"
         . "- Si 'À LOUER' / 'EN LOCATION' visible → transaction.type='location_longue' (sauf si 'courte durée'/'à la nuit'/'Airbnb').\n"
         . "- Si 'À VENDRE' / 'EN VENTE' visible → transaction.type='vente'.\n"
         . "- Si '60 000 DH/mois' → prix_meta.montant=60000, prix_meta.devise=MAD, prix_meta.periode=mois.\n"
         . "- Si 'négociable' / 'à débattre' → transaction.negociable=true et prix_meta.negociable=true.\n"
         . "- Si '1 mois caution' ou 'caution = 1 mois' → conditions.caution_mois=1.\n"
         . "- Si 'disponible immédiatement' / 'libre de suite' → conditions.disponibilite='immediate'.\n"
         . "- Pour repères : extraire références géographiques (ex: 'proche Jemaa el-Fna', '5 min des souks', 'face à la mer').\n"
         . "- Pour equipements : tableau STRINGS en français normalisé. Inclure tout ce qui est mentionné même brièvement.\n"
         . "- Champs LEGACY : remplir aussi (titre, prix, devise, ville_bien, etc.) car le front v44 les attend.\n"
         . "- description_libre + description_complete : copier verbatim TOUT le bloc descriptif lisible.\n"
         . "- raw_text_visible : reproduire ligne par ligne TOUT le texte OCR de l'image, brut.\n"
         . "- source_type : si bulles SMS/iOS→'sms' ; WhatsApp (vert/coche)→'whatsapp' ; Marketplace/Facebook→'screenshot_web' (et source.type='facebook_marketplace') ; URL navigateur visible→'screenshot_web' ; papier→'photo_annonce_papier' ; panneau→'photo_panneau' ; sinon 'capture_autre'.\n\n"
         . "EXEMPLE OBLIGATOIRE — pour cette annonce :\n"
         . '"RIAD MEUBLÉ À LOUER – MÉDINA DE MARRAKECH. 3 chambres confortables, salon lumineux, salles de bains, hammam traditionnel, climatisation. Situé à quelques minutes de Jemaa el-Fna. Loyer 60 000 DH/mois, 1 mois de caution + 1 mois de loyer. Loyer négociable. Disponible immédiatement."' . "\n\n"
         . "Tu DOIS retourner :\n"
         . "{\n"
         . '  "transaction": {"type": "location_longue", "periode": "mois", "negociable": true},' . "\n"
         . '  "bien_meta": {"type": "riad", "etat": "meuble", "chambres": 3, "salons": 1, "salles_de_bains": 1, "equipements": ["hammam", "climatisation", "salon_lumineux", "meuble"]},' . "\n"
         . '  "localisation": {"ville": "Marrakech", "quartier": "Médina", "reperes": ["proche Jemaa el-Fna"], "pays": "Maroc"},' . "\n"
         . '  "prix_meta": {"montant": 60000, "devise": "MAD", "periode": "mois", "negociable": true},' . "\n"
         . '  "conditions": {"caution_mois": 1, "avance_mois": 1, "disponibilite": "immediate"},' . "\n"
         . '  "contact": {"type": "particulier"},' . "\n"
         . '  "description_libre": "RIAD MEUBLÉ À LOUER – MÉDINA DE MARRAKECH. 3 chambres confortables, salon lumineux, salles de bains, hammam traditionnel, climatisation. Situé à quelques minutes de Jemaa el-Fna. Loyer 60 000 DH/mois, 1 mois de caution + 1 mois de loyer. Loyer négociable. Disponible immédiatement.",' . "\n"
         . '  "raw_text_visible": "RIAD MEUBLÉ À LOUER\\nMÉDINA DE MARRAKECH\\n3 chambres confortables\\nSalon lumineux\\nSalles de bains\\nHammam traditionnel\\nClimatisation\\nLoyer 60 000 DH / mois\\n1 mois caution + 1 mois loyer\\nLoyer négociable\\nDisponible immédiatement",' . "\n"
         . '  "confidence": "high",' . "\n"
         . '  "source": {"type": "facebook_marketplace"},' . "\n"
         . '  "titre": "RIAD MEUBLÉ À LOUER – MÉDINA DE MARRAKECH",' . "\n"
         . '  "prix": 60000, "devise": "MAD",' . "\n"
         . '  "nombre_chambres": 3, "nombre_sdb": 1, "nombre_pieces": 4,' . "\n"
         . '  "ville_bien": "Marrakech", "quartier_bien": "Médina", "pays_bien": "MA",' . "\n"
         . '  "types_bien": ["Riad"], "annonceur_type": "particulier",' . "\n"
         . '  "source_type": "screenshot_web"' . "\n"
         . "}\n\n"
         . "Suis ce niveau d'exhaustivité POUR CHAQUE image. Aucun champ visible n'est ignoré.";

    $payload = [
        'model' => CLAUDE_MODEL,
        'max_tokens' => 4500,
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
        if (is_array($parsed)) {
            $parsed['_raw_model'] = CLAUDE_MODEL;
            // V46 — log de diagnostic non-bloquant (compte les champs non-null pour preuves).
            $count_nonnull = 0;
            $walker = function ($x) use (&$walker, &$count_nonnull) {
                if (is_array($x)) { foreach ($x as $v) $walker($v); }
                elseif ($x !== null && $x !== '') $count_nonnull++;
            };
            $walker($parsed);
            $eq = is_array($parsed['bien_meta']['equipements'] ?? null) ? count($parsed['bien_meta']['equipements']) : 0;
            $rp = is_array($parsed['localisation']['reperes'] ?? null) ? count($parsed['localisation']['reperes']) : 0;
            $tx = $parsed['transaction']['type'] ?? 'null';
            $pp = $parsed['prix_meta']['periode'] ?? 'null';
            error_log("[v46-extract] non-null=$count_nonnull eq=$eq rep=$rp tx=$tx period=$pp model=" . CLAUDE_MODEL);
            return $parsed;
        }
    }
    return null;
}

$user = requireAuth();
$action = $_GET['action'] ?? ($_POST['action'] ?? '');

switch ($action) {

    case 'extract': {
        require_once __DIR__ . '/_security.php';
        // V18.39 — rate limit 30 / heure par user.
        checkRateLimit('import_image', 30, 3600, (int) $user['id']);
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
