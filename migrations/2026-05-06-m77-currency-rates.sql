-- M/2026/05/06/77 — Refonte devises : currency_rates JSON par dossier (rates par paire bloc).
-- Structure : {"pair_id_1": {"rate": 10.84, "source": "live|manual", "currency_left": "EUR",
--                            "currency_right": "MAD", "updated_at": "..."}}
-- Idempotent.

ALTER TABLE clients
  ADD COLUMN IF NOT EXISTS currency_rates LONGTEXT NULL;
