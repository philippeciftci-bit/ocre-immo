-- V20 phase 1 — seed users + workspaces + log migration.
-- Lance dans ocre_meta.

-- Ozkan (compte agent test pour Philippe)
INSERT INTO users (email, password_hash, display_name, role, country_code, must_change_password)
VALUES ('ozkan@ocre.immo', '$2y$10$placeholder.replace.bcrypt.hash.in.script.layer', 'Özkan', 'agent', 'MA', 1)
ON DUPLICATE KEY UPDATE display_name = VALUES(display_name);

-- Philippe super-admin
INSERT INTO users (email, password_hash, display_name, role, country_code, must_change_password)
VALUES ('philippe.ciftci@gmail.com', '$2y$10$placeholder.replace.bcrypt.hash.in.script.layer', 'Philippe Ciftci', 'super_admin', 'FR', 1)
ON DUPLICATE KEY UPDATE display_name = VALUES(display_name), role = 'super_admin';

-- Workspace ozkan WSp
INSERT INTO workspaces (slug, type, display_name, country_code)
VALUES ('ozkan', 'wsp', 'Özkan', 'MA')
ON DUPLICATE KEY UPDATE display_name = VALUES(display_name);

-- Membership owner ozkan
INSERT INTO workspace_members (workspace_id, user_id, role, joined_at)
SELECT w.id, u.id, 'owner', NOW()
FROM workspaces w JOIN users u ON u.email = 'ozkan@ocre.immo'
WHERE w.slug = 'ozkan'
ON DUPLICATE KEY UPDATE role = 'owner';

-- Log migration
INSERT INTO _migrations_log (script_name, summary)
VALUES ('v20_phase1_2026-04-26', 'ocre_meta + ocre_wsp_ozkan v20 schema + ozkan/philippe users + ozkan WSp');
