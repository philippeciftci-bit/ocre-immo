<?php
// M/2026/04/28/16 — Helpers seeder dossiers démo mode test.
// Découplés de api/seed.php (qui les require_once + dispatch HTTP) pour qu'ils
// soient appelables depuis le hook signup (auth_v20.php) sans déclencher
// requireAuth() de l'endpoint.
require_once __DIR__ . '/router.php';

// Données canoniques (10 dossiers, 3 paires matchantes 95%/75%/50% + 4 libres).
function seedDossiersV1(): array {
    return [
      [ 'seed_id' => 'seed-2026-04-28-pair1-acheteur-villa-palmeraie',
        'projet' => 'Acheteur', 'is_investisseur' => 0,
        'prenom' => 'Karim', 'nom' => 'Benjelloun', 'societe_nom' => null,
        'tel' => '+212 6 61 11 22 01', 'email' => 'karim.benjelloun@example.com',
        'vertical' => 'vente',
        'data' => [
          'profil_type' => 'Particulier', 'projet' => 'Acheteur',
          'nationalite' => 'MA', 'pays_residence' => 'MA',
          'budget_max' => 8000000,
          'bien' => [ 'pays' => 'MA', 'types' => ['Villa'], 'type' => 'Villa',
            'ville' => 'Marrakech', 'quartier' => 'Palmeraie',
            'chambres' => 4, 'surface' => 300, 'terrain' => 1500 ],
          'notes' => '[DEMO-SEED-V1] Acheteur villa Palmeraie 4ch — paire matchante 1 (~95%).' ]
      ],
      [ 'seed_id' => 'seed-2026-04-28-pair1-vendeur-villa-palmeraie',
        'projet' => 'Vendeur', 'is_investisseur' => 0,
        'prenom' => 'Amine', 'nom' => 'Tazi', 'societe_nom' => null,
        'tel' => '+212 6 61 11 22 02', 'email' => 'amine.tazi@example.com',
        'vertical' => 'vente',
        'data' => [
          'profil_type' => 'Particulier', 'projet' => 'Vendeur',
          'nationalite' => 'MA', 'pays_residence' => 'MA',
          'prix_affiche' => 8200000,
          'bien' => [ 'pays' => 'MA', 'types' => ['Villa'], 'type' => 'Villa',
            'ville' => 'Marrakech', 'quartier' => 'Palmeraie',
            'chambres' => 4, 'surface' => 320, 'terrain' => 1700 ],
          'notes' => '[DEMO-SEED-V1] Vendeur villa Palmeraie 4ch — paire matchante 1 (~95%).' ]
      ],
      [ 'seed_id' => 'seed-2026-04-28-pair2-acheteur-appt-anfa',
        'projet' => 'Acheteur', 'is_investisseur' => 0,
        'prenom' => null, 'nom' => null, 'societe_nom' => 'Anfa Invest SARL',
        'tel' => '+212 5 22 33 44 55', 'email' => 'contact@anfa-invest.example.com',
        'vertical' => 'vente',
        'data' => [
          'profil_type' => 'Société', 'projet' => 'Acheteur',
          'nationalite' => 'MA', 'pays_residence' => 'MA',
          'budget_max' => 4000000,
          'bien' => [ 'pays' => 'MA', 'types' => ['Appartement'], 'type' => 'Appartement',
            'ville' => 'Casablanca', 'quartier' => 'Anfa',
            'chambres' => 3, 'surface' => 150 ],
          'notes' => '[DEMO-SEED-V1] Société acheteur appt Anfa 3ch — paire matchante 2 (~75%).' ]
      ],
      [ 'seed_id' => 'seed-2026-04-28-pair2-vendeur-appt-bourgogne',
        'projet' => 'Vendeur', 'is_investisseur' => 0,
        'prenom' => 'Leila', 'nom' => 'El Amrani', 'societe_nom' => null,
        'tel' => '+212 6 61 22 33 03', 'email' => 'leila.elamrani@example.com',
        'vertical' => 'vente',
        'data' => [
          'profil_type' => 'Particulier', 'projet' => 'Vendeur',
          'nationalite' => 'MA', 'pays_residence' => 'MA',
          'prix_affiche' => 3800000,
          'bien' => [ 'pays' => 'MA', 'types' => ['Appartement'], 'type' => 'Appartement',
            'ville' => 'Casablanca', 'quartier' => 'Bourgogne',
            'chambres' => 3, 'surface' => 165 ],
          'notes' => '[DEMO-SEED-V1] Vendeur appt Bourgogne 3ch — paire matchante 2 (~75%).' ]
      ],
      [ 'seed_id' => 'seed-2026-04-28-pair3-investisseur-essaouira',
        'projet' => 'Investisseur', 'is_investisseur' => 1,
        'prenom' => 'Yassine', 'nom' => 'Oufkir', 'societe_nom' => null,
        'tel' => '+212 6 61 33 44 04', 'email' => 'yassine.oufkir@example.com',
        'vertical' => 'vente',
        'data' => [
          'profil_type' => 'Particulier', 'projet' => 'Investisseur',
          'nationalite' => 'MA', 'pays_residence' => 'FR',
          'budget_max' => 2000000,
          'bien' => [ 'pays' => 'MA', 'types' => ['Riad','Maison'], 'type' => 'Riad',
            'ville' => 'Essaouira', 'quartier' => 'Centre',
            'chambres' => 3 ],
          'notes' => '[DEMO-SEED-V1] Investisseur Essaouira centre — paire matchante 3 (~50%).' ]
      ],
      [ 'seed_id' => 'seed-2026-04-28-pair3-vendeur-riad-medina',
        'projet' => 'Vendeur', 'is_investisseur' => 0,
        'prenom' => null, 'nom' => null, 'societe_nom' => 'Médina Heritage SAS',
        'tel' => '+212 5 24 47 12 34', 'email' => 'contact@medina-heritage.example.com',
        'vertical' => 'vente',
        'data' => [
          'profil_type' => 'Société', 'projet' => 'Vendeur',
          'nationalite' => 'MA', 'pays_residence' => 'MA',
          'prix_affiche' => 2400000,
          'bien' => [ 'pays' => 'MA', 'types' => ['Riad'], 'type' => 'Riad',
            'ville' => 'Essaouira', 'quartier' => 'Médina',
            'chambres' => 4, 'surface' => 220 ],
          'notes' => '[DEMO-SEED-V1] Société vendeur riad Médina Essaouira — paire matchante 3 (~50%).' ]
      ],
      [ 'seed_id' => 'seed-2026-04-28-libre-acheteur-gueliz',
        'projet' => 'Acheteur', 'is_investisseur' => 0,
        'prenom' => 'Rachida', 'nom' => 'Bennani', 'societe_nom' => null,
        'tel' => '+212 6 61 44 55 05', 'email' => 'rachida.bennani@example.com',
        'vertical' => 'vente',
        'data' => [
          'profil_type' => 'Particulier', 'projet' => 'Acheteur',
          'nationalite' => 'MA', 'pays_residence' => 'MA',
          'budget_max' => 1800000,
          'bien' => [ 'pays' => 'MA', 'types' => ['Appartement'], 'type' => 'Appartement',
            'ville' => 'Marrakech', 'quartier' => 'Guéliz',
            'chambres' => 2, 'surface' => 90 ],
          'notes' => '[DEMO-SEED-V1] Acheteur appt Guéliz 2ch — dossier libre.' ]
      ],
      [ 'seed_id' => 'seed-2026-04-28-libre-locataire-maarif',
        'projet' => 'Locataire', 'is_investisseur' => 0,
        'prenom' => 'Sofia', 'nom' => 'Chraibi', 'societe_nom' => null,
        'tel' => '+212 6 61 55 66 06', 'email' => 'sofia.chraibi@example.com',
        'vertical' => 'location_longue',
        'data' => [
          'profil_type' => 'Particulier', 'projet' => 'Locataire',
          'nationalite' => 'MA', 'pays_residence' => 'MA',
          'loyer_max' => 8000,
          'bien' => [ 'pays' => 'MA', 'types' => ['Appartement'], 'type' => 'Appartement',
            'ville' => 'Casablanca', 'quartier' => 'Maarif',
            'chambres' => 1, 'surface' => 60 ],
          'notes' => '[DEMO-SEED-V1] Locataire studio Maarif — dossier libre.' ]
      ],
      [ 'seed_id' => 'seed-2026-04-28-libre-bailleur-hivernage',
        'projet' => 'Bailleur', 'is_investisseur' => 0,
        'prenom' => 'Nabil', 'nom' => 'Idrissi', 'societe_nom' => null,
        'tel' => '+212 6 61 66 77 07', 'email' => 'nabil.idrissi@example.com',
        'vertical' => 'location_longue',
        'data' => [
          'profil_type' => 'Particulier', 'projet' => 'Bailleur',
          'nationalite' => 'MA', 'pays_residence' => 'MA',
          'loyer_demande' => 12000,
          'bien' => [ 'pays' => 'MA', 'types' => ['Appartement'], 'type' => 'Appartement',
            'ville' => 'Marrakech', 'quartier' => 'Hivernage',
            'chambres' => 2, 'surface' => 110 ],
          'notes' => '[DEMO-SEED-V1] Bailleur appt Hivernage 2ch — dossier libre.' ]
      ],
      [ 'seed_id' => 'seed-2026-04-28-libre-curieux-medina',
        'projet' => 'Curieux', 'is_investisseur' => 0,
        'prenom' => null, 'nom' => null, 'societe_nom' => 'Atlas Études Patrimoine SARL',
        'tel' => '+212 5 24 38 90 12', 'email' => 'etudes@atlas-patrimoine.example.com',
        'vertical' => null,
        'data' => [
          'profil_type' => 'Société', 'projet' => 'Curieux',
          'nationalite' => 'MA', 'pays_residence' => 'MA',
          'bien' => [ 'pays' => 'MA', 'ville' => 'Marrakech', 'quartier' => 'Médina' ],
          'notes' => '[DEMO-SEED-V1] Société curieuse étude marché Médina — dossier libre.' ]
      ],
    ];
}

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
        $stmt = pdo_meta()->prepare(
            "INSERT IGNORE INTO seed_clients_v1
                (seed_id, projet, is_investisseur, prenom, nom, societe_nom, tel, email, vertical, data)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
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
