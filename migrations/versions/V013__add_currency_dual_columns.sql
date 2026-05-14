-- M/2026/05/14/58 — Rattrapage colonnes monnaie dual sur clients pour wsp anciens.
-- Le code api/clients.php L126+710 attend ces colonnes (M/2026/05/13/8 DualCurrencyPair
-- Variant B). V001__base.sql actuel les contient deja MAIS les wsp existants crees avant
-- la mise a jour V001 n'ont pas la colonne (CREATE TABLE IF NOT EXISTS skip si table
-- existe). Cette migration applique les ALTER additifs idempotents.
-- Cible : table clients de chaque wsp ocre_wsp_*.
-- MariaDB 10.6+ supporte ALTER TABLE ADD COLUMN IF NOT EXISTS.

ALTER TABLE clients
  ADD COLUMN IF NOT EXISTS exchange_rate_snapshot DECIMAL(14,6) DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS exchange_rate_source VARCHAR(64) DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS exchange_rate_fetched_at DATETIME DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS custom_exchange_rate DECIMAL(14,6) DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS deleted_by INT DEFAULT NULL;
