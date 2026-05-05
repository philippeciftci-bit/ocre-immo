-- M/2026/05/05/58 — Refonte PDF étape 1 : champ destinataire personnalisé du PDF (optionnel par bien).
-- Multi-tenant : à appliquer sur chaque ocre_wsp_<slug> (déjà appliqué via ALTER pour 10 tenants existants
-- le 2026-05-05 ; ce fichier sert de référence + pour provision-tenant.sh futur).

ALTER TABLE clients
  ADD COLUMN IF NOT EXISTS destinataire_nom   VARCHAR(200) NULL AFTER public_contacts_count,
  ADD COLUMN IF NOT EXISTS destinataire_email VARCHAR(200) NULL AFTER destinataire_nom;
