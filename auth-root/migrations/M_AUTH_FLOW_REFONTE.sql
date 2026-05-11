-- M/2026/05/11/37 — M_AUTH_FLOW_REFONTE : TTL magic link + idle timeout configurables par user
ALTER TABLE auth_users ADD COLUMN IF NOT EXISTS magic_link_ttl_hours INT NOT NULL DEFAULT 24;
ALTER TABLE auth_users ADD COLUMN IF NOT EXISTS session_idle_timeout_hours INT NOT NULL DEFAULT 24;
ALTER TABLE auth_sessions ADD COLUMN IF NOT EXISTS last_activity_at DATETIME NULL;
