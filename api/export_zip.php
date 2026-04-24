<?php
// V18.32 — Export ZIP autonome (Option A Philippe) : génère une archive contenant tous les
// dossiers du user + photos embeddées (locales + téléchargées depuis URLs externes) + mini
// viewer HTML standalone pour consultation hors connexion.
//
// Endpoints :
//   GET  ?action=preview     (auth user)     → JSON {dossiers, staged, archived, photos_est, size_est}
//   GET  ?action=generate    (auth user)     → ZIP binaire (Content-Disposition attachment)

require_once __DIR__ . '/db.php';
setCorsHeaders();

const MAX_ZIP_BYTES        = 500 * 1024 * 1024;  // 500 Mo
const EXTERNAL_FETCH_TIMEOUT = 5;                // 5 s par URL externe
const EXTERNAL_FETCH_MAX_PER_DOSSIER = 15;       // cap défensif
const TEMPLATE_DIR = __DIR__ . '/../export-template';

function uploadsBase(): string { return realpath(__DIR__ . '/..') . '/uploads'; }

function collectPhotoSources($bien) {
    $raw = $bien['photos'] ?? [];
    if (!is_array($raw)) return [];
    $out = [];
    $seen = [];
    foreach ($raw as $p) {
        if (is_string($p)) $src = $p;
        elseif (is_array($p)) $src = $p['url'] ?? $p['url_internal'] ?? '';
        else continue;
        if (!$src) continue;
        if (isset($seen[$src])) continue;
        $seen[$src] = true;
        $out[] = $src;
        if (count($out) >= EXTERNAL_FETCH_MAX_PER_DOSSIER) break;
    }
    return $out;
}

function normalizeClient($row): array {
    $d = json_decode($row['data'] ?? '{}', true) ?: [];
    $d['id'] = (int) $row['id'];
    $d['archived'] = (bool) (int) ($row['archived'] ?? 0);
    $d['is_draft'] = (bool) (int) ($row['is_draft'] ?? 0);
    $d['is_staged'] = (bool) (int) ($row['is_staged'] ?? 0);
    $d['projet'] = $row['projet'] ?? ($d['projet'] ?? 'Acheteur');
    $d['is_investisseur'] = (bool) (int) ($row['is_investisseur'] ?? 0);
    $d['created_at'] = $row['created_at'] ?? null;
    $d['updated_at'] = $row['updated_at'] ?? null;
    return $d;
}

function fetchDossiers(int $user_id): array {
    // V18.17 schéma : is_staged + promoted_at. On ramène TOUT (main + staged + archivé).
    $stmt = db()->prepare(
        "SELECT id, data, is_draft, is_staged, archived, projet, is_investisseur, created_at, updated_at
         FROM clients WHERE user_id = ? ORDER BY updated_at DESC"
    );
    $stmt->execute([$user_id]);
    $rows = $stmt->fetchAll();
    return array_map('normalizeClient', $rows);
}

function estimatePhotoSize(array $dossiers): int {
    // Estimation grossière : 200 Ko par photo (moyenne compressée JPEG 1600px q85).
    $n = 0;
    foreach ($dossiers as $d) $n += count(collectPhotoSources($d['bien'] ?? []));
    return $n * 200 * 1024;
}

function switchPhotoRefs(array &$dossiers, array $photoMap) {
    // photoMap : ['original_src' => 'photos/abc123.ext']. Réécrit d.bien.photos[].
    foreach ($dossiers as &$d) {
        $bien = $d['bien'] ?? [];
        $raw = $bien['photos'] ?? [];
        if (!is_array($raw)) continue;
        $newPhotos = [];
        foreach ($raw as $p) {
            $src = is_string($p) ? $p : (is_array($p) ? ($p['url'] ?? $p['url_internal'] ?? '') : '');
            if (!$src) continue;
            if (isset($photoMap[$src])) {
                $newPhotos[] = [
                    'url_internal' => $photoMap[$src],
                    'source_original' => $src,
                ];
            } else {
                // Photo non embeddée (erreur download) : on garde l'URL originale, le viewer
                // tentera de charger depuis internet au mieux.
                $newPhotos[] = ['url' => $src];
            }
        }
        $bien['photos'] = $newPhotos;
        $d['bien'] = $bien;
    }
    unset($d);
}

function downloadExternal(string $url, int $maxBytes = 6291456): ?array {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => EXTERNAL_FETCH_TIMEOUT,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS => 3,
        CURLOPT_USERAGENT => 'Mozilla/5.0 OcreImmoExport/18.32',
        CURLOPT_HEADER => false,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_RANGE => '0-' . ($maxBytes - 1),
    ]);
    $body = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $mime = curl_getinfo($ch, CURLINFO_CONTENT_TYPE) ?: '';
    curl_close($ch);
    if ($body === false || $code >= 400 || strlen($body) === 0) return null;
    if (strpos($mime, 'image/') !== 0) {
        // Sniff magic bytes si le content-type est menteur (beaucoup de CDN renvoient html).
        $m2 = mimeFromBytes(substr($body, 0, 12));
        if ($m2 === null) return null;
        $mime = $m2;
    }
    return ['body' => $body, 'mime' => $mime];
}

function mimeFromBytes(string $head): ?string {
    if (substr($head, 0, 3) === "\xFF\xD8\xFF") return 'image/jpeg';
    if (substr($head, 0, 8) === "\x89PNG\r\n\x1A\n") return 'image/png';
    if (substr($head, 0, 6) === 'GIF87a' || substr($head, 0, 6) === 'GIF89a') return 'image/gif';
    if (substr($head, 0, 4) === 'RIFF' && substr($head, 8, 4) === 'WEBP') return 'image/webp';
    return null;
}

function extFromMime(string $mime): string {
    switch ($mime) {
        case 'image/jpeg': case 'image/jpg': return 'jpg';
        case 'image/png': return 'png';
        case 'image/gif': return 'gif';
        case 'image/webp': return 'webp';
        default: return 'bin';
    }
}

function buildCounts(array $dossiers): array {
    $mainN = 0; $stagedN = 0; $archivedN = 0; $photosN = 0;
    foreach ($dossiers as $d) {
        if ($d['is_staged']) $stagedN++;
        elseif ($d['archived']) $archivedN++;
        else $mainN++;
        $photosN += count(collectPhotoSources($d['bien'] ?? []));
    }
    return [
        'dossiers' => $mainN,
        'staged' => $stagedN,
        'archived' => $archivedN,
        'photos' => $photosN,
    ];
}

function isStagedFlag($d) { return !empty($d['is_staged']); }
function isArchivedFlag($d) { return !empty($d['archived']); }

$user = requireAuth();
$action = $_GET['action'] ?? ($_POST['action'] ?? '');

switch ($action) {

    case 'preview': {
        $dossiers = fetchDossiers((int) $user['id']);
        $counts = buildCounts($dossiers);
        $sizeEst = estimatePhotoSize($dossiers) + 300 * 1024; // + 300 Ko json + viewer
        jsonOk([
            'counts' => $counts,
            'size_estimate_bytes' => $sizeEst,
        ]);
    }

    case 'generate': {
        @set_time_limit(180);
        @ini_set('memory_limit', '512M');

        $dossiers = fetchDossiers((int) $user['id']);
        $counts = buildCounts($dossiers);

        // Temp ZIP file.
        $tmp = tempnam(sys_get_temp_dir(), 'ocre_export_');
        if (!$tmp) jsonError('Impossible de créer un fichier temp', 500);
        $zipPath = $tmp . '.zip';
        @rename($tmp, $zipPath);

        $zip = new ZipArchive();
        if ($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            jsonError('ZipArchive open KO', 500);
        }

        // 1) Collecter photos (local + external) et les ajouter au ZIP sous photos/{uuid}.ext.
        $photoMap = [];       // original_src => 'photos/xxx.ext'
        $totalBytes = 0;
        $uploadsBase = uploadsBase();
        foreach ($dossiers as $d) {
            $bien = $d['bien'] ?? [];
            $sources = collectPhotoSources($bien);
            foreach ($sources as $src) {
                if (isset($photoMap[$src])) continue;
                if ($totalBytes > MAX_ZIP_BYTES) break 2;

                $data = null; $mime = null;
                // Décider : API proxy interne, URL externe, ou chemin relatif.
                if (strpos($src, '/api/image.php?path=') === 0) {
                    // Proxy interne : extraire path → lire fichier.
                    $q = parse_url($src, PHP_URL_QUERY);
                    parse_str($q ?: '', $qs);
                    $rel = isset($qs['path']) ? ltrim((string) $qs['path'], '/') : '';
                    if ($rel && preg_match('#^users/user_(\d+)/imports/[a-f0-9]{24}\.(jpe?g|png|webp)$#i', $rel)) {
                        $path = $uploadsBase . '/' . $rel;
                        if (is_file($path)) {
                            $data = @file_get_contents($path);
                            $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
                            $mime = $ext === 'jpg' ? 'image/jpeg' : ('image/' . $ext);
                        }
                    }
                } elseif (preg_match('#^https?://#i', $src)) {
                    $r = downloadExternal($src);
                    if ($r) { $data = $r['body']; $mime = $r['mime']; }
                } elseif (strpos($src, '/uploads/') === 0 || strpos($src, 'uploads/') === 0) {
                    $rel = ltrim(preg_replace('#^/+#', '', $src), '/');
                    if (strpos($rel, 'uploads/') === 0) $rel = substr($rel, 8);
                    $path = $uploadsBase . '/' . $rel;
                    if (is_file($path)) {
                        $data = @file_get_contents($path);
                        $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
                        $mime = $ext === 'jpg' ? 'image/jpeg' : ('image/' . $ext);
                    }
                }

                if ($data && $mime) {
                    $uuid = bin2hex(random_bytes(10));
                    $ext = extFromMime($mime);
                    $rel = 'photos/' . $uuid . '.' . $ext;
                    $zip->addFromString($rel, $data);
                    $photoMap[$src] = $rel;
                    $totalBytes += strlen($data);
                }
            }
        }

        // 2) Réécrire d.bien.photos pour pointer vers les chemins relatifs du ZIP.
        switchPhotoRefs($dossiers, $photoMap);

        // 3) Construire data.json.
        $meta = [
            'version' => '18.32',
            'exported_at' => date('c'),
            'source' => [
                'app' => 'Ocre Immo',
                'user_email' => $user['email'] ?? '',
                'user_name' => trim(($user['prenom'] ?? '') . ' ' . ($user['nom'] ?? '')),
            ],
            'counts' => $counts,
            'dossiers' => array_values(array_filter($dossiers, function($d) { return !isStagedFlag($d) && !isArchivedFlag($d); })),
            'staged' => array_values(array_filter($dossiers, 'isStagedFlag')),
            'archived' => array_values(array_filter($dossiers, 'isArchivedFlag')),
        ];
        $zip->addFromString('data.json', json_encode($meta, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT));

        // 4) Templates viewer.
        foreach (['viewer.html', 'viewer.css', 'viewer.js', 'README.txt'] as $file) {
            $src = TEMPLATE_DIR . '/' . $file;
            if (is_file($src)) $zip->addFile($src, $file);
        }

        if (!$zip->close()) jsonError('ZipArchive close KO', 500);

        $sz = filesize($zipPath);
        if ($sz > MAX_ZIP_BYTES) {
            @unlink($zipPath);
            jsonError('Export volumineux (' . round($sz / 1048576) . ' Mo) — limite ' . (MAX_ZIP_BYTES / 1048576) . ' Mo', 413);
        }

        logAction((int) $user['id'], 'export_zip', 'size=' . $sz . ' dossiers=' . $counts['dossiers']);
        $filename = 'ocre-export-' . date('Ymd-Hi') . '.zip';
        header('Content-Type: application/zip');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Content-Length: ' . $sz);
        header('Cache-Control: no-store');
        readfile($zipPath);
        @unlink($zipPath);
        exit;
    }

    default:
        jsonError('action inconnue : ' . $action, 400);
}
