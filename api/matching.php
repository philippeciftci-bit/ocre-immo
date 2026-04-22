<?php
// V17.11 — moteur matching interne : Acheteur↔Vendeur, Locataire↔Bailleur, Investisseur→Vendeurs.
// Scoring 0-100. Auth user (owner).
require_once __DIR__ . '/db.php';
setCorsHeaders();

$user = requireAuth();
$action = $_GET['action'] ?? ($_POST['action'] ?? '');

const OPP_PROFIL = [
    'Acheteur'     => ['Vendeur'],
    'Investisseur' => ['Vendeur'],
    'Vendeur'      => ['Acheteur', 'Investisseur'],
    'Locataire'    => ['Bailleur'],
    'Bailleur'     => ['Locataire'],
];

function numOr0($x) { $n = (float)$x; return is_finite($n) ? $n : 0; }

function extractSurface($bien) {
    if (!empty($bien['surface'])) return (float)$bien['surface'];
    $mn = (float)($bien['surface_min'] ?? 0);
    $mx = (float)($bien['surface_max'] ?? 0);
    if ($mn && $mx) return ($mn + $mx) / 2;
    if ($mn) return $mn;
    if ($mx) return $mx;
    if (!empty($bien['habitable_totale'])) return (float)$bien['habitable_totale'];
    return 0;
}
function extractBudgetMax($data) {
    $f = $data['financement'] ?? [];
    if (is_array($f) && !empty($f['budget_total'])) return (float)$f['budget_total'];
    if (!empty($data['budget_max'])) return (float)$data['budget_max'];
    if (!empty($data['budget_min'])) return (float)$data['budget_min'];
    return 0;
}
function extractPrix($profil, $data) {
    if ($profil === 'Vendeur') return (float)($data['prix_affiche'] ?? 0);
    if ($profil === 'Bailleur') return (float)($data['loyer_demande'] ?? 0);
    return 0;
}

function scoreMatch($srcRow, $candidateRow) {
    $src_data = json_decode($srcRow['data'] ?? '{}', true) ?: [];
    $c_data = json_decode($candidateRow['data'] ?? '{}', true) ?: [];
    $sb = $src_data['bien'] ?? [];
    $cb = $c_data['bien'] ?? [];
    // Pré-requis : même pays
    $src_pays = $sb['pays'] ?? '';
    $c_pays = $cb['pays'] ?? '';
    if (!$src_pays || $src_pays !== $c_pays) return null;
    // Types intersection non vide
    $src_types = (isset($sb['types']) && is_array($sb['types'])) ? $sb['types'] : (!empty($sb['type']) ? [$sb['type']] : []);
    $c_types = (isset($cb['types']) && is_array($cb['types'])) ? $cb['types'] : (!empty($cb['type']) ? [$cb['type']] : []);
    $inter = array_values(array_intersect($src_types, $c_types));
    if (!$inter) return null;
    // Ville (match exact requis, case-insensitive)
    $src_ville = mb_strtolower(trim($sb['ville'] ?? ''));
    $c_ville = mb_strtolower(trim($cb['ville'] ?? ''));
    if ($src_ville && $c_ville && $src_ville !== $c_ville) return null;
    // Base 50 : pays + ville + type OK
    $score = 50;
    $reasons = ['Même pays', 'Type ' . implode('/', $inter)];
    if ($src_ville && $c_ville) $reasons[] = 'Même ville';
    // +15 si tous les types demandés matchent (côté src)
    if (count($src_types) && count($inter) === count($src_types)) {
        $score += 15;
        $reasons[] = 'Tous types recherchés matchent';
    }
    // Budget / prix (acheteur/investisseur vs vendeur ; locataire vs bailleur)
    $src_p = $srcRow['projet'] ?? '';
    $c_p = $candidateRow['projet'] ?? '';
    $is_src_buyer = in_array($src_p, ['Acheteur','Investisseur'], true);
    $is_c_seller = $c_p === 'Vendeur';
    $is_src_seller = $src_p === 'Vendeur';
    $is_c_buyer = in_array($c_p, ['Acheteur','Investisseur'], true);
    $budget = 0; $prix = 0;
    if ($is_src_buyer && $is_c_seller) { $budget = extractBudgetMax($src_data); $prix = extractPrix('Vendeur', $c_data); }
    if ($is_src_seller && $is_c_buyer) { $budget = extractBudgetMax($c_data); $prix = extractPrix('Vendeur', $src_data); }
    if ($budget && $prix) {
        $min_ok = $prix * 0.90;
        $max_ok = $budget * 1.05;
        if ($budget >= $min_ok && $prix <= $max_ok) {
            $score += 20;
            $reasons[] = 'Budget compatible';
        }
    }
    // Loyer (Locataire ↔ Bailleur)
    if ($src_p === 'Locataire' && $c_p === 'Bailleur') {
        $loyer_max = (float)($src_data['loyer_max'] ?? 0);
        $loyer_dem = (float)($c_data['loyer_demande'] ?? 0);
        if ($loyer_max && $loyer_dem && $loyer_dem <= $loyer_max) { $score += 20; $reasons[] = 'Loyer dans budget'; }
    }
    if ($src_p === 'Bailleur' && $c_p === 'Locataire') {
        $loyer_dem = (float)($src_data['loyer_demande'] ?? 0);
        $loyer_max = (float)($c_data['loyer_max'] ?? 0);
        if ($loyer_max && $loyer_dem && $loyer_dem <= $loyer_max) { $score += 20; $reasons[] = 'Loyer dans budget'; }
    }
    // Quartier
    $src_q = mb_strtolower(trim($sb['quartier'] ?? ''));
    $c_q = mb_strtolower(trim($cb['quartier'] ?? ''));
    if ($src_q && $c_q && $src_q === $c_q) { $score += 10; $reasons[] = 'Même quartier'; }
    // Surface ±20%
    $ss = extractSurface($sb); $cs = extractSurface($cb);
    if ($ss > 0 && $cs > 0) {
        $diff = abs($ss - $cs) / max($ss, $cs);
        if ($diff <= 0.20) { $score += 5; $reasons[] = 'Surface compatible'; }
    }
    return ['score' => min(100, $score), 'reasons' => $reasons];
}

function candidatesForProfil($user_id, $profil) {
    $opp_list = OPP_PROFIL[$profil] ?? [];
    if (!$opp_list) return [];
    $placeholders = implode(',', array_fill(0, count($opp_list), '?'));
    $stmt = db()->prepare(
        "SELECT id, projet, data, prenom, nom, societe_nom, updated_at
         FROM clients
         WHERE user_id = ? AND archived = 0 AND projet IN ($placeholders)
         ORDER BY updated_at DESC
         LIMIT 500"
    );
    $stmt->execute(array_merge([$user_id], $opp_list));
    return $stmt->fetchAll();
}

switch ($action) {
    case 'find_matches': {
        $client_id = (int)($_GET['client_id'] ?? 0);
        if (!$client_id) jsonError('client_id requis');
        $stmt = db()->prepare("SELECT * FROM clients WHERE id = ? AND user_id = ? LIMIT 1");
        $stmt->execute([$client_id, $user['id']]);
        $src = $stmt->fetch();
        if (!$src) jsonError('Introuvable', 404);
        $candidates = candidatesForProfil((int)$user['id'], $src['projet'] ?? '');
        $out = [];
        foreach ($candidates as $c) {
            if ((int)$c['id'] === $client_id) continue;
            $r = scoreMatch($src, $c);
            if ($r !== null && $r['score'] >= 50) {
                $c_data = json_decode($c['data'] ?? '{}', true) ?: [];
                $types = (isset($c_data['bien']['types']) && is_array($c_data['bien']['types']))
                    ? $c_data['bien']['types']
                    : (!empty($c_data['bien']['type']) ? [$c_data['bien']['type']] : []);
                $out[] = [
                    'client_id' => (int)$c['id'],
                    'score' => $r['score'],
                    'reasons' => $r['reasons'],
                    'summary' => [
                        'prenom' => $c['prenom'],
                        'nom' => $c['nom'],
                        'societe_nom' => $c['societe_nom'],
                        'profil' => $c['projet'],
                        'type' => implode(' · ', $types),
                        'ville' => $c_data['bien']['ville'] ?? '',
                        'quartier' => $c_data['bien']['quartier'] ?? '',
                        'pays' => $c_data['bien']['pays'] ?? '',
                        'prix' => extractPrix($c['projet'] ?? '', $c_data),
                    ],
                ];
            }
        }
        usort($out, fn($a, $b) => $b['score'] - $a['score']);
        jsonOk(['matches' => array_slice($out, 0, 10)]);
    }

    case 'find_all_matches': {
        // Map client_id → {count, max_score}. Calcul one-shot sur tous les dossiers du user.
        $stmt = db()->prepare(
            "SELECT id, projet, data, prenom, nom, societe_nom FROM clients
             WHERE user_id = ? AND archived = 0
             LIMIT 500"
        );
        $stmt->execute([$user['id']]);
        $all = $stmt->fetchAll();
        // Indexer par profil pour éviter de re-scanner
        $by_profil = [];
        foreach ($all as $row) $by_profil[$row['projet'] ?? ''][] = $row;
        $out = [];
        foreach ($all as $src) {
            $opp_list = OPP_PROFIL[$src['projet'] ?? ''] ?? [];
            if (!$opp_list) { $out[(int)$src['id']] = ['count' => 0, 'max_score' => 0]; continue; }
            $count = 0; $max_score = 0;
            foreach ($opp_list as $opp_p) {
                foreach ($by_profil[$opp_p] ?? [] as $c) {
                    if ((int)$c['id'] === (int)$src['id']) continue;
                    $r = scoreMatch($src, $c);
                    if ($r !== null && $r['score'] >= 50) { $count++; if ($r['score'] > $max_score) $max_score = $r['score']; }
                }
            }
            if ($count > 0) $out[(int)$src['id']] = ['count' => $count, 'max_score' => $max_score];
        }
        jsonOk(['matches_by_id' => $out]);
    }

    default:
        jsonError('Action inconnue', 404);
}
