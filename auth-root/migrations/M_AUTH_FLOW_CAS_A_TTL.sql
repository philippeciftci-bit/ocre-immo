-- M/2026/05/11/43 — Critere cas A base sur TTL DB (PAS cookie navigateur, fix Safari iPad ITP)
ALTER TABLE auth_users ADD COLUMN IF NOT EXISTS last_magic_link_consumed_at DATETIME NULL DEFAULT NULL;
ALTER TABLE auth_users ADD INDEX IF NOT EXISTS idx_email_last_access (email, last_login_at, last_magic_link_consumed_at);
