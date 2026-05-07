-- M/2026/05/07/5 — Rollback ALTER TABLE users (drop 3 colonnes ajoutees).
-- A executer uniquement si migration .sql doit etre annulee.
-- Restore alternative : `mysql -uocre_app -p ocre_meta < /var/backups/ocre-db/users-pre-agent-fields-AAAAMMJJ-HHMM.sql`.

ALTER TABLE users
  DROP COLUMN IF EXISTS cgu_accepted,
  DROP COLUMN IF EXISTS rgpd_accepted,
  DROP COLUMN IF EXISTS pays;
