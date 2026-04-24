<?php
// V20 demo seed FIX — structure JSON alignée sur vrai schéma app.
// Tous les champs lus par le front (c.prenom, c.tel, c.bien.*, c.financement.*,
// c.prix_affiche, c.loyer_demande, c.loyer_max, c.budget_max) sont posés au bon endroit.
// Photos : format proxy /api/image.php?path=users%2Fuser_1%2Fimports%2F<uuid>.jpg.
require_once __DIR__ . '/db.php';
$ip = $_SERVER['REMOTE_ADDR'] ?? '';
if ($ip !== '46.225.215.148') { http_response_code(403); exit('Forbidden'); }

$pdo = db();
$st = $pdo->prepare("SELECT id FROM users WHERE email = ? LIMIT 1");
$st->execute(['philippe.ciftci@gmail.com']);
$philippe = $st->fetch();
if (!$philippe) { http_response_code(500); exit('Philippe not found'); }
$PID = (int) $philippe['id'];

$pre = (int) $pdo->query("SELECT COUNT(*) FROM clients WHERE user_id = $PID")->fetchColumn();
$pdo->prepare("DELETE FROM clients WHERE user_id = ?")->execute([$PID]);

$raw = file_get_contents('php://input');
$body = json_decode($raw, true) ?: [];
$manifest = $body['photos'] ?? [];

function photoPaths(array $uuids, int $pid): array {
    $out = [];
    foreach ($uuids as $u) {
        // Front attend soit /api/image.php?path=... (pré-encodé) soit chemin relatif.
        // Format utilisé en prod : URL proxy avec path URL-encodé.
        $inner = 'users/user_' . $pid . '/imports/' . $u . '.jpg';
        $out[] = '/api/image.php?path=' . rawurlencode($inner);
    }
    return $out;
}

$TAG = '[DEMO-2026-04-24]';
$TOUCH = (int) (microtime(true) * 1000);

$dossiers = [
    // 1) VENDEUR particulier FR — Sophie Lambert — Riad Bab Doukkala
    [
        'projet' => 'Vendeur', 'is_investisseur' => 0, 'photos_key' => 1, 'days_ago' => 14,
        'col_prenom' => 'Sophie', 'col_nom' => 'Lambert',
        'col_tel' => '+33 6 12 34 56 78', 'col_email' => 'sophie.lambert@example.com',
        'col_societe' => null,
        'data' => [
            'prenom' => 'Sophie', 'nom' => 'Lambert',
            'tel' => '+33 6 12 34 56 78', 'email' => 'sophie.lambert@example.com',
            'tels' => [['label' => 'Principal', 'valeur' => '+33 6 12 34 56 78', 'primary' => true]],
            'profil_type' => 'Particulier',
            'nationalite' => 'FR', 'pays_residence' => 'MA',
            'ville' => 'Marrakech', 'adresse' => 'Bab Doukkala',
            'is_investisseur' => false,
            'canal_prefere' => 'WhatsApp', 'origine' => 'recommandation amie', 'langue' => 'FR',
            'notes' => "$TAG Riad médina Bab Doukkala — propriétaire particulier FR résidant à Marrakech. Canal WhatsApp, origine recommandation amie.",
            'prix_affiche' => 4500000,
            'bien' => [
                'pays' => 'MA',
                'types' => ['Riad'], 'type' => 'Riad',
                'ville' => 'Marrakech', 'quartier' => 'Bab Doukkala',
                'chambres' => 4, 'sdb' => 3, 'surface' => 280, 'pieces' => 7, 'etages' => 2,
                'terrasse' => true, 'patio' => true,
                'description' => "Riad authentique médina Bab Doukkala — 280 m² sur 2 niveaux, 4 chambres, 3 salles de bains, terrasse et patio traditionnel.",
            ],
            'financement' => [
                'devise' => 'MAD',
                'prix_affiche' => 4500000, 'prix_plancher' => 4200000,
            ],
        ],
    ],
    // 2) VENDEUR SARL + Investisseur — Karim El Fassi — Villa Amizmiz
    [
        'projet' => 'Vendeur', 'is_investisseur' => 1, 'photos_key' => 2, 'days_ago' => 12,
        'col_prenom' => 'Karim', 'col_nom' => 'El Fassi',
        'col_tel' => '+212 6 20 30 40 50', 'col_email' => 'k.elfassi@atlas-dev.ma',
        'col_societe' => 'SARL Atlas Développement',
        'data' => [
            'prenom' => 'Karim', 'nom' => 'El Fassi',
            'tel' => '+212 6 20 30 40 50', 'email' => 'k.elfassi@atlas-dev.ma',
            'tels' => [['label' => 'Pro', 'valeur' => '+212 6 20 30 40 50', 'primary' => true]],
            'profil_type' => 'Société',
            'societe_nom' => 'SARL Atlas Développement',
            'representant' => 'Karim El Fassi',
            'forme_juridique' => 'SARL',
            'siret' => 'MA-RC-45782',
            'nationalite' => 'MA', 'pays_residence' => 'MA',
            'ville' => 'Casablanca',
            'is_investisseur' => true,
            'canal_prefere' => 'Email', 'origine' => 'site internet', 'langue' => 'FR/AR',
            'notes' => "$TAG Villa contemporaine Route d'Amizmiz — gérant Karim El Fassi pour SARL Atlas Développement. Horizon invest 10 ans, rendement cible 6%/an.",
            'prix_affiche' => 8500000,
            'rendement_cible' => 6, 'horizon_invest_annees' => 10,
            'bien' => [
                'pays' => 'MA',
                'types' => ['Villa'], 'type' => 'Villa',
                'ville' => 'Marrakech', 'quartier' => "Route d'Amizmiz",
                'chambres' => 5, 'sdb' => 4, 'surface' => 450, 'pieces' => 9, 'etages' => 2,
                'piscine' => true, 'jardin' => true, 'parking' => 3,
                'description' => "Villa contemporaine 450 m² sur 2 niveaux, 5 chambres, 4 SDB, piscine, jardin, 3 parkings. Route d'Amizmiz.",
                'neuf_ancien' => 'neuf',
            ],
            'financement' => [
                'devise' => 'MAD',
                'prix_affiche' => 8500000, 'prix_plancher' => 8000000,
                'rendement_cible' => 6, 'horizon_invest_annees' => 10,
            ],
        ],
    ],
    // 3) VENDEUR particulier FR — Jean-Marc Dubois — Appart Hivernage
    [
        'projet' => 'Vendeur', 'is_investisseur' => 0, 'photos_key' => 3, 'days_ago' => 10,
        'col_prenom' => 'Jean-Marc', 'col_nom' => 'Dubois',
        'col_tel' => '+33 1 42 33 44 55', 'col_email' => 'jm.dubois@example.com',
        'col_societe' => null,
        'data' => [
            'prenom' => 'Jean-Marc', 'nom' => 'Dubois',
            'tel' => '+33 1 42 33 44 55', 'email' => 'jm.dubois@example.com',
            'tels' => [['label' => 'Fixe', 'valeur' => '+33 1 42 33 44 55', 'primary' => true]],
            'profil_type' => 'Particulier',
            'nationalite' => 'FR', 'pays_residence' => 'FR',
            'ville' => 'Paris',
            'is_investisseur' => false,
            'canal_prefere' => 'Tel', 'origine' => 'Facebook', 'langue' => 'FR',
            'notes' => "$TAG Appartement Hivernage, 4e étage balcon sud + 1 parking souterrain + 1 cave.",
            'prix_affiche' => 2800000,
            'bien' => [
                'pays' => 'MA',
                'types' => ['Appartement'], 'type' => 'Appartement',
                'ville' => 'Marrakech', 'quartier' => 'Hivernage',
                'chambres' => 3, 'sdb' => 2, 'surface' => 140, 'pieces' => 5, 'etage' => 4,
                'orientation' => 'Sud', 'balcon' => true, 'parking' => 1, 'cave' => 1,
                'description' => "Appartement Hivernage, 140 m², 3 ch, 2 SDB, 4e étage, balcon plein sud, 1 parking souterrain, 1 cave.",
            ],
            'financement' => ['devise' => 'MAD', 'prix_affiche' => 2800000, 'prix_plancher' => 2600000],
        ],
    ],
    // 4) BAILLEUR particulier MA — Fatima Benjelloun — Appart meublé Hivernage Rabat
    [
        'projet' => 'Bailleur', 'is_investisseur' => 0, 'photos_key' => 4, 'days_ago' => 8,
        'col_prenom' => 'Fatima', 'col_nom' => 'Benjelloun',
        'col_tel' => '+212 6 61 22 33 44', 'col_email' => 'f.benjelloun@example.ma',
        'col_societe' => null,
        'data' => [
            'prenom' => 'Fatima', 'nom' => 'Benjelloun',
            'tel' => '+212 6 61 22 33 44', 'email' => 'f.benjelloun@example.ma',
            'tels' => [['label' => 'Principal', 'valeur' => '+212 6 61 22 33 44', 'primary' => true]],
            'profil_type' => 'Particulier',
            'nationalite' => 'MA', 'pays_residence' => 'MA',
            'ville' => 'Rabat',
            'is_investisseur' => false,
            'canal_prefere' => 'WhatsApp', 'langue' => 'FR/AR',
            'notes' => "$TAG Appartement meublé Hivernage Rabat — bail 12 mois renouvelable, dépôt 2 mois, charges 800 incluses.",
            'loyer_demande' => 12000,
            'bien' => [
                'pays' => 'MA',
                'types' => ['Appartement'], 'type' => 'Appartement',
                'ville' => 'Rabat', 'quartier' => 'Hivernage',
                'meuble' => true,
                'chambres' => 2, 'sdb' => 2, 'surface' => 85, 'pieces' => 4, 'etage' => 3,
                'balcon' => true, 'parking' => 1,
                'description' => "Appartement meublé 85 m², 2 ch, 2 SDB, 3e étage, balcon, 1 parking. Location bail 12 mois renouvelable.",
            ],
            'financement' => [
                'devise' => 'MAD',
                'loyer_demande' => 12000, 'loyer_plancher' => 11000,
                'charges' => 800, 'charges_incluses' => true, 'depot_mois' => 2,
            ],
            'duree_bail_mois' => 12, 'renouvelable' => true,
        ],
    ],
    // 5) BAILLEUR particulier MA — Ahmed Chraibi — Villa Palmeraie saisonnière
    [
        'projet' => 'Bailleur', 'is_investisseur' => 0, 'photos_key' => 5, 'days_ago' => 6,
        'col_prenom' => 'Ahmed', 'col_nom' => 'Chraibi',
        'col_tel' => '+212 6 77 88 99 00', 'col_email' => 'ahmed.chraibi@example.ma',
        'col_societe' => null,
        'data' => [
            'prenom' => 'Ahmed', 'nom' => 'Chraibi',
            'tel' => '+212 6 77 88 99 00', 'email' => 'ahmed.chraibi@example.ma',
            'tels' => [['label' => 'Principal', 'valeur' => '+212 6 77 88 99 00', 'primary' => true]],
            'profil_type' => 'Particulier',
            'nationalite' => 'MA', 'pays_residence' => 'MA',
            'ville' => 'Marrakech',
            'is_investisseur' => false,
            'canal_prefere' => 'Email', 'langue' => 'FR/EN',
            'notes' => "$TAG Villa Palmeraie location saisonnière (Airbnb + directe). Piscine chauffée, jardin 1500 m². Tarif 5 000 MAD/nuit haute saison, 3 500 basse.",
            'loyer_demande' => 5000,
            'type_location' => 'saisonniere',
            'bien' => [
                'pays' => 'MA',
                'types' => ['Villa'], 'type' => 'Villa',
                'ville' => 'Marrakech', 'quartier' => 'Palmeraie',
                'chambres' => 6, 'sdb' => 5, 'surface' => 320, 'pieces' => 10, 'etages' => 1,
                'piscine' => true, 'piscine_chauffee' => true, 'jardin' => true,
                'surface_terrain' => 1500, 'parking' => 4,
                'description' => "Villa Palmeraie 320 m² plain-pied, 6 ch, 5 SDB, piscine chauffée, jardin 1500 m², 4 parkings. Location saisonnière Airbnb + directe.",
            ],
            'financement' => [
                'devise' => 'MAD',
                'loyer_demande' => 5000, 'loyer_plancher' => 3500,
                'unite_loyer' => 'nuit',
                'saison_haute' => 5000, 'saison_basse' => 3500,
                'depot' => 10000,
            ],
        ],
    ],
    // 6) BAILLEUR SA — Hassan Alaoui — Plateau bureaux Guéliz
    [
        'projet' => 'Bailleur', 'is_investisseur' => 0, 'photos_key' => 6, 'days_ago' => 5,
        'col_prenom' => 'Hassan', 'col_nom' => 'Alaoui',
        'col_tel' => '+212 5 22 11 22 33', 'col_email' => 'h.alaoui@immobiliere-gueliz.ma',
        'col_societe' => 'Société Immobilière du Guéliz',
        'data' => [
            'prenom' => 'Hassan', 'nom' => 'Alaoui',
            'tel' => '+212 5 22 11 22 33', 'email' => 'h.alaoui@immobiliere-gueliz.ma',
            'tels' => [['label' => 'Pro', 'valeur' => '+212 5 22 11 22 33', 'primary' => true]],
            'profil_type' => 'Société',
            'societe_nom' => 'Société Immobilière du Guéliz',
            'representant' => 'Hassan Alaoui',
            'forme_juridique' => 'SA',
            'nationalite' => 'MA', 'pays_residence' => 'MA',
            'ville' => 'Marrakech',
            'is_investisseur' => false,
            'canal_prefere' => 'Email', 'langue' => 'FR',
            'notes' => "$TAG Plateau bureaux Guéliz — bail commercial 3/6/9, TVA 20%, charges 2 500 MAD. Climatisation + 2 parkings.",
            'loyer_demande' => 18000,
            'type_location' => 'bail_commercial',
            'bien' => [
                'pays' => 'MA',
                'types' => ['Bureau'], 'type' => 'Bureau',
                'ville' => 'Marrakech', 'quartier' => 'Guéliz',
                'surface' => 180, 'etage' => 2, 'climatisation' => true, 'parking' => 2,
                'description' => "Plateau bureaux Guéliz 180 m² au 2e étage, climatisé, 2 parkings. Bail commercial 3/6/9.",
            ],
            'financement' => [
                'devise' => 'MAD',
                'loyer_demande' => 18000,
                'loyer_ht' => true, 'charges' => 2500,
                'depot_mois' => 3, 'tva_pct' => 20,
            ],
            'duree_bail_type' => '3/6/9',
        ],
    ],
    // 7) ACHETEUR couple FR — Marie & Thomas Legrand
    [
        'projet' => 'Acheteur', 'is_investisseur' => 0, 'photos_key' => null, 'days_ago' => 4,
        'col_prenom' => 'Marie & Thomas', 'col_nom' => 'Legrand',
        'col_tel' => '+33 7 55 66 77 88', 'col_email' => 'legrand.couple@example.fr',
        'col_societe' => null,
        'data' => [
            'prenom' => 'Marie & Thomas', 'nom' => 'Legrand',
            'tel' => '+33 7 55 66 77 88', 'email' => 'legrand.couple@example.fr',
            'tels' => [['label' => 'Thomas', 'valeur' => '+33 7 55 66 77 88', 'primary' => true]],
            'profil_type' => 'Particulier',
            'nationalite' => 'FR', 'pays_residence' => 'FR',
            'ville' => 'Bordeaux',
            'is_investisseur' => false,
            'canal_prefere' => 'WhatsApp', 'langue' => 'FR',
            'notes' => "$TAG Couple FR Bordeaux — recherche riad ou villa 3-4 ch, Kasbah ou Sidi Ghanem. Financement crédit France. Horizon 6 mois.",
            'budget_max' => 2500000, 'budget_min' => 1800000,
            'bien' => [
                'pays' => 'MA',
                'types' => ['Riad', 'Villa'],
                'ville' => 'Marrakech', 'quartiers_cibles' => ['Kasbah', 'Sidi Ghanem'],
                'chambres_min' => 3, 'chambres_max' => 4,
                'surface_min' => 150, 'surface_max' => 250,
                'description' => "Recherche riad ou villa 3-4 chambres, 150-250 m², Kasbah ou Sidi Ghanem. Financement crédit France.",
            ],
            'financement' => [
                'devise' => 'MAD', 'budget_max' => 2500000, 'budget_total' => 2500000,
                'apport' => 1500000, 'mode' => 'classique',
            ],
            'horizon_achat_mois' => 6,
        ],
    ],
    // 8) ACHETEUR + Investisseur ES — Ricardo Alvarez
    [
        'projet' => 'Acheteur', 'is_investisseur' => 1, 'photos_key' => null, 'days_ago' => 3,
        'col_prenom' => 'Ricardo', 'col_nom' => 'Alvarez',
        'col_tel' => '+212 6 33 44 55 66', 'col_email' => 'ricardo.alvarez@example.es',
        'col_societe' => null,
        'data' => [
            'prenom' => 'Ricardo', 'nom' => 'Alvarez',
            'tel' => '+212 6 33 44 55 66', 'email' => 'ricardo.alvarez@example.es',
            'tels' => [['label' => 'Principal', 'valeur' => '+212 6 33 44 55 66', 'primary' => true]],
            'profil_type' => 'Particulier',
            'nationalite' => 'ES', 'pays_residence' => 'MA',
            'ville' => 'Marrakech',
            'is_investisseur' => true,
            'canal_prefere' => 'Email', 'origine' => 'Google', 'langue' => 'FR/EN/ES',
            'notes' => "$TAG Expat ES à Marrakech — investisseur location saisonnière, rendement cible 8%/an, horizon 5 ans.",
            'budget_max' => 5000000,
            'rendement_cible' => 8, 'horizon_invest_annees' => 5,
            'bien' => [
                'pays' => 'MA',
                'types' => ['Villa', 'Appartement'],
                'ville' => 'Marrakech', 'quartiers_cibles' => ['Targa', 'Palmeraie'],
                'chambres_min' => 3,
                'surface_min' => 180,
                'piscine' => true,
                'description' => "Recherche villa ou appartement avec piscine, 3+ ch, 180+ m², Targa ou Palmeraie.",
            ],
            'financement' => [
                'devise' => 'MAD', 'budget_max' => 5000000, 'budget_total' => 5000000,
                'rendement_cible' => 8, 'horizon_invest_annees' => 5,
            ],
            'type_location_cible' => 'saisonniere',
        ],
    ],
    // 9) LOCATAIRE famille DE — Weber
    [
        'projet' => 'Locataire', 'is_investisseur' => 0, 'photos_key' => null, 'days_ago' => 2,
        'col_prenom' => 'Famille', 'col_nom' => 'Weber',
        'col_tel' => '+49 170 12 34 567', 'col_email' => 'weber.family@example.de',
        'col_societe' => null,
        'data' => [
            'prenom' => 'Famille', 'nom' => 'Weber',
            'tel' => '+49 170 12 34 567', 'email' => 'weber.family@example.de',
            'tels' => [['label' => 'Principal', 'valeur' => '+49 170 12 34 567', 'primary' => true]],
            'profil_type' => 'Particulier',
            'nationalite' => 'DE', 'pays_residence' => 'DE',
            'ville' => 'Marrakech',
            'is_investisseur' => false,
            'canal_prefere' => 'Email', 'langue' => 'EN/DE',
            'notes' => "$TAG Expats allemands, arrivée Marrakech. 2 enfants + 1 chien. Garants parents DE + attestations employeur. Bail 3 ans.",
            'loyer_max' => 25000,
            'bien' => [
                'pays' => 'MA',
                'types' => ['Villa', 'Appartement'],
                'ville' => 'Marrakech', 'quartiers_cibles' => ['Targa', 'Amelkis'],
                'chambres_min' => 4, 'surface_min' => 200, 'jardin' => true,
                'description' => "Recherche villa ou appartement 4+ ch, 200+ m², avec jardin, Targa ou Amelkis.",
            ],
            'financement' => [
                'devise' => 'MAD', 'loyer_max' => 25000,
                'depot_mois' => 2, 'garants' => 'parents DE + attestations employeur',
            ],
            'duree_bail_mois' => 36, 'nb_enfants' => 2, 'animaux' => 'chien',
        ],
    ],
    // 10) LOCATAIRE étudiante MA — Yasmine Benkiran
    [
        'projet' => 'Locataire', 'is_investisseur' => 0, 'photos_key' => null, 'days_ago' => 1,
        'col_prenom' => 'Yasmine', 'col_nom' => 'Benkiran',
        'col_tel' => '+212 6 11 22 33 44', 'col_email' => 'y.benkiran@example.ma',
        'col_societe' => null,
        'data' => [
            'prenom' => 'Yasmine', 'nom' => 'Benkiran',
            'tel' => '+212 6 11 22 33 44', 'email' => 'y.benkiran@example.ma',
            'tels' => [['label' => 'Mobile', 'valeur' => '+212 6 11 22 33 44', 'primary' => true]],
            'profil_type' => 'Particulier',
            'nationalite' => 'MA', 'pays_residence' => 'MA',
            'ville' => 'Marrakech',
            'is_investisseur' => false,
            'canal_prefere' => 'WhatsApp', 'langue' => 'FR/AR',
            'notes' => "$TAG Étudiante MA en stage 6 mois à Marrakech. Garant parent.",
            'loyer_max' => 6000,
            'bien' => [
                'pays' => 'MA',
                'types' => ['Studio', 'Appartement'],
                'ville' => 'Marrakech', 'quartiers_cibles' => ['Guéliz', 'Centre'],
                'meuble' => true,
                'description' => "Recherche studio ou T1 meublé, Guéliz ou centre.",
            ],
            'financement' => [
                'devise' => 'MAD', 'loyer_max' => 6000,
                'charges_incluses' => true, 'depot_mois' => 1, 'garants' => 'parent',
            ],
            'duree_bail_mois' => 6, 'renouvelable' => true,
            'statut' => 'étudiante',
        ],
    ],
];

$created = [];
$ins = $pdo->prepare("INSERT INTO clients
    (user_id, projet, is_investisseur, archived, is_draft, is_staged,
     prenom, nom, societe_nom, tel, email, data, created_at, updated_at)
    VALUES (?, ?, ?, 0, 0, 0, ?, ?, ?, ?, ?, ?, ?, ?)");

foreach ($dossiers as $d) {
    $photos = [];
    if (!empty($d['photos_key']) && isset($manifest[(string) $d['photos_key']])) {
        $photos = photoPaths($manifest[(string) $d['photos_key']], $PID);
    }
    $data = $d['data'];
    $data['_touch'] = $TOUCH;
    if ($photos) { $data['bien']['photos'] = $photos; }
    $when = date('Y-m-d H:i:s', time() - ($d['days_ago'] * 86400));
    $ins->execute([
        $PID, $d['projet'], (int) $d['is_investisseur'],
        $d['col_prenom'], $d['col_nom'], $d['col_societe'],
        $d['col_tel'], $d['col_email'],
        json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        $when, $when,
    ]);
    $id = (int) $pdo->lastInsertId();
    $created[] = [
        'id' => $id,
        'projet' => $d['projet'],
        'name' => trim(($d['col_prenom'] ?? '') . ' ' . ($d['col_nom'] ?? '')),
        'societe' => $d['col_societe'],
        'is_investisseur' => (int) $d['is_investisseur'],
        'photos' => count($photos),
    ];
}

header('Content-Type: application/json; charset=utf-8');
echo json_encode([
    'ok' => true,
    'philippe_user_id' => $PID,
    'pre_delete_count' => $pre,
    'created' => $created,
    'total_created' => count($created),
], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
