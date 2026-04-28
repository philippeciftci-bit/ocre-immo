-- M/2026/04/28/16 — Seeder dossiers démo mode test.
-- La table seed_clients_v1 vit dans ocre_meta (source canonique partagée).
-- Les inserts canoniques sont posés idempotemment par api/seed.php au premier
-- hit (function ensureSeedMetaSchema()). Ce fichier sert de trace migration.
-- À exécuter manuellement uniquement si le bootstrap PHP n'a jamais tourné.

-- USE ocre_meta;

CREATE TABLE IF NOT EXISTS seed_clients_v1 (
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
) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

-- Sur chaque base WSp _test (tenant), api/seed.php ajoute aussi :
-- ALTER TABLE clients ADD COLUMN seed_id VARCHAR(64) NULL;
-- ALTER TABLE clients ADD UNIQUE KEY uniq_user_seed (user_id, seed_id);
-- (idempotent, swallow errors).

INSERT IGNORE INTO _migrations (name) VALUES ('2026-04-28-seed-data');
