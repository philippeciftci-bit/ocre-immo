-- M/2026/05/07/5 — ALTER TABLE users ajout 3 colonnes manquantes pour signup agent end-to-end.
-- Idempotent (IF NOT EXISTS supporte par MariaDB 10.5+ / MySQL 8).
-- Schema users contenait deja la majorite des colonnes RGPD (cgu_accepted_at/version/ip/user_agent + rgpd_*),
-- activation_token, slug, siret, telephone, country_code, status enum pending_activation, created_at.
-- 3 colonnes manquantes : flags TINYINT explicites cgu_accepted + rgpd_accepted (utiles pour
-- requetes WHERE cgu_accepted = 1 sans re-evaluer NULL/NOT NULL sur cgu_accepted_at) + pays varchar(2)
-- distinct de country_code (qui sert deja a autre chose : pays de souscription).

-- Backup pre-migration : /var/backups/ocre-db/users-pre-agent-fields-AAAAMMJJ-HHMM.sql
-- Cmd : mysqldump -uocre_app -p ocre_meta users > /var/backups/ocre-db/users-pre-agent-fields-$(date +%Y%m%d-%H%M).sql

ALTER TABLE users
  ADD COLUMN IF NOT EXISTS cgu_accepted TINYINT(1) NOT NULL DEFAULT 0 AFTER cgu_version,
  ADD COLUMN IF NOT EXISTS rgpd_accepted TINYINT(1) NOT NULL DEFAULT 0 AFTER rgpd_version,
  ADD COLUMN IF NOT EXISTS pays VARCHAR(2) DEFAULT NULL AFTER country_code;
