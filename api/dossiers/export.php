<?php
// M111 — POST /api/dossiers/export.php
// Body : {format: 'csv'|'xlsx', filters: {date_from, date_to, status, profil}}
// Retourne fichier en download (Content-Disposition: attachment).

require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../_session.php';

$user = getCurrentUserDualMode();
if (!$user || !empty($user['_no_tenant_user']) || !empty($user['_tenant_mismatch'])) {
    http_response_code(401);
    header('Content-Type: application/json');
    echo json_encode(['ok' => false, 'error' => 'Non authentifie']);
    exit;
}
$tenant = $user['slug'];
if (!$tenant) {
    http_response_code(400);
    header('Content-Type: application/json');
    echo json_encode(['ok' => false, 'error' => 'Tenant requis']);
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    http_response_code(405);
    exit('method');
}

$raw = file_get_contents('php://input');
$body = json_decode($raw, true) ?: [];
$format = strtolower($body['format'] ?? 'csv');
if (!in_array($format, ['csv', 'xlsx'])) $format = 'csv';
$filters = is_array($body['filters'] ?? null) ? $body['filters'] : [];

$tenantDb = 'ocre_wsp_' . $tenant;
try {
    $pdo = new PDO(
        'mysql:host=' . DB_HOST . ';dbname=' . $tenantDb . ';charset=utf8mb4',
        DB_USER, DB_PASS,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
    );
} catch (Throwable $e) {
    http_response_code(500);
    header('Content-Type: application/json');
    echo json_encode(['ok' => false, 'error' => 'DB tenant error']);
    exit;
}

// Build query avec filtres
$where = [];
$args = [];
if (!empty($filters['date_from'])) { $where[] = 'created_at >= ?'; $args[] = $filters['date_from']; }
if (!empty($filters['date_to'])) { $where[] = 'created_at <= ?'; $args[] = $filters['date_to'] . ' 23:59:59'; }
if (!empty($filters['status']) && is_array($filters['status'])) {
    $ph = implode(',', array_fill(0, count($filters['status']), '?'));
    $where[] = "statut IN ($ph)";
    foreach ($filters['status'] as $s) $args[] = $s;
}
if (!empty($filters['profil']) && is_array($filters['profil'])) {
    $ph = implode(',', array_fill(0, count($filters['profil']), '?'));
    $where[] = "projet IN ($ph)";
    foreach ($filters['profil'] as $p) $args[] = $p;
}
$whereSql = $where ? 'WHERE ' . implode(' AND ', $where) : '';

$sql = "SELECT id, projet, statut, is_draft, prenom, nom, email, tel,
               type_bien, ville, cp, country_code, surface_m2, nb_pieces, bedrooms, bathrooms,
               budget_max, currency, notes, created_at, updated_at
        FROM clients
        $whereSql
        ORDER BY created_at DESC
        LIMIT 5000";

try {
    $st = $pdo->prepare($sql);
    $st->execute($args);
    $rows = $st->fetchAll();
} catch (Throwable $e) {
    // Si colonne manque (e.g. bedrooms) sur tenant ancien : fallback minimal
    $st = $pdo->prepare("SELECT id, projet, statut, is_draft, prenom, nom, email, tel, created_at, updated_at FROM clients $whereSql ORDER BY created_at DESC LIMIT 5000");
    $st->execute($args);
    $rows = $st->fetchAll();
}

// Anonymisation drafts (RGPD-friendly)
foreach ($rows as &$r) {
    if (!empty($r['is_draft'])) {
        $r['nom'] = $r['nom'] ? '[BROUILLON]' : null;
        $r['prenom'] = $r['prenom'] ? '[BROUILLON]' : null;
        $r['email'] = $r['email'] ? '[BROUILLON]' : null;
        $r['tel'] = $r['tel'] ? '[BROUILLON]' : null;
    }
}
unset($r);

$headers = ['ID', 'Profil', 'Statut', 'Brouillon', 'Prénom', 'Nom', 'Email', 'Téléphone',
            'Type bien', 'Ville', 'CP', 'Pays', 'Surface m²', 'Nb pièces', 'Chambres', 'SdB',
            'Budget max', 'Devise', 'Notes', 'Créé le', 'Modifié le'];
$rowKeys = ['id','projet','statut','is_draft','prenom','nom','email','tel',
            'type_bien','ville','cp','country_code','surface_m2','nb_pieces','bedrooms','bathrooms',
            'budget_max','currency','notes','created_at','updated_at'];

$filename = 'ocre-dossiers-' . $tenant . '-' . date('Ymd-His');

if ($format === 'csv') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '.csv"');
    echo "\xEF\xBB\xBF"; // UTF-8 BOM (Excel friendly)
    $out = fopen('php://output', 'w');
    fputcsv($out, $headers, ';', '"');
    foreach ($rows as $r) {
        $line = [];
        foreach ($rowKeys as $k) {
            $v = $r[$k] ?? '';
            if ($k === 'is_draft') $v = $v ? 'Oui' : 'Non';
            $line[] = (string) $v;
        }
        fputcsv($out, $line, ';', '"');
    }
    fclose($out);
    exit;
}

// XLSX : zip + XML brut Office Open XML.
function xlsx_esc($v) {
    return htmlspecialchars((string) $v, ENT_XML1 | ENT_QUOTES, 'UTF-8');
}

function xlsx_col($i) {
    // 1 → A, 27 → AA
    $s = '';
    while ($i > 0) {
        $i--;
        $s = chr(65 + ($i % 26)) . $s;
        $i = intdiv($i, 26);
    }
    return $s;
}

$tmpZip = tempnam(sys_get_temp_dir(), 'xlsx');
$zip = new ZipArchive();
$zip->open($tmpZip, ZipArchive::OVERWRITE);

$zip->addFromString('[Content_Types].xml',
    '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
    . '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
    . '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
    . '<Default Extension="xml" ContentType="application/xml"/>'
    . '<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
    . '<Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>'
    . '<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>'
    . '</Types>');

$zip->addFromString('_rels/.rels',
    '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
    . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
    . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>'
    . '</Relationships>');

$zip->addFromString('xl/_rels/workbook.xml.rels',
    '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
    . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
    . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/>'
    . '<Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>'
    . '</Relationships>');

$zip->addFromString('xl/workbook.xml',
    '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
    . '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
    . '<sheets><sheet name="Dossiers" sheetId="1" r:id="rId1"/></sheets>'
    . '</workbook>');

// Styles : header bold (s=1)
$zip->addFromString('xl/styles.xml',
    '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
    . '<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
    . '<fonts count="2"><font><sz val="11"/><name val="Calibri"/></font><font><b/><sz val="11"/><name val="Calibri"/><color rgb="FFFFFFFF"/></font></fonts>'
    . '<fills count="3"><fill><patternFill patternType="none"/></fill><fill><patternFill patternType="gray125"/></fill><fill><patternFill patternType="solid"><fgColor rgb="FF8B6F3A"/></patternFill></fill></fills>'
    . '<borders count="1"><border/></borders>'
    . '<cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>'
    . '<cellXfs count="2"><xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/><xf numFmtId="0" fontId="1" fillId="2" borderId="0" xfId="0" applyFont="1" applyFill="1"/></cellXfs>'
    . '</styleSheet>');

$nbCols = count($headers);
$lastCol = xlsx_col($nbCols);
$lastRow = count($rows) + 1;

$sheet = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
    . '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
    . '<sheetViews><sheetView tabSelected="1" workbookViewId="0"><pane ySplit="1" topLeftCell="A2" activePane="bottomLeft" state="frozen"/></sheetView></sheetViews>'
    . '<cols>';
foreach ($headers as $i => $h) {
    $sheet .= '<col min="' . ($i + 1) . '" max="' . ($i + 1) . '" width="18" customWidth="1"/>';
}
$sheet .= '</cols><sheetData>';

// Header row (style 1 = bold + ocre bg)
$sheet .= '<row r="1">';
foreach ($headers as $i => $h) {
    $sheet .= '<c r="' . xlsx_col($i + 1) . '1" s="1" t="inlineStr"><is><t>' . xlsx_esc($h) . '</t></is></c>';
}
$sheet .= '</row>';

foreach ($rows as $rowIdx => $r) {
    $rowNum = $rowIdx + 2;
    $sheet .= '<row r="' . $rowNum . '">';
    foreach ($rowKeys as $i => $k) {
        $v = $r[$k] ?? '';
        if ($k === 'is_draft') $v = $v ? 'Oui' : 'Non';
        $col = xlsx_col($i + 1);
        // Numerique pour budget_max + surface_m2 + nb_pieces + bedrooms + bathrooms
        if (in_array($k, ['budget_max', 'surface_m2', 'nb_pieces', 'bedrooms', 'bathrooms']) && is_numeric($v)) {
            $sheet .= '<c r="' . $col . $rowNum . '"><v>' . xlsx_esc($v) . '</v></c>';
        } else {
            $sheet .= '<c r="' . $col . $rowNum . '" t="inlineStr"><is><t>' . xlsx_esc($v) . '</t></is></c>';
        }
    }
    $sheet .= '</row>';
}
$sheet .= '</sheetData>';
// Auto-filter
$sheet .= '<autoFilter ref="A1:' . $lastCol . $lastRow . '"/>';
$sheet .= '</worksheet>';

$zip->addFromString('xl/worksheets/sheet1.xml', $sheet);
$zip->close();

header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
header('Content-Disposition: attachment; filename="' . $filename . '.xlsx"');
header('Content-Length: ' . filesize($tmpZip));
readfile($tmpZip);
@unlink($tmpZip);
exit;
