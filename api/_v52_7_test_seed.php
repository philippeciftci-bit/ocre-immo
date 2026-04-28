<?php
// V52.7 — one-shot IP-whitelist : configure mode test + crée user test@ocre.immo
// + seed 7 dossiers démo variés. Ne touche PAS à user_id=8 (morineau.ophelie).
require_once __DIR__ . '/db.php';
$allowed = ['46.225.215.148','127.0.0.1','::1'];
$remote = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? ($_SERVER['REMOTE_ADDR'] ?? '');
$ip = trim(explode(',', $remote)[0]);
if (!in_array($ip, $allowed, true)) { http_response_code(403); exit(json_encode(['ok'=>false,'error'=>'forbidden','seen_ip'=>$ip])); }
header('Content-Type: application/json; charset=utf-8');

$out = ['steps' => []];
try {
    $pdo = db();

    // 1. Vérifie settings existants (table settings, colonne key_name/value)
    $cur = [];
    foreach (['test_password','mode_test'] as $k) {
        $st = $pdo->prepare("SELECT value FROM settings WHERE key_name = ? LIMIT 1");
        $st->execute([$k]);
        $cur[$k] = $st->fetchColumn();
    }
    $out['settings_before'] = $cur;

    // 2. test_password : si vide → set OCRE-DEMO-2026, sinon garde
    $finalPwd = $cur['test_password'];
    if (!$finalPwd) {
        $finalPwd = 'OCRE-DEMO-2026';
        $pdo->prepare("INSERT INTO settings (key_name, value) VALUES (?, ?) ON DUPLICATE KEY UPDATE value = VALUES(value)")
            ->execute(['test_password', $finalPwd]);
        $out['steps'][] = "test_password set to $finalPwd";
    } else {
        $out['steps'][] = "test_password kept existing (" . substr($finalPwd, 0, 4) . "***)";
    }

    // mode_test = 1 (active)
    $pdo->prepare("INSERT INTO settings (key_name, value) VALUES (?, '1') ON DUPLICATE KEY UPDATE value = '1'")
        ->execute(['mode_test']);
    $out['steps'][] = "mode_test = 1";

    // 3. user test@ocre.immo
    $st = $pdo->prepare("SELECT id, role FROM users WHERE email = 'test@ocre.immo' LIMIT 1");
    $st->execute();
    $u = $st->fetch();
    if ($u) {
        $testUid = (int)$u['id'];
        $out['steps'][] = "user test@ocre.immo existant id=$testUid role=" . $u['role'];
        // Force role visiteur (le plus restreint dispo, sinon agent)
        $rolesAvail = $pdo->query("SELECT DISTINCT role FROM users WHERE role IS NOT NULL")->fetchAll(PDO::FETCH_COLUMN);
        $targetRole = in_array('visiteur', $rolesAvail, true) ? 'visiteur' : 'agent';
        $pdo->prepare("UPDATE users SET role = ?, active = 1, prenom = 'Compte', nom = 'Démo', password_hash = 'MODE_TEST_NO_LOGIN' WHERE id = ?")
            ->execute([$targetRole, $testUid]);
        $out['steps'][] = "user test@ocre.immo role=$targetRole";
    } else {
        $rolesAvail = $pdo->query("SELECT DISTINCT role FROM users WHERE role IS NOT NULL")->fetchAll(PDO::FETCH_COLUMN);
        $targetRole = in_array('visiteur', $rolesAvail, true) ? 'visiteur' : 'agent';
        $ins = $pdo->prepare("INSERT INTO users (email, prenom, nom, role, active, password_hash, created_at)
                              VALUES ('test@ocre.immo', 'Compte', 'Démo', ?, 1, 'MODE_TEST_NO_LOGIN', NOW())");
        $ins->execute([$targetRole]);
        $testUid = (int)$pdo->lastInsertId();
        $out['steps'][] = "user test@ocre.immo cree id=$testUid role=$targetRole";
    }

    // 4. Purge anciens dossiers du compte test
    $del = $pdo->prepare("DELETE FROM clients WHERE user_id = ?");
    $del->execute([$testUid]);
    $out['steps'][] = "purge anciens dossiers count=" . $del->rowCount();

    // 5. Seed 7 dossiers
    $unsplash = [
        'https://images.unsplash.com/photo-1564013799919-ab600027ffc6?auto=format&fit=crop&w=900&q=70',
        'https://images.unsplash.com/photo-1545324418-cc1a3fa10c00?auto=format&fit=crop&w=900&q=70',
        'https://images.unsplash.com/photo-1518883240988-d4cdcec1e8e3?auto=format&fit=crop&w=900&q=70',
        'https://images.unsplash.com/photo-1568605114967-8130f3a36994?auto=format&fit=crop&w=900&q=70',
        'https://images.unsplash.com/photo-1502672260266-1c1ef2d93688?auto=format&fit=crop&w=900&q=70',
        'https://images.unsplash.com/photo-1600585154340-be6161a56a0c?auto=format&fit=crop&w=900&q=70',
        'https://images.unsplash.com/photo-1605276374104-dee2a0ed3cd6?auto=format&fit=crop&w=900&q=70',
        'https://images.unsplash.com/photo-1512917774080-9991f1c4c750?auto=format&fit=crop&w=900&q=70',
        'https://images.unsplash.com/photo-1502005229762-cf1b2da7c5d6?auto=format&fit=crop&w=900&q=70',
        'https://images.unsplash.com/photo-1613490493576-7fde63acd811?auto=format&fit=crop&w=900&q=70',
    ];
    $pickPhotos = function (int $n, int $offset = 0) use ($unsplash) {
        $out = [];
        for ($i = 0; $i < $n; $i++) $out[] = $unsplash[($offset + $i) % count($unsplash)];
        return $out;
    };

    $dossiers = [
        // 1. Acheteur particulier Paris
        [
            'prenom' => 'Thomas', 'nom' => 'Lefebvre', 'tel' => '+33614253647', 'email' => 'thomas.lefebvre@gmail.com',
            'projet' => 'Acheteur', 'profil_type' => 'Particulier', 'is_investisseur' => 0,
            'pays_residence' => 'France', 'nationalite' => 'France', 'langue' => 'FR', 'canal' => 'Email', 'origine' => 'Recommandation',
            'budget_max' => 1800000,
            'bien' => [
                'type' => 'Appartement', 'types' => ['Appartement'], 'pays' => 'FR', 'ville' => 'Paris', 'quartier' => 'XVIe — Auteuil',
                'surface' => 100, 'pieces' => 4, 'chambres' => 3, 'sdb' => 2, 'etage' => 4, 'ascenseur' => true,
                'orientation' => 'Sud-Ouest', 'balcon_terrasse' => true, 'parking' => true, 'cave' => true,
                'equipements' => ['ascenseur','balcon','parking','cave','cuisine_equipee'],
                'photos' => $pickPhotos(6, 0),
            ],
            'financement' => [
                'budget_total' => 1800000, 'apport' => 450000, 'devise' => '€',
                'mode' => 'classique', 'duree_annees' => 20, 'taux_ou_marge' => 3.5,
                'banque' => ['nom' => 'BNP Paribas', 'agence' => 'Auteuil'],
                'statut_dossier' => 'pre-accord',
            ],
            'payment_plan' => [['id' => 'pp_t1', 'amount' => 1800000, 'currency' => 'EUR', 'method' => 'wire']],
            'received_payments' => [],
            'etape' => 'visite', 'score' => 78,
            'notes' => "Famille avec 2 enfants (8 et 11 ans). Recherche secteur calme proche écoles.\nSensible au charme ancien (parquet, moulures), accepte travaux légers.",
        ],
        // 2. Acheteur investisseur Marrakech
        [
            'prenom' => 'Sarah', 'nom' => 'Bensalem', 'tel' => '+33677889911', 'email' => 'sarah.bensalem@orange.fr',
            'projet' => 'Investisseur', 'profil_type' => 'Particulier', 'is_investisseur' => 1,
            'pays_residence' => 'France', 'nationalite' => 'France/Maroc', 'langue' => 'FR', 'canal' => 'WhatsApp', 'origine' => 'Salon immobilier',
            'budget_max' => 400000, 'rendement_cible' => 7,
            'bien' => [
                'type' => 'Riad', 'types' => ['Riad','Appartement'], 'pays' => 'MA', 'ville' => 'Marrakech', 'quartier' => 'Médina / Palmeraie',
                'surface' => 180, 'pieces' => 5, 'chambres' => 3, 'sdb' => 2,
                'equipements' => ['piscine','terrasse','patio','climatisation','vue_atlas'],
                'photos' => $pickPhotos(7, 1),
            ],
            'financement' => [
                'budget_total' => 400000, 'apport' => 400000, 'devise' => '€',
                'mode' => 'comptant',
                'horizon_annees' => 5, 'rendement_cible' => 7,
                'statut_dossier' => 'recherche-active',
            ],
            'payment_plan' => [['id' => 'pp_s1', 'amount' => 400000, 'currency' => 'EUR', 'method' => 'wire']],
            'received_payments' => [],
            'etape' => 'qualifie', 'score' => 84,
            'notes' => "Investisseuse expérimentée (déjà 2 lots Casablanca). Cible riad rentable Airbnb haut de gamme.\nHorizon 5 ans avec revente. Disponibilité immédiate pour visiter.",
        ],
        // 3. Vendeur particulier Gordes
        [
            'prenom' => 'Famille', 'nom' => 'Moreau', 'tel' => '+33490722115', 'email' => 'pierre.moreau@gmail.com',
            'projet' => 'Vendeur', 'profil_type' => 'Particulier', 'is_investisseur' => 0,
            'pays_residence' => 'France', 'nationalite' => 'France', 'langue' => 'FR', 'canal' => 'Téléphone', 'origine' => 'Bouche-à-oreille',
            'prix_affiche' => 2400000, 'prix_plancher' => 2100000,
            'bien' => [
                'type' => 'Villa', 'types' => ['Villa','Mas'], 'pays' => 'FR', 'ville' => 'Gordes', 'quartier' => 'Lubéron',
                'surface' => 320, 'surface_terrain' => 6500, 'pieces' => 8, 'chambres' => 5, 'sdb' => 4,
                'equipements' => ['piscine','jardin','terrasse','parking','cheminee','cuisine_equipee','vue_panoramique'],
                'photos' => $pickPhotos(9, 2),
            ],
            'financement' => [
                'prix_affiche' => 2400000, 'prix_plancher' => 2100000, 'devise' => '€',
                'frais_pct' => 4, 'frais_montant' => 96000,
                'statut_dossier' => 'mandat-exclusif',
            ],
            'payment_plan' => [['id' => 'pp_m1', 'amount' => 2400000, 'currency' => 'EUR', 'method' => 'wire']],
            'received_payments' => [],
            'etape' => 'offre', 'score' => 81,
            'notes' => "Bien familial, vente après héritage. Prix plancher CONFIDENTIEL 2.1M€.\n3 offres reçues à 2.0-2.15M€. Pierre et Anne Moreau cohéritiers.",
        ],
        // 4. Vendeur société Aix-en-Provence
        [
            'prenom' => '', 'nom' => '', 'societe_nom' => 'SCI Lavender', 'tel' => '+33442271415', 'email' => 'contact@sci-lavender.fr',
            'projet' => 'Vendeur', 'profil_type' => 'Société', 'is_investisseur' => 0,
            'societe_siret' => '88412566700014', 'societe_forme' => 'SCI',
            'pays_residence' => 'France', 'langue' => 'FR', 'canal' => 'Email', 'origine' => 'Site web',
            'prix_affiche' => 680000,
            'bien' => [
                'type' => 'Appartement', 'types' => ['Appartement'], 'pays' => 'FR', 'ville' => 'Aix-en-Provence', 'quartier' => 'Mazarin',
                'surface' => 95, 'pieces' => 4, 'chambres' => 3, 'sdb' => 1, 'etage' => 2, 'ascenseur' => false,
                'equipements' => ['parquet_chevron','moulures','cheminee','cave'],
                'photos' => $pickPhotos(7, 3),
            ],
            'financement' => [
                'prix_affiche' => 680000, 'devise' => '€',
                'frais_pct' => 5, 'frais_montant' => 34000,
                'statut_dossier' => 'mandat-simple',
            ],
            'payment_plan' => [['id' => 'pp_l1', 'amount' => 680000, 'currency' => 'EUR', 'method' => 'wire']],
            'received_payments' => [],
            'etape' => 'qualifie', 'score' => 65,
            'notes' => "SCI familiale. Appart de standing centre-ville Aix.\nGérant : M. Verdier. Disponible 1er juillet. Travaux 2018 (cuisine + sdb).",
        ],
        // 5. Locataire Lyon
        [
            'prenom' => 'Julien', 'nom' => 'Marchetti', 'tel' => '+33687654312', 'email' => 'jmarchetti@laposte.net',
            'projet' => 'Locataire', 'profil_type' => 'Particulier', 'is_investisseur' => 0,
            'pays_residence' => 'France', 'nationalite' => 'France', 'langue' => 'FR', 'canal' => 'WhatsApp', 'origine' => 'LeBonCoin',
            'loyer_max' => 1600, 'garants' => 'Parents (CDI cadres)',
            'bien' => [
                'type' => 'Appartement', 'types' => ['Appartement','T3'], 'pays' => 'FR', 'ville' => 'Lyon', 'quartier' => 'Presqu\'île — Cordeliers',
                'surface' => 65, 'pieces' => 3, 'chambres' => 2, 'sdb' => 1, 'etage' => 3, 'ascenseur' => true,
                'equipements' => ['ascenseur','interphone','parquet','double_vitrage'],
                'photos' => $pickPhotos(4, 4),
            ],
            'financement' => [
                'loyer_max' => 1600, 'devise' => '€', 'charges_max' => 80,
                'statut_dossier' => 'recherche-active',
            ],
            'payment_plan' => [],
            'received_payments' => [],
            'etape' => 'prospect', 'score' => 58,
            'notes' => "Ingénieur 28 ans, CDI Sanofi Gerland. Salaire 3.2k€ net. Cherche T3 max Cordeliers/Bellecour.\nDisponible 1er septembre. Sans animaux, non-fumeur.",
        ],
        // 6. Bailleur Bordeaux
        [
            'prenom' => 'Claire', 'nom' => 'Dupont', 'tel' => '+33556813327', 'email' => 'claire.dupont@wanadoo.fr',
            'projet' => 'Bailleur', 'profil_type' => 'Particulier', 'is_investisseur' => 0,
            'pays_residence' => 'France', 'nationalite' => 'France', 'langue' => 'FR', 'canal' => 'Téléphone', 'origine' => 'Recommandation notaire',
            'loyer_demande' => 2200, 'charges' => 150, 'depot' => 4400,
            'bien' => [
                'type' => 'Maison', 'types' => ['Maison'], 'pays' => 'FR', 'ville' => 'Bordeaux', 'quartier' => 'Chartrons',
                'surface' => 130, 'surface_terrain' => 200, 'pieces' => 5, 'chambres' => 3, 'sdb' => 2,
                'equipements' => ['jardin','terrasse','cheminee','cuisine_equipee','double_vitrage','garage'],
                'photos' => $pickPhotos(8, 5),
            ],
            'financement' => [
                'loyer_demande' => 2200, 'charges' => 150, 'depot' => 4400, 'devise' => '€',
                'duree_bail' => 36, 'meuble' => false,
                'statut_dossier' => 'a-louer',
            ],
            'payment_plan' => [],
            'received_payments' => [],
            'etape' => 'qualifie', 'score' => 72,
            'notes' => "Maison de famille, propriétaire installée Paris. Privilégie locataire CDI stable longue durée.\nVisites le samedi via gardien. Disponibilité mi-mai.",
        ],
        // 7. Bailleur investisseur (société) Ajaccio
        [
            'prenom' => '', 'nom' => '', 'societe_nom' => 'Groupe Ibiza Holdings', 'tel' => '+33495210414', 'email' => 'gestion@ibizaholdings.fr',
            'projet' => 'Bailleur', 'profil_type' => 'Société', 'is_investisseur' => 1,
            'societe_siret' => '79445288100023', 'societe_forme' => 'SARL',
            'pays_residence' => 'France', 'langue' => 'FR', 'canal' => 'Email', 'origine' => 'Apporteur d\'affaires',
            'loyer_demande' => 5400, 'charges' => 320,
            'bien' => [
                'type' => 'Lot multiple', 'types' => ['Appartement','Local commercial'], 'pays' => 'FR', 'ville' => 'Ajaccio', 'quartier' => 'Vieux-Port',
                'surface' => 220, 'pieces' => 9, 'chambres' => 5, 'sdb' => 3, 'lots' => 3,
                'equipements' => ['climatisation','vue_mer','balcon','parking','double_vitrage'],
                'photos' => $pickPhotos(10, 6),
            ],
            'financement' => [
                'loyer_demande' => 5400, 'charges' => 320, 'devise' => '€',
                'rendement_actuel' => 5.8, 'rendement_cible' => 6.5,
                'statut_dossier' => 'optimisation',
            ],
            'payment_plan' => [
                ['id' => 'pp_i1', 'amount' => 65000, 'currency' => 'EUR', 'method' => 'wire'],
            ],
            'received_payments' => [
                ['id' => 'rp_i1', 'date' => date('Y-m-d', strtotime('-2 month')), 'amount' => 5400, 'currency' => 'EUR', 'method' => 'wire'],
                ['id' => 'rp_i2', 'date' => date('Y-m-d', strtotime('-1 month')), 'amount' => 5400, 'currency' => 'EUR', 'method' => 'wire'],
            ],
            'etape' => 'compromis', 'score' => 88,
            'notes' => "3 lots Vieux-Port. Rendement actuel 5.8% net, cible 6.5% via réajustement loyers + court terme saisonnier.\nGérant Pierre Castellani. Bilan annuel chaque janvier.",
        ],
    ];

    $created = [];
    foreach ($dossiers as $d) {
        $d['tels'] = $d['tel'] ? [['label' => 'Principal', 'valeur' => $d['tel'], 'primary' => true]] : [];
        $d['emails'] = $d['email'] ? [['label' => 'Principal', 'valeur' => $d['email'], 'primary' => true]] : [];
        $is_investisseur = (int)($d['is_investisseur'] ?? 0);
        $data = json_encode($d, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $payment_plan_json = json_encode($d['payment_plan'] ?? [], JSON_UNESCAPED_UNICODE);
        $received_payments_json = json_encode($d['received_payments'] ?? [], JSON_UNESCAPED_UNICODE);
        $stmt = $pdo->prepare(
            "INSERT INTO clients (user_id, data, projet, is_investisseur, archived, is_draft, prenom, nom, societe_nom, tel, email, payment_plan, received_payments, created_at, updated_at)
             VALUES (?, ?, ?, ?, 0, 0, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())"
        );
        $stmt->execute([
            $testUid, $data, $d['projet'], $is_investisseur,
            $d['prenom'] ?? '', $d['nom'] ?? '', $d['societe_nom'] ?? '',
            $d['tel'] ?? '', $d['email'] ?? '',
            $payment_plan_json, $received_payments_json,
        ]);
        $cid = (int)$pdo->lastInsertId();
        $label = trim(($d['prenom'] ?? '') . ' ' . ($d['nom'] ?? '')) ?: ($d['societe_nom'] ?? '');
        $created[] = ['id' => $cid, 'label' => $label, 'projet' => $d['projet'], 'invest' => $is_investisseur];
    }

    $verif = $pdo->prepare("SELECT COUNT(*) FROM clients WHERE user_id = ? AND archived = 0");
    $verif->execute([$testUid]);
    $count = (int)$verif->fetchColumn();

    $out['ok'] = true;
    $out['test_password'] = $finalPwd;
    $out['test_user_id'] = $testUid;
    $out['dossiers_seeded'] = count($created);
    $out['count_in_db'] = $count;
    $out['created'] = $created;
} catch (Throwable $e) {
    $out['ok'] = false;
    $out['error'] = $e->getMessage();
    $out['trace'] = $e->getTraceAsString();
}
echo json_encode($out, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
