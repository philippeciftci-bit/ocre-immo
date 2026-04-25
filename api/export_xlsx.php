<?php
// V51 — Export tabulaire CSV Excel-compatible pour la vue Sheet.
// PhpSpreadsheet n'est pas déployé sur cluster121 OVH, donc fallback CSV UTF-8 BOM
// (lecture native Excel/Numbers/LibreOffice). Extension .csv pour fidélité contenu.
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/_audit.php';

setCorsHeaders();
$user = requireAuth();

$prenom = preg_replace('/[^a-z0-9]+/i', '-', strtolower($user['prenom'] ?? 'agent')) ?: 'agent';
$filename = 'ocre-immo-' . $prenom . '-' . date('Ymd') . '.csv';

// Liste complète (non-archivé, non-staged, non-deleted).
$stmt = db()->prepare(
    "SELECT id, data, projet FROM clients
     WHERE user_id = ? AND is_staged = 0 AND archived = 0 AND deleted_at IS NULL
     ORDER BY updated_at DESC"
);
$stmt->execute([$user['id']]);
$rows = $stmt->fetchAll();

header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Cache-Control: no-store, no-cache, must-revalidate');
header('Pragma: no-cache');

echo "\xEF\xBB\xBF"; // BOM UTF-8 pour Excel Windows

$headers = [
    'Client', 'Profil', 'Tel', 'Email',
    'Type', 'Localisation', 'Surface (m2)', 'Pieces', 'SDB', 'Equipements',
    'Prix demande', 'Devise', 'Periode', 'Frais %', 'Frais montant',
    'Prevu', 'Recu', 'Solde', 'Statut',
    'Etape', 'Score', 'Notes',
];
echo implode(';', array_map('csv_quote', $headers)) . "\r\n";

function csv_quote($s) {
    $s = (string)$s;
    if (preg_match('/[";\r\n]/', $s)) {
        return '"' . str_replace('"', '""', $s) . '"';
    }
    return $s;
}
function fmt_num($n) {
    if ($n === null || $n === '' || !is_numeric($n)) return '';
    return number_format((float)$n, 0, ',', ' ');
}

foreach ($rows as $r) {
    $d = json_decode($r['data'] ?? '{}', true) ?: [];
    $bien = $d['bien'] ?? [];
    $fin = $d['financement'] ?? [];
    $plan = is_array($d['payment_plan'] ?? null) ? $d['payment_plan'] : [];
    $recv = is_array($d['received_payments'] ?? null) ? $d['received_payments'] : [];
    $planTot = 0; $recvTot = 0; $cur = '';
    foreach ($plan as $l) { $planTot += (float)($l['amount'] ?? 0); if (!$cur && !empty($l['currency'])) $cur = $l['currency']; }
    foreach ($recv as $l) { $recvTot += (float)($l['amount'] ?? 0); }
    if (!$cur) $cur = $fin['devise'] ?? 'MAD';
    $solde = max(0, $planTot - $recvTot);
    $statut = ($planTot > 0 && $recvTot >= $planTot) ? 'Solde' : ($recvTot > 0 ? 'Partiel' : 'Devis');
    $row = [
        trim(($d['prenom'] ?? '') . ' ' . ($d['nom'] ?? '')) ?: ($d['societe_nom'] ?? ''),
        $r['projet'] ?? '',
        $d['tel'] ?? '',
        $d['email'] ?? '',
        $bien['type'] ?? '',
        trim(($bien['ville'] ?? '') . ($bien['quartier'] ? ' - ' . $bien['quartier'] : '')),
        $bien['surface'] ?? '',
        $bien['pieces'] ?? $bien['chambres'] ?? '',
        $bien['sdb'] ?? '',
        is_array($bien['equipements'] ?? null) ? implode(', ', $bien['equipements']) : '',
        fmt_num($fin['prix_affiche'] ?? $d['prix_affiche'] ?? $planTot),
        $cur,
        $d['prix_periode'] ?? 'Total',
        $fin['frais_pct'] ?? '',
        fmt_num($fin['frais_montant'] ?? ''),
        fmt_num($planTot),
        fmt_num($recvTot),
        fmt_num($solde),
        $statut,
        $d['etape'] ?? '',
        $d['score'] ?? '',
        str_replace(["\r", "\n"], [' ', ' '], (string)($d['notes'] ?? '')),
    ];
    echo implode(';', array_map('csv_quote', $row)) . "\r\n";
}
