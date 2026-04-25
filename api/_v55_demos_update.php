<?php
// V55 — one-shot IP-whitelist : enrichir les 7 dossiers démo (user_id=9 test@ocre.immo)
// avec les nouveaux champs Section III pro.
require_once __DIR__ . '/db.php';
$allowed = ['46.225.215.148','127.0.0.1','::1'];
$remote = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? ($_SERVER['REMOTE_ADDR'] ?? '');
$ip = trim(explode(',', $remote)[0]);
if (!in_array($ip, $allowed, true)) { http_response_code(403); exit(json_encode(['ok'=>false,'error'=>'forbidden','seen_ip'=>$ip])); }
header('Content-Type: application/json; charset=utf-8');
$out = ['steps' => []];
try {
    $pdo = db();
    // Récupère user_id de test@ocre.immo
    $st = $pdo->prepare("SELECT id FROM users WHERE email = 'test@ocre.immo' LIMIT 1");
    $st->execute();
    $u = $st->fetch();
    if (!$u) { echo json_encode(['ok'=>false,'error'=>'test user introuvable']); exit; }
    $testUid = (int)$u['id'];

    // Mapping nom → enrichissement V55
    function todayMinusDays($d) { return date('Y-m-d', strtotime("-$d days")); }
    $enrichments = [
        'Lefebvre' => [
            'mode_financement' => 'Crédit', 'apport_personnel' => 400000,
            'pret_montant' => 1400000, 'pret_duree_annees' => 20, 'pret_taux_estime' => 3.85,
            'honoraires_charge' => 'Vendeur', 'frais_notaire_pct' => 7.5,
        ],
        'Bensalem' => [
            'mode_financement' => 'Comptant', 'apport_personnel' => 400000,
            'honoraires_charge' => 'Acheteur', 'honoraires_montant_estime' => 12000,
            'frais_notaire_pct' => 6,
            'strategie_invest' => 'Saisonnier/Airbnb',
            'rendement_brut_cible' => 8.5, 'rendement_net_cible' => 6.0,
            'horizon_annees' => 5, 'regime_fiscal' => 'Auto-entrepreneur',
        ],
        'Moreau' => [
            'mandat_type' => 'Exclusif', 'mandat_duree_mois' => 6,
            'mandat_date_signature' => todayMinusDays(30),
            'mandat_numero' => 'MV-2026-042',
            'commission_mode' => 'Pourcentage', 'commission_taux' => 5.0,
            'commission_charge' => 'Vendeur', 'commission_ttc_ou_ht' => 'TTC',
            'part_agent_pct' => 55,
            'date_disponibilite' => todayMinusDays(-60),
        ],
        'Lavender' => [
            'mandat_type' => 'Simple', 'mandat_duree_mois' => 3,
            'mandat_date_signature' => todayMinusDays(15),
            'commission_mode' => 'Pourcentage', 'commission_taux' => 4.5,
            'commission_charge' => 'Partagés', 'commission_split_vendeur_pct' => 50,
            'commission_ttc_ou_ht' => 'TTC',
            'co_courtage_actif' => false,
            'part_agent_pct' => 50,
        ],
        'Marchetti' => [
            'duree_souhaitee' => 'Bail nu 3 ans', 'date_emmenagement_souhaitee' => todayMinusDays(-90),
            'type_garantie' => 'Visale', 'garants_oui' => false,
            'depot_garantie_capacite' => 3200,
            'honoraires_locataire_estimes' => 400, 'frais_dossier' => 50,
        ],
        'Dupont' => [
            'charges_recuperables' => 150, 'charges_non_recuperables' => 60,
            'depot_garantie_demande' => 4400,
            'honoraires_bailleur_mode' => '% loyer mensuel', 'honoraires_bailleur_taux' => 100,
            'honoraires_locataire_partage_oui' => false,
            'mandat_gestion_actif' => true, 'mandat_gestion_taux' => 7.5,
            'mandat_gestion_frais_relocation' => 800, 'mandat_gestion_duree_annees' => 1,
            'gli_proposee' => true, 'gli_taux' => 2.5,
        ],
        'Ibiza' => [
            'charges_recuperables' => 320, 'charges_non_recuperables' => 180,
            'depot_garantie_demande' => 10800,
            'honoraires_bailleur_mode' => 'Forfait', 'honoraires_bailleur_forfait' => 8000,
            'mandat_gestion_actif' => true, 'mandat_gestion_taux' => 8.0,
            'gli_proposee' => false,
            'prix_acquisition_estime' => 1100000,
            'rendement_brut_actuel' => 5.8, 'rendement_brut_cible' => 7.0,
            'rendement_net_actuel' => 4.2,
            'regime_fiscal_en_cours' => 'SCI à l\'IS',
            'travaux_a_amortir' => 65000,
        ],
    ];

    $stmt = $pdo->prepare("SELECT id, prenom, nom, societe_nom, data FROM clients WHERE user_id = ? AND archived = 0");
    $stmt->execute([$testUid]);
    $rows = $stmt->fetchAll();
    $upd = $pdo->prepare("UPDATE clients SET data = ?, updated_at = NOW() WHERE id = ?");
    $updated = [];
    foreach ($rows as $r) {
        $name = trim(($r['prenom'] ?? '') . ' ' . ($r['nom'] ?? '')) ?: ($r['societe_nom'] ?? '');
        $key = null;
        foreach ($enrichments as $k => $_) {
            if (stripos($name, $k) !== false) { $key = $k; break; }
        }
        if (!$key) { $out['steps'][] = "skip id={$r['id']} ($name) — no match"; continue; }
        $d = json_decode($r['data'] ?? '{}', true) ?: [];
        foreach ($enrichments[$key] as $field => $v) {
            $d[$field] = $v;
        }
        $newData = json_encode($d, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $upd->execute([$newData, (int)$r['id']]);
        $updated[] = ['id' => (int)$r['id'], 'name' => $name, 'fields_added' => count($enrichments[$key])];
    }
    $out['ok'] = true;
    $out['test_user_id'] = $testUid;
    $out['updated'] = $updated;
    $out['count'] = count($updated);
} catch (Throwable $e) {
    $out['ok'] = false;
    $out['error'] = $e->getMessage();
}
echo json_encode($out, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
