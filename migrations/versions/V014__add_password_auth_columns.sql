-- M/2026/05/14/71 — Phase B AUTH-PERENNE : cols rate-limit/lockout pour login mot de passe.
-- Cols password_hash + password_reset_token + password_reset_expires + must_change_password
-- existent deja dans ocre_meta.users. Migration cible UNIQUEMENT le rajout des cols manquantes.
-- Note : ces cols vivent dans la table users de ocre_meta (pas dans chaque ocre_wsp_*).
-- Idempotent via ADD COLUMN IF NOT EXISTS (MariaDB 10.6+).

-- Cette migration tourne sur chaque ocre_wsp_* mais effectue un no-op : la table users locale wsp
-- n'a pas ces cols (la "table users" lambda est centralisee dans ocre_meta).
-- On enregistre quand meme une row dans _schema_migrations pour SCHEMA_VERSION_REQUIRED check.

-- No-op pour wsp (table users est dans ocre_meta).
SELECT 'V014 no-op wsp side' AS message;
