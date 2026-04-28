<?php
// M/2026/04/28/16 — Helpers seeder dossiers démo mode test.
// M/2026/04/28/23 — Refonte panier v2 : 10 dossiers ultra-réalistes Marrakech
// (Bouzid, El Fassi, Atlas Invest, Tahiri, Dubois, Médina Heritage, Benhima,
// Lemoine, Oufkir, Atlas Riads). 80%+ champs remplis, prix et quartiers réalistes
// 2025. 3 paires matchantes 95/75/50 %. Suppression franche des seed_ids v1
// (préfixe seed-2026-04-28-pair*/libre*) — remplacés par préfixe seed-2026-04-28-v2-*.
require_once __DIR__ . '/router.php';

function seedDossiersV1(): array {
    return [
      // === Paire 1 (~95 %) — Bouzid acheteur ↔ El Fassi vendeur, villa Palmeraie ===
      [ 'seed_id' => 'seed-2026-04-28-v2-pair1-bouzid-acheteur-villa-palmeraie',
        'projet' => 'Acheteur', 'is_investisseur' => 0,
        'prenom' => 'Karim et Yasmina', 'nom' => 'Bouzid', 'societe_nom' => null,
        'tel' => '+33 6 21 47 89 02', 'email' => 'karim.bouzid@example.com',
        'vertical' => 'vente',
        'data' => [
          'profil_type' => 'Particulier', 'projet' => 'Acheteur',
          'nationalite' => 'FR', 'pays_residence' => 'FR', 'langue' => 'FR',
          'canal_prefere' => 'WhatsApp', 'origine' => 'recommandation amie',
          'budget_min' => 7000000, 'budget_max' => 8000000, 'mode_financement' => 'cash',
          'bien' => [
            'pays' => 'MA', 'types' => ['Villa'], 'type' => 'Villa',
            'ville' => 'Marrakech', 'quartier' => 'Palmeraie', 'rayon_km' => 5,
            'chambres' => 4, 'sdb' => 3, 'surface' => 300, 'surface_terrain' => 1500,
            'etat' => 'récent', 'exposition' => 'sud',
            'equipements' => ['piscine' => true, 'parking' => 2, 'jardin' => 1500, 'terrasse' => 60, 'climatisation' => true],
          ],
          'notes' => 'Couple FR pour résidence secondaire familiale, séjours réguliers Marrakech. Cherche villa Palmeraie avec piscine chauffée et grand jardin paysager. Visite en mai prévue.',
        ],
      ],
      [ 'seed_id' => 'seed-2026-04-28-v2-pair1-elfassi-vendeur-villa-palmeraie',
        'projet' => 'Vendeur', 'is_investisseur' => 0,
        'prenom' => 'Hassan et Naima', 'nom' => 'El Fassi', 'societe_nom' => null,
        'tel' => '+212 6 61 78 23 45', 'email' => 'hassan.elfassi@example.com',
        'vertical' => 'vente',
        'data' => [
          'profil_type' => 'Particulier', 'projet' => 'Vendeur',
          'nationalite' => 'MA', 'pays_residence' => 'MA', 'langue' => 'FR',
          'canal_prefere' => 'Tel', 'origine' => 'contact direct',
          'prix_affiche' => 8200000, 'prix_plancher' => 7900000, 'devise' => 'MAD',
          'charges' => '1500 MAD/mois (gardien + entretien jardin)',
          'bien' => [
            'pays' => 'MA', 'types' => ['Villa'], 'type' => 'Villa',
            'ville' => 'Marrakech', 'quartier' => 'Palmeraie',
            'chambres' => 4, 'sdb' => 3, 'surface' => 320, 'surface_terrain' => 1700, 'surface_annexes' => 60,
            'annee_construction' => 2018, 'etat' => 'récent', 'exposition' => 'sud-ouest',
            'equipements' => ['piscine' => '50 m² chauffée', 'parking' => 2, 'jardin' => 1500, 'terrasse' => 80, 'climatisation' => true, 'cheminee' => true, 'cuisine_equipee' => true],
            'photos' => ['https://images.unsplash.com/photo-1564013799919-ab600027ffc6?w=600&auto=format', 'https://images.unsplash.com/photo-1613490493576-7fde63acd811?w=600&auto=format', 'https://images.unsplash.com/photo-1568605114967-8130f3a36994?w=600&auto=format', 'https://images.unsplash.com/photo-1600596542815-ffad4c1539a9?w=600&auto=format', 'https://images.unsplash.com/photo-1571055107559-3e67626fa8be?w=600&auto=format']
          ],
          'notes' => 'Vente villa Palmeraie suite départ Émirats Arabes Unis. Bien entretenu, jardin paysagé palmiers et oliviers, piscine chauffée. Sécurité 24/7 résidence privée. Disponibilité immédiate.',
        ],
      ],

      // === Paire 2 (~75 %) — Atlas Invest acheteur ↔ Tahiri vendeur, appt Casa ===
      [ 'seed_id' => 'seed-2026-04-28-v2-pair2-atlasinvest-acheteur-appt-anfa',
        'projet' => 'Acheteur', 'is_investisseur' => 0,
        'prenom' => null, 'nom' => null, 'societe_nom' => 'Atlas Invest SARL',
        'tel' => '+212 5 22 49 71 33', 'email' => 'contact@atlas-invest.example.com',
        'vertical' => 'vente',
        'data' => [
          'profil_type' => 'Société', 'projet' => 'Acheteur',
          'forme_juridique' => 'SARL', 'rc' => 'RC Casablanca 458921',
          'representant_legal' => 'M. Younes Berrada (DG)',
          'representant_prenom' => 'Younes', 'representant_nom' => 'Berrada', 'representant_fonction' => 'Directeur général',
          'nationalite' => 'MA', 'pays_residence' => 'MA', 'langue' => 'FR',
          'canal_prefere' => 'Email', 'origine' => 'salon immobilier Casablanca',
          'budget_min' => 3500000, 'budget_max' => 4000000, 'mode_financement' => 'cash société',
          'bien' => [
            'pays' => 'MA', 'types' => ['Appartement'], 'type' => 'Appartement',
            'ville' => 'Casablanca', 'quartier' => 'Anfa', 'rayon_km' => 3,
            'chambres' => 3, 'sdb' => 2, 'surface' => 150, 'etage' => 'élevé',
            'etat' => 'neuf ou récent',
            'equipements' => ['parking' => 1, 'cave' => true, 'ascenseur' => true, 'climatisation' => true, 'balcon' => 10, 'vue' => 'mer'],
          ],
          'notes' => 'Logement de fonction directeur général. Standing élevé exigé, immeuble sécurisé proche Twin Center. Acquisition par la société (avantage fiscal).',
        ],
      ],
      [ 'seed_id' => 'seed-2026-04-28-v2-pair2-tahiri-vendeur-appt-bourgogne',
        'projet' => 'Vendeur', 'is_investisseur' => 0,
        'prenom' => 'Mohammed', 'nom' => 'Tahiri', 'societe_nom' => null,
        'tel' => '+212 6 61 12 47 88', 'email' => 'mohammed.tahiri@example.com',
        'vertical' => 'vente',
        'data' => [
          'profil_type' => 'Particulier', 'projet' => 'Vendeur',
          'nationalite' => 'MA', 'pays_residence' => 'MA', 'langue' => 'AR/FR',
          'canal_prefere' => 'WhatsApp', 'origine' => 'notaire',
          'prix_affiche' => 3800000, 'prix_plancher' => 3600000, 'devise' => 'MAD',
          'charges' => '800 MAD/mois (syndic)',
          'bien' => [
            'pays' => 'MA', 'types' => ['Appartement'], 'type' => 'Appartement',
            'ville' => 'Casablanca', 'quartier' => 'Bourgogne',
            'chambres' => 3, 'sdb' => 2, 'surface' => 165, 'etage' => '5e/8',
            'annee_construction' => 2010, 'etat' => 'bon état général',
            'equipements' => ['balcon' => 12, 'parking' => '1 sous-sol sécurisé', 'cave' => true, 'ascenseur' => true, 'climatisation' => true],
            'photos' => ['https://images.unsplash.com/photo-1502672260266-1c1ef2d93688?w=600&auto=format', 'https://images.unsplash.com/photo-1600585154340-be6161a56a0c?w=600&auto=format', 'https://images.unsplash.com/photo-1565183997392-2f6f122e5912?w=600&auto=format', 'https://images.unsplash.com/photo-1551782450-a2132b4ba21d?w=600&auto=format', 'https://images.unsplash.com/photo-1600210492493-0946911123c4?w=600&auto=format']
          ],
          'notes' => 'Vente succession parents. Appartement spacieux Bourgogne, balcon 12 m² agréable, parking sous-sol. Vente sans urgence, négociable.',
        ],
      ],

      // === Paire 3 (~50 %) — Dubois investisseur ↔ Médina Heritage vendeur, Essaouira ===
      [ 'seed_id' => 'seed-2026-04-28-v2-pair3-dubois-investisseur-essaouira',
        'projet' => 'Investisseur', 'is_investisseur' => 1,
        'prenom' => 'Olivier', 'nom' => 'Dubois', 'societe_nom' => null,
        'tel' => '+33 6 78 14 22 91', 'email' => 'olivier.dubois@example.com',
        'vertical' => 'vente',
        'data' => [
          'profil_type' => 'Particulier', 'projet' => 'Investisseur',
          'nationalite' => 'FR', 'pays_residence' => 'FR', 'langue' => 'FR',
          'canal_prefere' => 'Email', 'origine' => 'web',
          'budget_min' => 1500000, 'budget_max' => 2000000, 'mode_financement' => 'cash + crédit FR',
          'rendement_cible_pct' => 6, 'horizon_ans' => 10,
          'bien' => [
            'pays' => 'MA', 'types' => ['Riad', 'Appartement'], 'type' => 'Riad',
            'ville' => 'Essaouira', 'quartier' => 'Centre', 'rayon_km' => 2,
            'chambres' => 3, 'surface' => 130,
            'etat' => 'rénové ou récent',
            'photos' => ['https://images.unsplash.com/photo-1545159449-cc7afd62cd29?w=600&auto=format']
          ],
          'notes' => 'Investisseur expérimenté, déjà 2 biens locatifs Lyon. Vise rendement net 6 % par an, location courte durée Airbnb. Ouvert riad ou appartement bien placé centre Essaouira.',
        ],
      ],
      [ 'seed_id' => 'seed-2026-04-28-v2-pair3-medinaheritage-vendeur-riad-essaouira',
        'projet' => 'Vendeur', 'is_investisseur' => 0,
        'prenom' => null, 'nom' => null, 'societe_nom' => 'Médina Heritage SAS',
        'tel' => '+212 5 24 47 12 34', 'email' => 'contact@medina-heritage.example.com',
        'vertical' => 'vente',
        'data' => [
          'profil_type' => 'Société', 'projet' => 'Vendeur',
          'forme_juridique' => 'SAS', 'rc' => 'RC Essaouira 12483',
          'representant_legal' => 'Mme Caroline Vasseur (Présidente)',
          'representant_prenom' => 'Caroline', 'representant_nom' => 'Vasseur', 'representant_fonction' => 'Présidente',
          'nationalite' => 'FR/MA', 'pays_residence' => 'MA', 'langue' => 'FR/EN',
          'canal_prefere' => 'Email', 'origine' => 'contact direct',
          'prix_affiche' => 2400000, 'prix_plancher' => 2250000, 'devise' => 'MAD',
          'charges' => '1200 MAD/mois (entretien + taxe médina)',
          'bien' => [
            'pays' => 'MA', 'types' => ['Riad'], 'type' => 'Riad',
            'ville' => 'Essaouira', 'quartier' => 'Médina',
            'chambres' => 4, 'sdb' => 4, 'surface' => 220,
            'annee_construction' => 1920, 'etat' => 'rénové 2022',
            'equipements' => ['patio' => 30, 'terrasse' => '80 m² panoramique', 'climatisation' => true, 'cheminee' => 2, 'vue' => 'océan + médina', 'plomberie' => 'neuve', 'electricite' => 'neuve', 'toiture' => 'refaite'],
            'photos' => ['https://images.unsplash.com/photo-1606047773143-6e7906ee4ff8?w=600&auto=format', 'https://images.unsplash.com/photo-1597212618440-806262de4f6b?w=600&auto=format', 'https://images.unsplash.com/photo-1591375275621-ec3e7d9c66f8?w=600&auto=format', 'https://images.unsplash.com/photo-1554995207-c18c203602cb?w=600&auto=format', 'https://images.unsplash.com/photo-1568084680786-a84f91d1153c?w=600&auto=format']
          ],
          'notes' => 'Riad médina rénové 2022 (chaux + tadelakt). Toiture refaite, plomberie/électricité neuves. Idéal maison d\'hôtes ou résidence secondaire luxe. Licence touristique transférable.',
        ],
      ],

      // === Dossiers libres (4) ===
      [ 'seed_id' => 'seed-2026-04-28-v2-libre-benhima-bailleur-gueliz',
        'projet' => 'Bailleur', 'is_investisseur' => 0,
        'prenom' => 'Fatima', 'nom' => 'Benhima', 'societe_nom' => null,
        'tel' => '+212 6 61 33 78 41', 'email' => 'fatima.benhima@example.com',
        'vertical' => 'location_longue',
        'data' => [
          'profil_type' => 'Particulier', 'projet' => 'Bailleur',
          'nationalite' => 'MA', 'pays_residence' => 'MA', 'langue' => 'FR/AR',
          'canal_prefere' => 'WhatsApp', 'origine' => 'bouche-à-oreille',
          'loyer_demande' => 12000, 'devise' => 'MAD',
          'charges' => 'incluses (eau + syndic)',
          'depot' => '24000 MAD (2 mois)', 'duree_min_mois' => 12, 'mode_paiement' => 'virement mensuel',
          'bien' => [
            'pays' => 'MA', 'types' => ['Appartement'], 'type' => 'Appartement',
            'ville' => 'Marrakech', 'quartier' => 'Guéliz',
            'chambres' => 2, 'sdb' => 2, 'surface' => 95, 'etage' => '3e/5',
            'annee_construction' => 2015, 'etat' => 'meublé moderne',
            'equipements' => ['balcon' => 8, 'parking' => 1, 'climatisation' => true, 'ascenseur' => true, 'meuble' => true, 'cuisine_equipee' => true, 'sdb_refaite' => 2023],
            'photos' => ['https://images.unsplash.com/photo-1522708323590-d24dbb6b0267?w=600&auto=format', 'https://images.unsplash.com/photo-1505691938895-1758d7feb511?w=600&auto=format', 'https://images.unsplash.com/photo-1586023492125-27b2c045efd7?w=600&auto=format', 'https://images.unsplash.com/photo-1567016526105-22da7c13161a?w=600&auto=format', 'https://images.unsplash.com/photo-1560448204-e02f11c3d0e2?w=600&auto=format']
          ],
          'notes' => 'Appartement meublé Guéliz proche Carré Eden, restaurants et commerces. Cuisine équipée, salle de bain refaite 2023. Préférence locataire CDI ou expat avec garants.',
        ],
      ],
      [ 'seed_id' => 'seed-2026-04-28-v2-libre-lemoine-locataire-gueliz',
        'projet' => 'Locataire', 'is_investisseur' => 0,
        'prenom' => 'Sébastien', 'nom' => 'Lemoine', 'societe_nom' => null,
        'tel' => '+33 6 84 22 17 56', 'email' => 'sebastien.lemoine@example.com',
        'vertical' => 'location_longue',
        'data' => [
          'profil_type' => 'Particulier', 'projet' => 'Locataire',
          'nationalite' => 'FR', 'pays_residence' => 'MA (en cours)', 'langue' => 'FR/EN',
          'canal_prefere' => 'WhatsApp', 'origine' => 'web (groupe expats)',
          'loyer_max' => 14000, 'devise' => 'MAD', 'charges_max' => 'incluses si possible',
          'duree_souhaitee' => 'longue durée 2-3 ans', 'non_fumeur' => true, 'animaux' => false,
          'profession' => 'Lead Developer SaaS (full remote)', 'employeur' => 'Berlin tech scale-up',
          'revenus_mensuels_eq_mad' => 65000, 'garants' => 'employeur + caution bancaire',
          'bien' => [
            'pays' => 'MA', 'types' => ['Appartement'], 'type' => 'Appartement',
            'ville' => 'Marrakech', 'quartier' => 'Guéliz',
            'chambres' => 2, 'surface' => 80,
            'equipements_souhaites' => ['meuble' => true, 'climatisation' => true, 'parking' => true, 'fibre' => true],
          ],
          'notes' => 'Expat IT français, contrat full remote SaaS Berlin. Cherche appartement Guéliz longue durée pour s\'installer Marrakech. Discret, non-fumeur, sans animaux. Caution bancaire + garantie employeur disponibles.',
        ],
      ],
      [ 'seed_id' => 'seed-2026-04-28-v2-libre-oufkir-acheteur-villa-hivernage',
        'projet' => 'Acheteur', 'is_investisseur' => 0,
        'prenom' => 'Yassine', 'nom' => 'Oufkir', 'societe_nom' => null,
        'tel' => '+212 6 61 92 14 78', 'email' => 'yassine.oufkir@example.com',
        'vertical' => 'vente',
        'data' => [
          'profil_type' => 'Particulier', 'projet' => 'Acheteur',
          'nationalite' => 'MA', 'pays_residence' => 'MA', 'langue' => 'FR/AR',
          'canal_prefere' => 'Tel', 'origine' => 'agence partenaire',
          'budget_min' => 6000000, 'budget_max' => 6500000, 'mode_financement' => 'crédit + cash 50/50',
          'bien' => [
            'pays' => 'MA', 'types' => ['Villa'], 'type' => 'Villa',
            'ville' => 'Marrakech', 'quartier' => 'Hivernage',
            'chambres' => 5, 'sdb' => 4, 'surface' => 400, 'surface_terrain' => 800,
            'etat' => 'récent ou bon état',
            'equipements' => ['piscine' => true, 'parking' => 3, 'jardin' => 600, 'terrasse' => 100, 'climatisation' => true],
          ],
          'notes' => 'Famille marocaine 5 personnes (3 enfants en école internationale). Cherche villa Hivernage proche écoles. Piscine non-négociable, jardin sécurisé pour enfants. Acquisition résidence principale.',
        ],
      ],
      [ 'seed_id' => 'seed-2026-04-28-v2-libre-atlasriads-vendeur-riad-medina',
        'projet' => 'Vendeur', 'is_investisseur' => 0,
        'prenom' => null, 'nom' => null, 'societe_nom' => 'Atlas Riads SARL',
        'tel' => '+212 5 24 38 90 12', 'email' => 'contact@atlas-riads.example.com',
        'vertical' => 'vente',
        'data' => [
          'profil_type' => 'Société', 'projet' => 'Vendeur',
          'forme_juridique' => 'SARL', 'rc' => 'RC Marrakech 89472',
          'representant_legal' => 'M. Karim Bensouda (Gérant)',
          'representant_prenom' => 'Karim', 'representant_nom' => 'Bensouda', 'representant_fonction' => 'Gérant',
          'nationalite' => 'MA', 'pays_residence' => 'MA', 'langue' => 'FR/EN',
          'canal_prefere' => 'Email', 'origine' => 'web',
          'prix_affiche' => 5800000, 'prix_plancher' => 5500000, 'devise' => 'MAD',
          'vente_meublee' => true, 'charges' => '1500 MAD/mois',
          'bien' => [
            'pays' => 'MA', 'types' => ['Riad'], 'type' => 'Riad',
            'ville' => 'Marrakech', 'quartier' => 'Médina',
            'chambres' => 6, 'sdb' => 6, 'surface' => 280, 'surface_terrain' => 350,
            'annee_construction' => 1900, 'etat' => 'rénové 2023',
            'equipements' => ['patio' => 25, 'plunge_pool' => true, 'roof_terrasse' => 90, 'climatisation' => true, 'meuble' => true, 'vue' => 'Atlas + médina', 'licence_touristique' => 'en cours de transfert'],
            'photos' => ['https://images.unsplash.com/photo-1542315192-1f61a1792f33?w=600&auto=format', 'https://images.unsplash.com/photo-1611892440504-42a792e24d32?w=600&auto=format', 'https://images.unsplash.com/photo-1616594039964-ae9021a400a0?w=600&auto=format', 'https://images.unsplash.com/photo-1582719478250-c89cae4dc85b?w=600&auto=format', 'https://images.unsplash.com/photo-1590490360182-c33d57733427?w=600&auto=format']
          ],
          'notes' => 'Riad médina rénové 2023, projet vente complet meublé prêt à exploitation maison d\'hôtes. 6 suites, plunge pool patio, roof terrasse 90 m² vue Atlas. Licence touristique en cours de transfert. Idéal investisseur pro.',
        ],
      ],
    ];
}

// M/2026/04/28/30 — buildMatchCriteres / applyCanonicalMatches supprimés
// franchement. C'est /api/matching.php?action=rejouer_complet qui calcule
// désormais les matches dynamiquement à partir des règles ocre_meta.match_rules_v1.

function ensureSeedMetaSchema(): void {
    static $done = false;
    if ($done) return;
    try {
        pdo_meta()->exec("CREATE TABLE IF NOT EXISTS seed_clients_v1 (
            seed_id VARCHAR(64) NOT NULL PRIMARY KEY,
            projet VARCHAR(40) NOT NULL,
            is_investisseur TINYINT(1) NOT NULL DEFAULT 0,
            prenom VARCHAR(100) NULL,
            nom VARCHAR(100) NULL,
            societe_nom VARCHAR(150) NULL,
            tel VARCHAR(40) NULL,
            email VARCHAR(191) NULL,
            vertical ENUM('vente','location_longue','sejour_court') NULL,
            data LONGTEXT NOT NULL
        ) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
        // M/2026/04/28/23 — UPSERT pour propager les évolutions du panier.
        $stmt = pdo_meta()->prepare(
            "INSERT INTO seed_clients_v1
                (seed_id, projet, is_investisseur, prenom, nom, societe_nom, tel, email, vertical, data)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE
                projet=VALUES(projet), is_investisseur=VALUES(is_investisseur),
                prenom=VALUES(prenom), nom=VALUES(nom), societe_nom=VALUES(societe_nom),
                tel=VALUES(tel), email=VALUES(email), vertical=VALUES(vertical),
                data=VALUES(data)"
        );
        foreach (seedDossiersV1() as $d) {
            $stmt->execute([
                $d['seed_id'], $d['projet'], (int) $d['is_investisseur'],
                $d['prenom'], $d['nom'], $d['societe_nom'],
                $d['tel'], $d['email'], $d['vertical'],
                json_encode($d['data'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            ]);
        }
    } catch (Throwable $e) { /* idempotent, silent */ }
    $done = true;
}

function ensureClientsHasSeedId(PDO $pdo): void {
    try { $pdo->exec("ALTER TABLE clients ADD COLUMN seed_id VARCHAR(64) NULL"); } catch (Throwable $e) {}
    try { $pdo->exec("ALTER TABLE clients ADD UNIQUE KEY uniq_user_seed (user_id, seed_id)"); } catch (Throwable $e) {}
}

// Idempotent : skip si (user_id, seed_id) existe déjà dans clients.
// Retour ['inserted'=>N, 'skipped'=>N, 'total'=>N].
function applySeedToTenant(PDO $tenantPdo, int $userId): array {
    ensureSeedMetaSchema();
    ensureClientsHasSeedId($tenantPdo);
    $seeds = pdo_meta()->query("SELECT * FROM seed_clients_v1")->fetchAll();
    $check = $tenantPdo->prepare("SELECT id FROM clients WHERE user_id = ? AND seed_id = ? LIMIT 1");
    $insert = $tenantPdo->prepare(
        "INSERT INTO clients
            (user_id, projet, is_investisseur, prenom, nom, societe_nom, tel, email,
             vertical, data, is_demo, is_draft, archived, seed_id, created_at, updated_at)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1, 0, 0, ?, NOW(), NOW())"
    );
    $inserted = 0; $skipped = 0;
    foreach ($seeds as $s) {
        $check->execute([$userId, $s['seed_id']]);
        if ($check->fetch()) { $skipped++; continue; }
        $insert->execute([
            $userId, $s['projet'], (int) $s['is_investisseur'],
            $s['prenom'], $s['nom'], $s['societe_nom'], $s['tel'], $s['email'],
            $s['vertical'], $s['data'], $s['seed_id'],
        ]);
        $inserted++;
    }
    return ['inserted' => $inserted, 'skipped' => $skipped, 'total' => count($seeds)];
}

