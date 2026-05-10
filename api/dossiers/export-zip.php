<?php
// M_OCRE_V1832_ZIP — POST /api/dossiers/export-zip.php
// Body: {dossier_id, options: {anonymize_client?, hide_price?, include_documents?}}
// Retourne ZIP binary download contenant data.json + photos/ + viewer.html standalone.
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../_session.php';
setCorsHeaders();

$user = requireAuth();
$uid = (int) $user['id'];

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') jsonError('method', 405);
$input = getInput();
$dossierId = (int) ($input['dossier_id'] ?? 0);
if ($dossierId <= 0) jsonError('dossier_id requis', 400);
$opts = is_array($input['options'] ?? null) ? $input['options'] : [];
$anonymize = !empty($opts['anonymize_client']);
$hidePrice = !empty($opts['hide_price']);
$includeDocs = !empty($opts['include_documents']);

// Lecture dossier (multi-tenant strict via user_id)
$st = db()->prepare("SELECT * FROM clients WHERE id = ? AND user_id = ? AND deleted_at IS NULL LIMIT 1");
$st->execute([$dossierId, $uid]);
$row = $st->fetch();
if (!$row) jsonError('Dossier introuvable ou non autorise', 404);

$data = json_decode((string)($row['data'] ?? '{}'), true) ?: [];

// Anonymisation
if ($anonymize) {
    foreach (['prenom','nom','email','tel','telephone','client_email','client_phone','client_nom','client_prenom'] as $k) {
        if (isset($data[$k])) $data[$k] = '[Anonyme]';
    }
}
// Masquage prix
if ($hidePrice) {
    foreach (['prix','prix_affiche','prix_vendeur','budget_min','budget_max','loyer_min','loyer_max','loyer_demande','honoraires','honoraires_fai','honoraires_acquereur'] as $k) {
        if (isset($data[$k])) $data[$k] = 'Sur demande';
    }
}

// Construction ZIP via ZipArchive natif PHP
$tmpZip = tempnam(sys_get_temp_dir(), 'ocrezip_');
$zip = new ZipArchive();
if ($zip->open($tmpZip, ZipArchive::OVERWRITE) !== true) jsonError('Impossible creer ZIP', 500);

// data.json : export structure complete
$dossierExport = [
    'ocre_version' => '18.32',
    'export_date' => date('c'),
    'export_options' => ['anonymize_client' => $anonymize, 'hide_price' => $hidePrice, 'include_documents' => $includeDocs],
    'dossier_id_source' => $dossierId,
    'projet' => $row['projet'] ?? null,
    'is_investisseur' => (int)($row['is_investisseur'] ?? 0),
    'archived' => (int)($row['archived'] ?? 0),
    'data' => $data,
    'payment_plan' => json_decode((string)($row['payment_plan'] ?? 'null'), true),
    'received_payments' => json_decode((string)($row['received_payments'] ?? 'null'), true),
    'photos' => [],
];

// Photos : copier depuis /opt/ocre-app/uploads/<dossier_id>/*.jpg|.webp (skip _thumb.webp pour reduire taille)
$uploadsDir = '/opt/ocre-app/uploads/' . $dossierId;
$photoCount = 0;
if (is_dir($uploadsDir)) {
    $items = scandir($uploadsDir) ?: [];
    foreach ($items as $f) {
        if ($f === '.' || $f === '..' || $f === 'documents' || $f === '.htaccess') continue;
        $full = $uploadsDir . '/' . $f;
        if (!is_file($full)) continue;
        // Skip thumbs et webp (on garde jpg principal pour viewer offline simple)
        if (preg_match('/_thumb\.webp$/', $f) || preg_match('/\.webp$/', $f)) continue;
        if (!preg_match('/\.(jpg|jpeg|png)$/i', $f)) continue;
        $zip->addFile($full, 'photos/' . $f);
        $dossierExport['photos'][] = 'photos/' . $f;
        $photoCount++;
    }
}

// Documents (optionnel) : copier depuis uploads/<id>/documents/
$docCount = 0;
if ($includeDocs) {
    $docsDir = $uploadsDir . '/documents';
    if (is_dir($docsDir)) {
        $items = scandir($docsDir) ?: [];
        $dossierExport['documents'] = [];
        foreach ($items as $f) {
            if ($f === '.' || $f === '..') continue;
            $full = $docsDir . '/' . $f;
            if (!is_file($full)) continue;
            $zip->addFile($full, 'documents/' . $f);
            $dossierExport['documents'][] = 'documents/' . $f;
            $docCount++;
        }
    }
}

$dossierExport['_stats'] = ['photos' => $photoCount, 'documents' => $docCount];

// data.json
$zip->addFromString('data.json', json_encode($dossierExport, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));

// viewer.html : template standalone offline (lecture data.json + photos via fetch local file://)
$viewerTpl = @file_get_contents('/opt/ocre-app/assets/viewer-template.html');
if ($viewerTpl === false) {
    $viewerTpl = '<!DOCTYPE html><html><head><meta charset="UTF-8"><title>Dossier Ocre Immo</title></head><body><h1>Viewer manquant</h1><p>Template assets/viewer-template.html non trouve.</p></body></html>';
}
$zip->addFromString('viewer.html', $viewerTpl);

// README rapide
$readme = "Dossier Ocre Immo — export ZIP autonome\n\n";
$readme .= "Date : " . date('c') . "\n";
$readme .= "Photos : $photoCount · Documents : $docCount\n\n";
$readme .= "Pour ouvrir : double-clique sur viewer.html\n";
$readme .= "Le viewer fonctionne offline (pas de connexion internet requise).\n\n";
$readme .= "Genere par Ocre Immo · https://ocre.immo\n";
$zip->addFromString('README.txt', $readme);

$zip->close();

// Slug filename
$slug = 'dossier-' . $dossierId;
if (!empty($data['nom'])) $slug = preg_replace('/[^a-zA-Z0-9_-]/', '_', strtolower((string)$data['nom']));
$filename = 'ocre-' . $slug . '-' . date('Ymd-His') . '.zip';

$size = filesize($tmpZip);
header('Content-Type: application/zip');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Content-Length: ' . $size);
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
readfile($tmpZip);
@unlink($tmpZip);
exit;
