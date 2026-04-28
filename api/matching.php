<?php
// M/2026/04/28/30 — Algo de matching réel + endpoint /api/matching.php.
// Calcule un score 0..100 par paire de dossiers du tenant courant selon les
// règles globales (ocre_meta.match_rules_v1) + préférences agent (match_preferences).
// Remplace l'ancien moteur V17.11 (suppression franche).
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/lib/router.php';
setCorsHeaders();

$user = requireAuth();
$action = $_GET['action'] ?? ($_POST['action'] ?? '');
$input = getInput();
$uid = (int) $user['id'];

function ensureMatchRulesV1(): array {
    static $cached = null;
    if ($cached !== null) return $cached;
    pdo_meta()->exec("CREATE TABLE IF NOT EXISTS match_rules_v1 (
        id INT NOT NULL PRIMARY KEY,
        weights LONGTEXT NOT NULL,
        hard_requirements LONGTEXT NOT NULL,
        geo_mode ENUM('strict','souple','tres_souple') NOT NULL DEFAULT 'tres_souple',
        tolerances_default LONGTEXT NOT NULL,
        cross_profile_pairs LONGTEXT NOT NULL,
        seuil_min_pct_default INT NOT NULL DEFAULT 70,
        version VARCHAR(16) NOT NULL DEFAULT 'v1',
        updated_by_user_id INT NULL,
        updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
    $defaults = [
        'weights' => ['pays' => 5, 'ville' => 15, 'quartier' => 10, 'type_bien' => 25,
                      'surface_hab' => 10, 'surface_terrain' => 5, 'chambres' => 8,
                      'budget' => 15, 'equipements' => 5, 'etat' => 2],
        'hard_requirements' => ['pays' => true, 'type_bien' => true],
        'tolerances_default' => ['budget_pct' => 10, 'surface_hab_pct' => 25,
                                  'surface_terrain_pct' => 50, 'chambres' => 1],
        'cross_profile_pairs' => [['Acheteur', 'Vendeur'], ['Locataire', 'Bailleur'],
                                    ['Investisseur', 'Vendeur'], ['Investisseur', 'Bailleur']],
    ];
    $stmt = pdo_meta()->prepare(
        "INSERT IGNORE INTO match_rules_v1 (id, weights, hard_requirements, geo_mode,
            tolerances_default, cross_profile_pairs, seuil_min_pct_default, version)
         VALUES (1, ?, ?, 'tres_souple', ?, ?, 70, 'v1')"
    );
    $stmt->execute([
        json_encode($defaults['weights'], JSON_UNESCAPED_UNICODE),
        json_encode($defaults['hard_requirements'], JSON_UNESCAPED_UNICODE),
        json_encode($defaults['tolerances_default'], JSON_UNESCAPED_UNICODE),
        json_encode($defaults['cross_profile_pairs'], JSON_UNESCAPED_UNICODE),
    ]);
    $row = pdo_meta()->query("SELECT * FROM match_rules_v1 WHERE id = 1")->fetch();
    foreach (['weights', 'hard_requirements', 'tolerances_default', 'cross_profile_pairs'] as $k) {
        $row[$k] = json_decode($row[$k], true) ?: [];
    }
    $cached = $row;
    return $row;
}

function profilCompat(string $a, string $b, array $pairs): bool {
    foreach ($pairs as $p) {
        if (count($p) !== 2) continue;
        if (($p[0] === $a && $p[1] === $b) || ($p[0] === $b && $p[1] === $a)) return true;
    }
    return false;
}

function clientBien(array $c): array {
    $d = is_string($c['data'] ?? null) ? (json_decode($c['data'], true) ?: []) : ($c['data'] ?? []);
    return [
        'projet' => $c['projet'] ?? ($d['projet'] ?? null),
        'pays' => $d['bien']['pays'] ?? null,
        'ville' => $d['bien']['ville'] ?? null,
        'quartier' => $d['bien']['quartier'] ?? null,
        'type_bien' => $d['bien']['type'] ?? (isset($d['bien']['types'][0]) ? $d['bien']['types'][0] : null),
        'types' => $d['bien']['types'] ?? null,
        'surface' => $d['bien']['surface'] ?? null,
        'surface_terrain' => $d['bien']['surface_terrain'] ?? null,
        'chambres' => $d['bien']['chambres'] ?? null,
        'budget_max' => $d['budget_max'] ?? null,
        'prix_affiche' => $d['prix_affiche'] ?? null,
        'loyer_max' => $d['loyer_max'] ?? null,
        'loyer_demande' => $d['loyer_demande'] ?? null,
        'equipements' => $d['bien']['equipements'] ?? [],
        'etat' => $d['bien']['etat'] ?? null,
    ];
}

function _budget_buyer(array $b): ?float { return $b['budget_max'] ?? $b['loyer_max'] ?? null; }
function _budget_seller(array $b): ?float { return $b['prix_affiche'] ?? $b['loyer_demande'] ?? null; }

function _fmt_money(float $n): string {
    if ($n >= 1000000) return number_format($n / 1000000, 1, ',', ' ') . ' M';
    return number_format($n, 0, ',', ' ');
}

function scoreCritere(string $cri, array $a, array $b, array $rules): ?array {
    $tol = $rules['tolerances_default'];
    switch ($cri) {
      case 'pays':
        if (!$a['pays'] || !$b['pays']) return null;
        return [$a['pays'] === $b['pays'] ? 1.0 : 0.0,
                $a['pays'] === $b['pays'] ? "Pays {$a['pays']}" : "Pays {$a['pays']} ≠ {$b['pays']}"];
      case 'ville':
        if (!$a['ville'] || !$b['ville']) return null;
        if ($a['ville'] === $b['ville']) return [1.0, "Ville {$a['ville']}"];
        return [0.0, "Ville {$a['ville']} ≠ {$b['ville']}"];
      case 'quartier':
        if (!$a['quartier'] || !$b['quartier']) return null;
        if ($a['quartier'] === $b['quartier']) return [1.0, "Quartier {$a['quartier']}"];
        if ($a['ville'] && $a['ville'] === $b['ville']) return [0.5, "Quartier voisin ({$a['quartier']} ≈ {$b['quartier']})"];
        return [0.0, "Quartier {$a['quartier']} ≠ {$b['quartier']}"];
      case 'type_bien':
        if (!$a['type_bien'] || !$b['type_bien']) return null;
        $atypes = is_array($a['types']) && $a['types'] ? $a['types'] : [$a['type_bien']];
        $btypes = is_array($b['types']) && $b['types'] ? $b['types'] : [$b['type_bien']];
        $shared = array_intersect($atypes, $btypes);
        if ($shared) return [1.0, "Type " . reset($shared)];
        return [0.0, "Type {$a['type_bien']} ≠ {$b['type_bien']}"];
      case 'surface_hab':
        if (!$a['surface'] || !$b['surface']) return null;
        $diffPct = abs($a['surface'] - $b['surface']) / max($a['surface'], $b['surface']) * 100;
        $score = max(0.0, 1.0 - $diffPct / max(1, (float) $tol['surface_hab_pct']));
        $score = min(1.0, $score);
        $lab = "Surface {$b['surface']} m² " . ($score >= 0.85 ? '≈' : '≠') . " {$a['surface']} m²";
        return [$score, $lab];
      case 'surface_terrain':
        if (!$a['surface_terrain'] || !$b['surface_terrain']) return null;
        $diffPct = abs($a['surface_terrain'] - $b['surface_terrain']) / max($a['surface_terrain'], $b['surface_terrain']) * 100;
        $score = max(0.0, 1.0 - $diffPct / max(1, (float) $tol['surface_terrain_pct']));
        $score = min(1.0, $score);
        $lab = "Terrain " . number_format($b['surface_terrain'], 0, ',', ' ') . " m² " . ($score >= 0.85 ? '≈' : '≠') . " " . number_format($a['surface_terrain'], 0, ',', ' ') . " m²";
        return [$score, $lab];
      case 'chambres':
        if ($a['chambres'] === null || $b['chambres'] === null) return null;
        $diff = abs((int) $a['chambres'] - (int) $b['chambres']);
        $tolCh = max(1, (int) $tol['chambres']);
        if ($diff === 0) return [1.0, "{$a['chambres']} chambres"];
        if ($diff <= $tolCh) return [max(0.0, 1.0 - $diff / ($tolCh + 1)), "{$b['chambres']} ch ≈ {$a['chambres']} ch"];
        return [0.0, "{$b['chambres']} ch ≠ {$a['chambres']} ch"];
      case 'budget':
        $buyerBud = _budget_buyer($a) ?: _budget_buyer($b);
        $sellerPr = _budget_seller($a) ?: _budget_seller($b);
        if (!$buyerBud || !$sellerPr) return null;
        $diffPct = abs($buyerBud - $sellerPr) / max($buyerBud, $sellerPr) * 100;
        $score = max(0.0, 1.0 - $diffPct / max(1, (float) $tol['budget_pct'] * 2));
        $score = min(1.0, $score);
        $lab = $score >= 0.85
            ? "Budget " . _fmt_money($sellerPr) . " ≈ " . _fmt_money($buyerBud) . " MAD"
            : "Budget " . _fmt_money($sellerPr) . " ≠ " . _fmt_money($buyerBud) . " MAD (" . round($diffPct) . " %)";
        return [$score, $lab];
      case 'equipements':
        $ea = is_array($a['equipements']) ? array_keys($a['equipements']) : [];
        $eb = is_array($b['equipements']) ? array_keys($b['equipements']) : [];
        if (!$ea && !$eb) return null;
        $shared = count(array_intersect($ea, $eb));
        $total = max(count($ea), count($eb));
        if ($total === 0) return null;
        return [$shared / $total, $shared . ' équipement(s) en commun'];
      case 'etat':
        if (!$a['etat'] || !$b['etat']) return null;
        $aa = mb_strtolower($a['etat']); $bb = mb_strtolower($b['etat']);
        $compat = (strpos($aa, 'récent') !== false || strpos($aa, 'neuf') !== false)
                  && (strpos($bb, 'récent') !== false || strpos($bb, 'neuf') !== false);
        return [$compat ? 1.0 : 0.0, $compat ? "État compatible (récent/neuf)" : "État divergent"];
    }
    return null;
}

function scorePair(array $clientA, array $clientB, array $rules): array {
    $a = clientBien($clientA); $b = clientBien($clientB);

    // Hard filter cross_profile_pairs.
    if (!profilCompat($a['projet'] ?? '', $b['projet'] ?? '', $rules['cross_profile_pairs'])) {
        return ['score_pct' => 0, 'skip' => true, 'reason' => 'profils incompatibles'];
    }
    // Hard filter pays.
    if (!empty($rules['hard_requirements']['pays']) && $a['pays'] && $b['pays'] && $a['pays'] !== $b['pays']) {
        return ['score_pct' => 0, 'skip' => true, 'reason' => 'pays différent (hard)'];
    }
    // Hard filter type_bien (intersection des types).
    if (!empty($rules['hard_requirements']['type_bien']) && $a['type_bien'] && $b['type_bien']) {
        $atypes = is_array($a['types']) && $a['types'] ? $a['types'] : [$a['type_bien']];
        $btypes = is_array($b['types']) && $b['types'] ? $b['types'] : [$b['type_bien']];
        if (!array_intersect($atypes, $btypes)) {
            return ['score_pct' => 0, 'skip' => true, 'reason' => 'type_bien différent (hard)'];
        }
    }

    $matched = []; $divergent = [];
    $sumWeighted = 0.0; $sumWeights = 0;
    foreach ($rules['weights'] as $cri => $poids) {
        $r = scoreCritere($cri, $a, $b, $rules);
        if ($r === null) continue;
        [$score, $lab] = $r;
        $sumWeighted += $score * (int) $poids;
        $sumWeights += (int) $poids;
        if ($score >= 0.4) $matched[] = $lab;
        else $divergent[] = $lab;
    }
    if ($sumWeights === 0) return ['score_pct' => 0, 'skip' => true, 'reason' => 'aucun critère applicable'];
    $score_pct = (int) round($sumWeighted * 100 / $sumWeights);
    return [
        'score_pct' => $score_pct, 'skip' => false,
        'criteres_matched' => ['matched' => $matched, 'divergent' => $divergent],
        'sum_weighted' => round($sumWeighted, 2), 'sum_weights' => $sumWeights,
    ];
}

switch ($action) {

case 'rejouer_complet': {
    $isSuper = ($user['role'] ?? '') === 'super_admin' || ($user['_origin_role'] ?? '') === 'super_admin';
    if (!$isSuper) jsonError('Accès refusé (super_admin requis)', 403);
    $rules = ensureMatchRulesV1();
    $t0 = microtime(true);
    $stmt = db()->prepare("SELECT * FROM clients WHERE deleted_at IS NULL AND archived = 0 AND user_id = ?");
    $stmt->execute([$uid]);
    $clients = $stmt->fetchAll();
    db()->prepare("DELETE FROM matches WHERE JSON_VALID(owner_user_ids) = 1 AND JSON_CONTAINS(owner_user_ids, ?)")
        ->execute([json_encode($uid)]);
    $insert = db()->prepare(
        "INSERT INTO matches (dossier_a_id, dossier_b_id, score_pct, source, status, owner_user_ids, criteres_matched, created_at)
         VALUES (?, ?, ?, 'interne', 'non_vu', ?, ?, NOW())"
    );
    $owner = json_encode([$uid]);
    $seuil = (int) $rules['seuil_min_pct_default'];
    $calcules = 0; $inseres = 0; $en_dessous = 0;
    $n = count($clients);
    for ($i = 0; $i < $n; $i++) {
        for ($j = $i + 1; $j < $n; $j++) {
            $calcules++;
            $r = scorePair($clients[$i], $clients[$j], $rules);
            if ($r['skip']) continue;
            if ($r['score_pct'] < $seuil) { $en_dessous++; continue; }
            $aId = (int) $clients[$i]['id']; $bId = (int) $clients[$j]['id'];
            if ($aId > $bId) { $tmp = $aId; $aId = $bId; $bId = $tmp; }
            try {
                $insert->execute([$aId, $bId, $r['score_pct'], $owner,
                    json_encode($r['criteres_matched'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)]);
                $inseres++;
            } catch (PDOException $e) { /* doublon UNIQUE silencieux */ }
        }
    }
    $duree_ms = (int) round((microtime(true) - $t0) * 1000);
    jsonOk(['calcules' => $calcules, 'inseres' => $inseres, 'en_dessous_seuil' => $en_dessous,
            'seuil_pct' => $seuil, 'duree_ms' => $duree_ms]);
}

case 'score_paire': {
    $aId = (int) ($input['dossier_a_id'] ?? 0);
    $bId = (int) ($input['dossier_b_id'] ?? 0);
    if (!$aId || !$bId) jsonError('dossier_a_id et dossier_b_id requis', 400);
    $rules = ensureMatchRulesV1();
    $stmt = db()->prepare("SELECT * FROM clients WHERE id IN (?, ?) AND user_id = ?");
    $stmt->execute([$aId, $bId, $uid]);
    $rows = $stmt->fetchAll();
    if (count($rows) !== 2) jsonError('Dossiers introuvables ou non possédés', 404);
    $byId = [];
    foreach ($rows as $r) $byId[(int) $r['id']] = $r;
    $r = scorePair($byId[$aId], $byId[$bId], $rules);
    jsonOk(['result' => $r, 'rules' => ['weights' => $rules['weights'], 'tolerances' => $rules['tolerances_default'], 'geo_mode' => $rules['geo_mode']]]);
}

case 'find_all_matches': {
    // M/2026/04/28/40 — restaure l'action utilisée par le frontend pour peupler
    // matchesById (régression mission 30). Retourne {matches_by_id: {client_id:
    // {count, max_score, matches: [{other_id, score_pct, status}, ...]}}}.
    $stmt = db()->prepare(
        "SELECT id, dossier_a_id, dossier_b_id, score_pct, status FROM matches
         WHERE JSON_VALID(owner_user_ids) = 1 AND JSON_CONTAINS(owner_user_ids, ?)
         AND status IN ('non_vu','vu','pertinent','surveiller')"
    );
    $stmt->execute([json_encode($uid)]);
    $rows = $stmt->fetchAll();
    $byId = [];
    foreach ($rows as $r) {
        foreach ([['dossier_a_id', 'dossier_b_id'], ['dossier_b_id', 'dossier_a_id']] as $pair) {
            $cid = (int) $r[$pair[0]];
            $other = (int) $r[$pair[1]];
            if (!isset($byId[$cid])) $byId[$cid] = ['count' => 0, 'max_score' => 0, 'matches' => []];
            $byId[$cid]['count']++;
            $byId[$cid]['max_score'] = max($byId[$cid]['max_score'], (int) $r['score_pct']);
            $byId[$cid]['matches'][] = ['other_id' => $other, 'score_pct' => (int) $r['score_pct'], 'status' => $r['status']];
        }
    }
    jsonOk(['matches_by_id' => $byId]);
}

case 'stats': {
    $rules = ensureMatchRulesV1();
    $cn = (int) db()->query("SELECT COUNT(*) FROM clients WHERE user_id = " . $uid . " AND deleted_at IS NULL AND archived = 0")->fetchColumn();
    $paires = $cn * ($cn - 1) / 2;
    $stmt = db()->prepare("SELECT COUNT(*) AS n, AVG(score_pct) AS avg_pct, MAX(score_pct) AS max_pct, MIN(score_pct) AS min_pct
                           FROM matches WHERE JSON_VALID(owner_user_ids) = 1 AND JSON_CONTAINS(owner_user_ids, ?)");
    $stmt->execute([json_encode($uid)]);
    $st = $stmt->fetch();
    jsonOk([
        'clients' => $cn,
        'paires_possibles' => (int) $paires,
        'matches_inseres' => (int) $st['n'],
        'score_moyen' => $st['avg_pct'] !== null ? (float) round($st['avg_pct'], 1) : null,
        'score_max' => $st['max_pct'] !== null ? (int) $st['max_pct'] : null,
        'score_min' => $st['min_pct'] !== null ? (int) $st['min_pct'] : null,
        'seuil_min_pct' => (int) $rules['seuil_min_pct_default'],
        'geo_mode' => $rules['geo_mode'],
    ]);
}

default:
    jsonError('Action inconnue (rejouer_complet | score_paire | stats)', 400);
}
