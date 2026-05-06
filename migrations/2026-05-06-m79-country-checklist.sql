-- M/2026/05/06/79 — Section VII : checklist pieces/diagnostics + acquittement cadre legal pays-dependant.
-- Structure JSON : { "diagnostics": {"dpe": {"checked":true, "note":"..."}, ...},
--                    "pieces": {...}, "legal_ack": {"sru": true, ...} }
-- Idempotent.

ALTER TABLE clients
  ADD COLUMN IF NOT EXISTS country_checklist LONGTEXT NULL;
