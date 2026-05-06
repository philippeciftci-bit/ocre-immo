-- M/2026/05/06/71 — Statut dossier (brouillon/enregistre/archive). Migration multi-tenant.
-- Idempotent : ADD COLUMN IF NOT EXISTS + UPDATE conditional.
-- Initialisation : tout dossier existant prend statut deduit de archived/is_draft.

ALTER TABLE clients
  ADD COLUMN IF NOT EXISTS statut ENUM('brouillon','enregistre','archive') NOT NULL DEFAULT 'enregistre' AFTER archived;

UPDATE clients
  SET statut = CASE
    WHEN archived = 1 THEN 'archive'
    WHEN is_draft = 1 THEN 'brouillon'
    ELSE 'enregistre'
  END
  WHERE 1 = 1;
