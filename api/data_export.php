<?php
// M/2026/04/28/55 — Export agent : CSV, XLSX (mini pure-PHP), ZIP avec photos+documents.
// Endpoint distinct de /api/export.php (qui sert le PDF).
require_once __DIR__ . '/db.php';
setCorsHeaders();

$user = requireAuth();
$uid = (int) $user['id'];
$format = $_GET['format'] ?? 'csv';

function fetchClientsForUser(int $uid): array {
    $st = db()->prepare("SELECT * FROM clients WHERE user_id = ? AND deleted_at IS NULL ORDER BY created_at DESC");
    $st->execute([$uid]);
    return $st->fetchAll();
}

function flattenClient(array $c): array {
    $data = [];
    if (!empty($c['data'])) {
        try { $data = json_decode($c['data'], true) ?: []; } catch (Throwable $e) { $data = []; }
    }
    $bien = $data['bien'] ?? [];
    return [
        'id' => $c['id'],
        'created_at' => $c['created_at'] ?? '',
        'updated_at' => $c['updated_at'] ?? '',
        'projet' => $c['projet'] ?? '',
        'vertical' => $c['vertical'] ?? '',
        'prenom' => $c['prenom'] ?? '',
        'nom' => $c['nom'] ?? '',
        'societe_nom' => $c['societe_nom'] ?? '',
        'tel' => $c['tel'] ?? '',
        'email' => $c['email'] ?? '',
        'profil_type' => $data['profil_type'] ?? '',
        'pays_residence' => $data['pays_residence'] ?? '',
        'bien_type' => $bien['type'] ?? '',
        'bien_statut' => $bien['statut'] ?? '',
        'bien_pays' => $bien['pays'] ?? '',
        'bien_ville' => $bien['ville'] ?? '',
        'bien_quartier' => $bien['quartier'] ?? '',
        'surface_hab' => $bien['surface_hab'] ?? $bien['surface'] ?? '',
        'surface_terrain' => $bien['surface_terrain_v2'] ?? $bien['terrain'] ?? '',
        'chambres' => $bien['chambres_v2'] ?? $bien['chambres'] ?? '',
        'sdb' => $bien['sdb_v2'] ?? $bien['sdb'] ?? '',
        'annee_construction' => $bien['annee_construction'] ?? '',
        'etat_general' => $bien['etat_general'] ?? '',
        'dpe' => $bien['dpe'] ?? '',
        'ges' => $bien['ges'] ?? '',
        'vue' => is_array($bien['vue'] ?? null) ? implode('|', $bien['vue']) : ($bien['vue'] ?? ''),
        'exposition' => $bien['exposition'] ?? '',
        'espaces_exterieurs' => is_array($bien['espaces_exterieurs'] ?? null) ? implode('|', $bien['espaces_exterieurs']) : '',
        'climatisation_type' => $bien['climatisation_type'] ?? '',
        'chauffage_type' => $bien['chauffage_type'] ?? '',
        'prix_affiche' => $data['prix_affiche'] ?? '',
        'budget_max' => $data['budget_max'] ?? '',
        'loyer_demande' => $data['loyer_demande'] ?? '',
        'loyer_max' => $data['loyer_max'] ?? '',
        'devise' => $data['devise'] ?? '',
        'commission_pct' => $data['commission_pct'] ?? '',
        'archived' => $c['archived'] ?? '',
        'is_draft' => $c['is_draft'] ?? '',
        'is_published' => $c['is_published'] ?? '',
    ];
}

function fetchEvents(int $uid): array {
    try {
        $st = db()->prepare("SELECT e.id, e.client_id, e.type, e.title, e.starts_at, e.status FROM events e JOIN clients c ON c.id = e.client_id WHERE c.user_id = ? ORDER BY e.starts_at DESC");
        $st->execute([$uid]);
        return $st->fetchAll();
    } catch (Throwable $e) { return []; }
}

function fetchDocs(int $uid): array {
    try {
        $st = db()->prepare("SELECT d.id, d.client_id, d.category, d.filename, d.size_bytes, d.created_at FROM documents d JOIN clients c ON c.id = d.client_id WHERE c.user_id = ?");
        $st->execute([$uid]);
        return $st->fetchAll();
    } catch (Throwable $e) { return []; }
}

function csvLine(array $cols): string {
    return implode(';', array_map(fn($v) => '"' . str_replace('"', '""', (string) $v) . '"', $cols));
}

if ($format === 'csv') {
    $clients = fetchClientsForUser($uid);
    $rows = array_map('flattenClient', $clients);
    $headers = $rows ? array_keys($rows[0]) : [];
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="ocre-export-' . date('Ymd-Hi') . '.csv"');
    echo "\xEF\xBB\xBF";
    echo csvLine($headers) . "\r\n";
    foreach ($rows as $r) echo csvLine(array_values($r)) . "\r\n";
    exit;
}

if ($format === 'xlsx') {
    require_once __DIR__ . '/lib/mini_xlsx.php';
    $clients = fetchClientsForUser($uid);
    $rows = array_map('flattenClient', $clients);
    $events = fetchEvents($uid);
    $docs = fetchDocs($uid);
    $sheets = [
        'Dossiers' => array_merge([$rows ? array_keys($rows[0]) : ['id']], array_map('array_values', $rows)),
        'Evenements' => array_merge([['id','client_id','type','title','starts_at','status']], array_map(fn($e) => array_values($e), $events)),
        'Documents' => array_merge([['id','client_id','category','filename','size_bytes','created_at']], array_map(fn($d) => array_values($d), $docs)),
    ];
    $bin = mini_xlsx_build($sheets);
    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment; filename="ocre-export-' . date('Ymd-Hi') . '.xlsx"');
    header('Content-Length: ' . strlen($bin));
    echo $bin;
    exit;
}

if ($format === 'zip') {
    $action = $_GET['action'] ?? 'request';
    $exportDir = '/tmp/ocre-exports';
    if (!is_dir($exportDir)) @mkdir($exportDir, 0700, true);
    if ($action === 'request') {
        $token = bin2hex(random_bytes(16));
        $zipPath = $exportDir . '/' . $token . '.zip';
        $clients = fetchClientsForUser($uid);
        $rows = array_map('flattenClient', $clients);
        $events = fetchEvents($uid);
        $docs = fetchDocs($uid);
        $zip = new ZipArchive();
        if ($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            jsonError('Impossible de créer le ZIP', 500);
        }
        $csvBuf = "\xEF\xBB\xBF" . csvLine($rows ? array_keys($rows[0]) : []) . "\r\n";
        foreach ($rows as $r) $csvBuf .= csvLine(array_values($r)) . "\r\n";
        $zip->addFromString('dossiers.csv', $csvBuf);
        $evBuf = "\xEF\xBB\xBF" . csvLine(['id','client_id','type','title','starts_at','status']) . "\r\n";
        foreach ($events as $e) $evBuf .= csvLine(array_values($e)) . "\r\n";
        $zip->addFromString('evenements.csv', $evBuf);
        $dcBuf = "\xEF\xBB\xBF" . csvLine(['id','client_id','category','filename','size_bytes','created_at']) . "\r\n";
        foreach ($docs as $d) $dcBuf .= csvLine(array_values($d)) . "\r\n";
        $zip->addFromString('documents.csv', $dcBuf);
        $uploadsDir = '/opt/ocre-app/uploads';
        if (is_dir($uploadsDir)) {
            foreach ($clients as $c) {
                $photosDir = $uploadsDir . '/photos/' . $c['id'];
                if (is_dir($photosDir)) {
                    foreach (glob($photosDir . '/*') ?: [] as $f) {
                        if (is_file($f)) $zip->addFile($f, 'photos/' . $c['id'] . '/' . basename($f));
                    }
                }
                $docsDirP = $uploadsDir . '/documents/' . $c['id'];
                if (is_dir($docsDirP)) {
                    foreach (glob($docsDirP . '/*') ?: [] as $f) {
                        if (is_file($f)) $zip->addFile($f, 'documents/' . $c['id'] . '/' . basename($f));
                    }
                }
            }
        }
        $readme = "Export Ocre Immo\nDate : " . date('c') . "\nUser ID : $uid\n\n"
            . "Fichiers :\n- dossiers.csv : 1 ligne par dossier (toutes colonnes Section I/II/III)\n"
            . "- evenements.csv : événements\n- documents.csv : documents\n"
            . "- photos/<id>/ : photos par dossier\n- documents/<id>/ : documents par dossier\n\n"
            . "CSV : encodage UTF-8 BOM, séparateur point-virgule (Excel FR).\n";
        $zip->addFromString('README.txt', $readme);
        $zip->close();
        @file_put_contents($exportDir . '/' . $token . '.meta.json', json_encode([
            'uid' => $uid, 'created_at' => time(), 'expires_at' => time() + 3600,
            'size' => filesize($zipPath),
        ]));
        jsonOk(['token' => $token, 'size' => filesize($zipPath), 'expires_in_seconds' => 3600]);
    }
    if ($action === 'status') {
        $token = $_GET['dl_token'] ?? '';
        if (!preg_match('/^[a-f0-9]{32}$/', $token)) jsonError('dl_token invalide', 400);
        $zipPath = $exportDir . '/' . $token . '.zip';
        if (!is_file($zipPath)) jsonError('export introuvable', 404);
        jsonOk(['ready' => true, 'size' => filesize($zipPath)]);
    }
    if ($action === 'download') {
        $token = $_GET['dl_token'] ?? '';
        if (!preg_match('/^[a-f0-9]{32}$/', $token)) jsonError('dl_token invalide', 400);
        $zipPath = $exportDir . '/' . $token . '.zip';
        $metaPath = $exportDir . '/' . $token . '.meta.json';
        if (!is_file($zipPath) || !is_file($metaPath)) jsonError('export introuvable', 404);
        $meta = json_decode((string) file_get_contents($metaPath), true) ?: [];
        if ((int) ($meta['uid'] ?? 0) !== $uid) jsonError('Accès refusé', 403);
        if (time() > (int) ($meta['expires_at'] ?? 0)) jsonError('lien expiré', 410);
        header('Content-Type: application/zip');
        header('Content-Disposition: attachment; filename="ocre-export-' . date('Ymd-Hi') . '.zip"');
        header('Content-Length: ' . filesize($zipPath));
        readfile($zipPath);
        exit;
    }
    jsonError('Action ZIP inconnue (request|status|download)', 400);
}

jsonError('format invalide (csv|xlsx|zip)', 400);
