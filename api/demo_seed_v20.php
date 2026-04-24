<?php
// V20 demo seed — one-shot IP-whitelist. DELETE tous les clients de Philippe, INSERT 10
// dossiers démo (3 Vendeurs / 3 Bailleurs / 2 Acheteurs / 2 Locataires). Photos déjà
// uploadées dans users/user_<pid>/imports/. Flag [DEMO-2026-04-24] dans data.notes.
require_once __DIR__ . '/db.php';
$ip = $_SERVER['REMOTE_ADDR'] ?? '';
if ($ip !== '46.225.215.148') { http_response_code(403); exit('Forbidden'); }

$pdo = db();

// 1. Philippe user_id
$st = $pdo->prepare("SELECT id FROM users WHERE email = ? LIMIT 1");
$st->execute(['philippe.ciftci@gmail.com']);
$philippe = $st->fetch();
if (!$philippe) { http_response_code(500); exit('Philippe not found'); }
$PID = (int) $philippe['id'];

// 2. DELETE existing Philippe's clients
$pre = (int) $pdo->query("SELECT COUNT(*) FROM clients WHERE user_id = $PID")->fetchColumn();
$pdo->prepare("DELETE FROM clients WHERE user_id = ?")->execute([$PID]);
$post = (int) $pdo->query("SELECT COUNT(*) FROM clients WHERE user_id = $PID")->fetchColumn();

// 3. Photo manifest (keys = dossier num 1..6, values = [uuid, uuid, ...])
// Lu depuis body POST JSON { "photos": { "1": [...], ... } }
$raw = file_get_contents('php://input');
$body = json_decode($raw, true) ?: [];
$photoManifest = $body['photos'] ?? [];

$TAG = '[DEMO-2026-04-24]';

function pathsFor(array $uuids, int $pid): array {
    $out = [];
    foreach ($uuids as $u) $out[] = 'users/user_' . $pid . '/imports/' . $u . '.jpg';
    return $out;
}

// 4. 10 dossiers — data JSON par dossier
$dossiers = [
    // VENDEURS
    [
        'projet' => 'Vendeur', 'prenom' => 'Sophie', 'nom' => 'Lambert',
        'tel' => '+33 6 12 34 56 78', 'email' => 'sophie.lambert@example.com',
        'is_investisseur' => 0,
        'photos_key' => 1,
        'days_ago' => 14,
        'data' => [
            'notes' => "$TAG Riad médina Bab Doukkala — propriétaire particulier FR résidant à Marrakech.",
            'origine' => 'recommandation amie',
            'canal' => 'WhatsApp', 'langue' => 'FR',
            'type_bien' => 'Riad', 'chambres' => 4, 'sdb' => 3, 'surface' => 280, 'etages' => 2,
            'terrasse' => true, 'patio' => true,
            'ville' => 'Marrakech', 'quartier' => 'Bab Doukkala',
            'pays' => 'MA', 'pays_residence' => 'MA',
            'type_personne' => 'particulier',
            'prix_affiche' => '4 500 000', 'prix_plancher' => '4 200 000', 'devise' => 'MAD',
        ],
    ],
    [
        'projet' => 'Vendeur', 'prenom' => 'Karim', 'nom' => 'El Fassi',
        'tel' => '+212 6 20 30 40 50', 'email' => 'k.elfassi@atlas-dev.ma',
        'societe_nom' => 'SARL Atlas Développement',
        'is_investisseur' => 1,
        'photos_key' => 2,
        'days_ago' => 12,
        'data' => [
            'notes' => "$TAG Villa contemporaine Route d'Amizmiz — gérant Karim El Fassi pour SARL Atlas Développement. Horizon invest 10 ans, rendement cible 6%/an.",
            'origine' => 'site internet',
            'canal' => 'Email', 'langue' => 'FR/AR',
            'type_bien' => 'Villa', 'chambres' => 5, 'sdb' => 4, 'surface' => 450, 'etages' => 2,
            'piscine' => true, 'jardin' => true, 'parking' => 3,
            'ville' => 'Marrakech', 'quartier' => 'Route d\'Amizmiz', 'pays' => 'MA',
            'type_personne' => 'societe', 'forme_juridique' => 'SARL',
            'siret' => 'MA-RC-45782', 'representant' => 'Karim El Fassi',
            'prix_affiche' => '8 500 000', 'prix_plancher' => '8 000 000', 'devise' => 'MAD',
            'rendement_cible_pct' => 6, 'horizon_invest_annees' => 10,
        ],
    ],
    [
        'projet' => 'Vendeur', 'prenom' => 'Jean-Marc', 'nom' => 'Dubois',
        'tel' => '+33 1 42 33 44 55', 'email' => 'jm.dubois@example.com',
        'is_investisseur' => 0,
        'photos_key' => 3,
        'days_ago' => 10,
        'data' => [
            'notes' => "$TAG Appartement Hivernage, 4e étage balcon sud + 1 parking souterrain + 1 cave.",
            'origine' => 'Facebook', 'canal' => 'Tel', 'langue' => 'FR',
            'type_bien' => 'Appartement', 'chambres' => 3, 'sdb' => 2, 'surface' => 140,
            'etage' => 4, 'orientation' => 'Sud', 'balcon' => true, 'parking' => 1, 'cave' => 1,
            'ville' => 'Marrakech', 'quartier' => 'Hivernage', 'pays' => 'MA',
            'pays_residence' => 'FR',
            'type_personne' => 'particulier',
            'prix_affiche' => '2 800 000', 'prix_plancher' => '2 600 000', 'devise' => 'MAD',
        ],
    ],
    // BAILLEURS
    [
        'projet' => 'Bailleur', 'prenom' => 'Fatima', 'nom' => 'Benjelloun',
        'tel' => '+212 6 61 22 33 44', 'email' => 'f.benjelloun@example.ma',
        'is_investisseur' => 0,
        'photos_key' => 4,
        'days_ago' => 8,
        'data' => [
            'notes' => "$TAG Appartement meublé Hivernage — bail 12 mois renouvelable, dépôt 2 mois.",
            'canal' => 'WhatsApp', 'langue' => 'FR/AR',
            'type_bien' => 'Appartement', 'meuble' => true, 'chambres' => 2, 'sdb' => 2, 'surface' => 85,
            'etage' => 3, 'balcon' => true, 'parking' => 1,
            'ville' => 'Rabat', 'quartier' => 'Hivernage', 'pays' => 'MA',
            'type_personne' => 'particulier',
            'loyer_demande' => '12 000', 'loyer_plancher' => '11 000', 'devise' => 'MAD',
            'charges' => '800', 'charges_incluses' => true, 'depot_mois' => 2,
            'duree_bail_mois' => 12, 'renouvelable' => true,
        ],
    ],
    [
        'projet' => 'Bailleur', 'prenom' => 'Ahmed', 'nom' => 'Chraibi',
        'tel' => '+212 6 77 88 99 00', 'email' => 'ahmed.chraibi@example.ma',
        'is_investisseur' => 0,
        'photos_key' => 5,
        'days_ago' => 6,
        'data' => [
            'notes' => "$TAG Villa Palmeraie location saisonnière Airbnb + directe. Piscine chauffée, jardin 1500 m².",
            'canal' => 'Email', 'langue' => 'FR/EN',
            'type_bien' => 'Villa', 'chambres' => 6, 'sdb' => 5, 'surface' => 320, 'etages' => 1,
            'piscine' => true, 'piscine_chauffee' => true, 'jardin' => true, 'surface_jardin' => 1500,
            'parking' => 4,
            'ville' => 'Marrakech', 'quartier' => 'Palmeraie', 'pays' => 'MA',
            'type_personne' => 'particulier',
            'type_location' => 'saisonniere',
            'loyer_demande' => '5 000', 'loyer_plancher' => '3 500', 'devise' => 'MAD',
            'unite_loyer' => 'nuit', 'saison_haute' => '5 000', 'saison_basse' => '3 500',
            'depot' => '10 000',
        ],
    ],
    [
        'projet' => 'Bailleur', 'prenom' => 'Hassan', 'nom' => 'Alaoui',
        'tel' => '+212 5 22 11 22 33', 'email' => 'h.alaoui@immobiliere-gueliz.ma',
        'societe_nom' => 'Société Immobilière du Guéliz',
        'is_investisseur' => 0,
        'photos_key' => 6,
        'days_ago' => 5,
        'data' => [
            'notes' => "$TAG Plateau bureaux Guéliz — bail commercial 3/6/9, TVA 20%, charges 2 500 MAD.",
            'canal' => 'Email', 'langue' => 'FR',
            'type_bien' => 'Bureau', 'surface' => 180, 'etage' => 2, 'climatisation' => true, 'parking' => 2,
            'ville' => 'Marrakech', 'quartier' => 'Guéliz', 'pays' => 'MA',
            'type_personne' => 'societe', 'forme_juridique' => 'SA', 'representant' => 'Hassan Alaoui',
            'type_location' => 'bail_commercial',
            'loyer_demande' => '18 000', 'devise' => 'MAD', 'loyer_ht' => true,
            'charges' => '2 500', 'depot_mois' => 3, 'tva_pct' => 20,
            'duree_bail_type' => '3/6/9',
        ],
    ],
    // ACHETEURS (pas de photos)
    [
        'projet' => 'Acheteur', 'prenom' => 'Marie & Thomas', 'nom' => 'Legrand',
        'tel' => '+33 7 55 66 77 88', 'email' => 'legrand.couple@example.fr',
        'is_investisseur' => 0,
        'photos_key' => null,
        'days_ago' => 4,
        'data' => [
            'notes' => "$TAG Couple FR Bordeaux — recherche riad ou villa 3-4 ch, Kasbah ou Sidi Ghanem. Financement crédit France.",
            'canal' => 'WhatsApp', 'langue' => 'FR', 'horizon_achat_mois' => 6,
            'type_bien_recherche' => ['Riad', 'Villa'], 'chambres_min' => 3, 'chambres_max' => 4,
            'surface_min' => 150, 'surface_max' => 250,
            'quartiers_cibles' => ['Kasbah', 'Sidi Ghanem'], 'ville' => 'Marrakech', 'pays' => 'MA',
            'pays_residence' => 'FR',
            'type_personne' => 'couple',
            'budget_max' => '2 500 000', 'apport' => '1 500 000', 'devise' => 'MAD',
            'financement' => 'crédit France',
        ],
    ],
    [
        'projet' => 'Acheteur', 'prenom' => 'Ricardo', 'nom' => 'Alvarez',
        'tel' => '+212 6 33 44 55 66', 'email' => 'ricardo.alvarez@example.es',
        'is_investisseur' => 1,
        'photos_key' => null,
        'days_ago' => 3,
        'data' => [
            'notes' => "$TAG Expat espagnol à Marrakech — investisseur location saisonnière, rendement cible 8%/an, horizon 5 ans.",
            'origine' => 'Google', 'canal' => 'Email', 'langue' => 'FR/EN/ES',
            'type_bien_recherche' => ['Villa', 'Appartement'], 'piscine_requise' => true,
            'chambres_min' => 3, 'surface_min' => 180,
            'quartiers_cibles' => ['Targa', 'Palmeraie'], 'ville' => 'Marrakech', 'pays' => 'MA',
            'pays_residence' => 'ES',
            'type_personne' => 'particulier',
            'budget_max' => '5 000 000', 'devise' => 'MAD',
            'rendement_cible_pct' => 8, 'horizon_invest_annees' => 5,
            'type_location_cible' => 'saisonniere',
        ],
    ],
    // LOCATAIRES (pas de photos)
    [
        'projet' => 'Locataire', 'prenom' => 'Famille', 'nom' => 'Weber',
        'tel' => '+49 170 12 34 567', 'email' => 'weber.family@example.de',
        'is_investisseur' => 0,
        'photos_key' => null,
        'days_ago' => 2,
        'data' => [
            'notes' => "$TAG Expats allemands, arrivée Marrakech. 2 enfants + 1 chien. Garants parents DE + attestations employeur.",
            'canal' => 'Email', 'langue' => 'EN/DE', 'duree_bail_mois' => 36,
            'type_bien_recherche' => ['Villa', 'Appartement'], 'chambres_min' => 4,
            'surface_min' => 200, 'jardin_requis' => true,
            'quartiers_cibles' => ['Targa', 'Amelkis'], 'ville' => 'Marrakech', 'pays' => 'MA',
            'pays_residence' => 'DE',
            'type_personne' => 'famille', 'nb_enfants' => 2, 'animaux' => 'chien',
            'loyer_max' => '25 000', 'devise' => 'MAD', 'depot_mois' => 2,
            'garants' => 'parents DE + attestations employeur',
        ],
    ],
    [
        'projet' => 'Locataire', 'prenom' => 'Yasmine', 'nom' => 'Benkiran',
        'tel' => '+212 6 11 22 33 44', 'email' => 'y.benkiran@example.ma',
        'is_investisseur' => 0,
        'photos_key' => null,
        'days_ago' => 1,
        'data' => [
            'notes' => "$TAG Étudiante MA en stage 6 mois à Marrakech. Garant parent.",
            'canal' => 'WhatsApp', 'langue' => 'FR/AR', 'duree_bail_mois' => 6, 'renouvelable' => true,
            'type_bien_recherche' => ['Studio', 'T1'], 'meuble_requis' => true,
            'quartiers_cibles' => ['Guéliz', 'Centre'], 'ville' => 'Marrakech', 'pays' => 'MA',
            'pays_residence' => 'MA',
            'type_personne' => 'particulier', 'statut' => 'étudiante',
            'loyer_max' => '6 000', 'devise' => 'MAD', 'charges_incluses' => true, 'depot_mois' => 1,
            'garants' => 'parent',
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
    if (!empty($d['photos_key']) && isset($photoManifest[(string) $d['photos_key']])) {
        $photos = pathsFor($photoManifest[(string) $d['photos_key']], $PID);
    }
    $data = $d['data'];
    if ($photos) $data['photos'] = $photos;
    $when = date('Y-m-d H:i:s', time() - ($d['days_ago'] * 86400));
    $ins->execute([
        $PID,
        $d['projet'],
        (int) $d['is_investisseur'],
        $d['prenom'], $d['nom'],
        $d['societe_nom'] ?? null,
        $d['tel'], $d['email'],
        json_encode($data, JSON_UNESCAPED_UNICODE),
        $when, $when,
    ]);
    $id = (int) $pdo->lastInsertId();
    $created[] = [
        'id' => $id,
        'projet' => $d['projet'],
        'name' => trim(($d['prenom'] ?? '') . ' ' . ($d['nom'] ?? '')),
        'societe' => $d['societe_nom'] ?? null,
        'is_investisseur' => (int) $d['is_investisseur'],
        'photos' => count($photos),
    ];
}

header('Content-Type: application/json; charset=utf-8');
echo json_encode([
    'ok' => true,
    'philippe_user_id' => $PID,
    'pre_count' => $pre,
    'after_delete_count' => $post,
    'created' => $created,
    'total_created' => count($created),
], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
