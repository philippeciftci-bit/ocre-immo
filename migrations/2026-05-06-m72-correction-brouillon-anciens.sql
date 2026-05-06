-- M/2026/05/06/72 — Correction fiches brouillon antérieures au deploy M71 (commit 602ba99 / 2026-05-06 08:40:22 UTC).
-- Idempotent. Effet : tout dossier brouillon ancien (anterieur a la mission M71) repasse en 'enregistre'
-- + is_draft=0 pour coherence. Les dossiers archives ne sont pas touchés.
-- Les dossiers brouillon créés/modifies APRES le deploy M71 restent en brouillon (Philippe les enregistrera lui-meme).

UPDATE clients
  SET statut = 'enregistre', is_draft = 0
  WHERE statut = 'brouillon'
    AND updated_at < '2026-05-06 08:40:22'
    AND deleted_at IS NULL;
