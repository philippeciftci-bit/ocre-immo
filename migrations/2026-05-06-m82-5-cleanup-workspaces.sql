-- M/2026/05/06/82.5 — Cleanup tables workspaces obsoletes (pivot M83 agents+pacts+matching).
-- DROP des tables creees en M82.1, plus utilisees apres l abandon du modele "partage de dossier".
-- Idempotent.

SET FOREIGN_KEY_CHECKS = 0;
DROP TABLE IF EXISTS client_workspaces;
DROP TABLE IF EXISTS workspaces;
SET FOREIGN_KEY_CHECKS = 1;
