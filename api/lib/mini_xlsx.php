<?php
// M/2026/04/28/55 — Mini XLSX writer pure-PHP. Pas de dépendance composer.
// Génère un .xlsx valide avec N feuilles. Strings inline, pas de styles avancés.

if (!function_exists('mini_xlsx_build')) {

function mini_xlsx_col(int $n): string {
    $s = '';
    $n++;
    while ($n > 0) {
        $r = ($n - 1) % 26;
        $s = chr(65 + $r) . $s;
        $n = intdiv($n - 1, 26);
    }
    return $s;
}

function mini_xlsx_xml_escape(string $s): string {
    return htmlspecialchars($s, ENT_XML1 | ENT_QUOTES, 'UTF-8');
}

function mini_xlsx_sheet_xml(array $rows): string {
    $xml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>';
    $xml .= '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetData>';
    foreach ($rows as $r => $row) {
        $rn = $r + 1;
        $xml .= '<row r="' . $rn . '">';
        foreach ($row as $c => $val) {
            $cell = mini_xlsx_col($c) . $rn;
            if ($val === null || $val === '') {
                continue;
            }
            if (is_numeric($val) && !preg_match('/^0\d/', (string) $val)) {
                $xml .= '<c r="' . $cell . '"><v>' . $val . '</v></c>';
            } else {
                $xml .= '<c r="' . $cell . '" t="inlineStr"><is><t xml:space="preserve">' . mini_xlsx_xml_escape((string) $val) . '</t></is></c>';
            }
        }
        $xml .= '</row>';
    }
    $xml .= '</sheetData></worksheet>';
    return $xml;
}

function mini_xlsx_build(array $sheets): string {
    $tmp = tempnam(sys_get_temp_dir(), 'xlsx_');
    $zip = new ZipArchive();
    if ($zip->open($tmp, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
        @unlink($tmp);
        throw new RuntimeException('mini_xlsx open failed');
    }
    $names = array_keys($sheets);

    $contentTypes = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
        . '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
        . '<Default Extension="xml" ContentType="application/xml"/>'
        . '<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>';
    foreach ($names as $i => $_) {
        $contentTypes .= '<Override PartName="/xl/worksheets/sheet' . ($i + 1) . '.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>';
    }
    $contentTypes .= '</Types>';
    $zip->addFromString('[Content_Types].xml', $contentTypes);

    $rels = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
        . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>'
        . '</Relationships>';
    $zip->addFromString('_rels/.rels', $rels);

    $wbRels = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">';
    $wb = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" '
        . 'xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets>';
    foreach ($names as $i => $name) {
        $sheetId = $i + 1;
        $rId = 'rIdS' . $sheetId;
        $safeName = preg_replace('/[^A-Za-z0-9 _-]/', '_', $name) ?: ('Sheet' . $sheetId);
        $safeName = mb_substr($safeName, 0, 31);
        $wb .= '<sheet name="' . mini_xlsx_xml_escape($safeName) . '" sheetId="' . $sheetId . '" r:id="' . $rId . '"/>';
        $wbRels .= '<Relationship Id="' . $rId . '" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet' . $sheetId . '.xml"/>';
    }
    $wb .= '</sheets></workbook>';
    $wbRels .= '</Relationships>';
    $zip->addFromString('xl/workbook.xml', $wb);
    $zip->addFromString('xl/_rels/workbook.xml.rels', $wbRels);

    foreach (array_values($sheets) as $i => $rows) {
        $zip->addFromString('xl/worksheets/sheet' . ($i + 1) . '.xml', mini_xlsx_sheet_xml($rows));
    }

    $zip->close();
    $bin = file_get_contents($tmp);
    @unlink($tmp);
    return $bin;
}

}
