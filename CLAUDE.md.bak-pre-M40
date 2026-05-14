# CLAUDE.md — Ocre Immo

> Référence locale du projet. Le global est dans `/root/.claude/CLAUDE.md`.

## Migrations DB

Toute migration impliquant un changement de schéma qui peut casser le code en cours doit suivre le pattern **expand-contract** documenté dans `/root/workspace/atelier-philippe/CONVENTIONS.md` section "Migrations DDL + DML lourdes — pattern expand-contract" (M/2026/05/14/7).

TL;DR :
1. **Phase 1 (expand)** : `ALTER ADD COLUMN NULL`, code accepte 2 versions.
2. **Phase 2 (backfill)** : script idempotent par lots de 1000 lignes, sur tous les wsp.
3. **Phase 3 (contract)** : `ALTER MODIFY NOT NULL` une fois 100% backfillé.

Versions intermédiaires : `V<NNN>__add_*_nullable.sql` puis `V<NNN+M>__*_notnull.sql`. Vérifier checksum SHA256 via `ocre-migrate.sh` (M/2026/05/14/7 V_A — drift fichier source = refus + notif `--priority high`).

## Pipeline migrations

- Fichiers : `/opt/ocre-app/migrations/versions/V<NNN>__*.sql` (idempotent, `CREATE TABLE IF NOT EXISTS` + `ADD COLUMN IF NOT EXISTS`).
- Application : `/root/bin/ocre-migrate.sh <slug>` ou `--all` (backup mysqldump auto, checksum SHA256 verifié).
- Tracking : table `_schema_migrations(id, name, applied_at, checksum)` dans chaque wsp.
- Cible : `SCHEMA_VERSION_REQUIRED` constante dans `/opt/ocre-app/api/config.php`. Audit auto via `ocre-schema-audit.timer` toutes les 10 min.

## Staging

`https://staging-001.ocre.immo/` — environnement factice obligatoire avant tout déploiement code touchant API / migrations / front.

- Reset : `/root/bin/ocre-staging-reset.sh`
- Seed : `/root/bin/ocre-staging-seed.sh` (3 agents fictifs + 5 dossiers)
- Slug `staging-*` refusé en provisioning utilisateur réel (sauf `ALLOW_STAGING_PROVISION=1`).

## SSOT composants

`/root/workspace/ocre-immo/COMPONENT_REGISTRY.md` — registre déclaratif. Pre-commit hook bloque toute violation Currency/Rate/Price/Amount/Devise/Taux/EUR/MAD hors registre.

## Vocabulaire

« tenant » est **banni**. Utiliser `wsp` / « espace de travail » / « agent immo » / « utilisateur » selon le contexte. Le header HTTP legacy `X-Tenant-Slug` reste en place tant que la Phase 2 lexique (rename programmé) n'a pas démarré.

## Correlation ID

Chaque requête HTTP est tracée via header `X-Request-Id` (généré côté front via `crypto.randomUUID()`, capturé serveur dans `/var/log/ocre/requests.log`). Toast 500 affiche les 8 premiers chars avec tap-to-copy. Debug : `/root/bin/ocre-trace.sh <request_id>`.

## Error handling front

- `window.apiCall(url, opts)` : wrapper haut niveau (retourne body JSON, throw sur erreur).
- Monkey-patch `window.fetch` transparent pour tous les call sites existants (2xx normal, 401/403 redirect login, 4xx toast warning, 5xx toast critical + log + throw).
- **Pas de retry sur 5xx** (M/2026/05/14/7 V_C) — uniquement sur erreurs réseau pures (1 retry, 500ms fixe).
- Endpoint logging : `/api/log_client_error.php` → `/var/log/ocre/client_errors.log`.
- Watcher : `ocre-errors-watch.service` (notif Telegram par erreur, dedup 60s).
