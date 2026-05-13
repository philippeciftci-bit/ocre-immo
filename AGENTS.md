# AGENTS.md — Conventions Ocre Immo

> Lu nativement par Codex CLI à chaque session. Voir aussi `CLAUDE.md` (lu par Claude Code) qui pointe ici pour la partie commune.

## Vocabulaire

- **« tenant » est banni** dans tout le code, les commentaires, les commits, l'UI. Utiliser à la place :
  - **wsp** — l'espace de travail (database `ocre_wsp_<slug>`, sous-domaine `<slug>.ocre.immo`).
  - **espace de travail** — version longue pour la copy UI.
  - **agent immo** — l'utilisateur principal (rôle `agent` en DB).
  - **utilisateur** — terme générique pour les memberships.
  - **dossier** — une transaction immobilière liée à un client.
- Le header HTTP legacy `X-Tenant-Slug` reste en place pour compatibilité jusqu'à la Phase 2 lexique (voir audit M/2026/05/13/78).

## Logo & marque

- Logo strict : **OCRE** en majuscules ocre foncé + **immo** en minuscules (style entête).
- Variants interdits : `OCRE • IMMO`, `OCRE IMMO`, `Ocre Immo`, `OCRE immo`, `Ocre immo`.
- Domaine `ocre.immo` autorisé (référence technique).

## Conventions commit

- **Conventional Commits** : `<type>(<scope>) [M/AAAA/MM/JJ/N]: <sujet>`
- Types : `feat`, `fix`, `refactor`, `chore`, `docs`, `test`, `perf`.
- Mission ID obligatoire entre crochets : `[M/2026/05/14/8]`.
- Signature `Co-Authored-By: <Agent Name> <noreply@anthropic.com>` en bas pour Claude Code, ou `Co-Authored-By: Codex CLI <noreply@openai.com>` pour Codex (mode handoff write futur — M/83).

## Règles d'or

### Préservation absolue des dossiers

Avant toute migration / ALTER / DROP / UPDATE en masse : `mysqldump --single-transaction` du wsp dans `/var/backups/ocre/migrate-YYYYMMDD-HHMMSS/`. Vérifier `clients_count` pré/post identique. Toute perte de données = mission échouée + rollback obligatoire.

### Parité absolue entre wsp

Tout wsp doit fonctionner comme `exbattat-a312` (le wsp de référence). Le pipeline migrations (`/root/bin/ocre-migrate.sh`) applique les versions `V<NNN>__*.sql` séquentiellement avec tracking `_schema_migrations(name, applied_at, checksum)`. Un wsp en `schema_status != OK` doit être rattrapé sous 10 min par `ocre-schema-audit.timer`.

### Versioning entiers

- `v17 → v18 → v19...` pour le front (jamais `v18.2.3`).
- `V001 → V002 → V003...` pour les migrations DB (séquentiel strict, jamais skipper).
- `SW_VERSION` bumpé à chaque modif JS frontend (`ocre-sw-v<N>.0-<tag>`).

### Pattern expand-contract

Voir `/root/workspace/atelier-philippe/CONVENTIONS.md` section dédiée. Jamais d'`ALTER TABLE ADD COLUMN NOT NULL` direct. Toujours en 3 phases : Phase 1 expand (NULL), Phase 2 backfill (1000-batch idempotent), Phase 3 contract (NOT NULL).

### Périmètre strict

« Change X » = rien d'autre ne bouge. Pas de refactor opportuniste, pas de nettoyage incident. Un bug fix touche la ligne qui bugue.

### Zéro emoji décoratif UI

Les copy UI Philippe sont en texte plat. Emojis interdits dans l'UI, dans les commentaires de code prod, dans les commits. Exception : `⚠` pour alerte critique dans les notifs Telegram.

### Code mort = suppression franche

Pas de `// removed` ou `/* commented out */` ou variables `_unused`. L'historique git est la mémoire.

## Structure du projet

- `/opt/ocre-app/` — prod déployée (rsync depuis repo)
- `/root/workspace/ocre-immo/` — repo source (git)
- `/root/bin/` — scripts ops (`ocre-migrate.sh`, `ocre-uptime-check.sh`, `ocre-schema-audit.sh`, `ocre-errors-watch.sh`, `ocre-staging-reset.sh`, `ocre-trace.sh`, `ocre-deploy.sh`)

## Lecture obligatoire AVANT toute édition

- `COMPONENT_REGISTRY.md` à la racine si tu touches un composant currency/rate/price/amount/devise/taux/EUR/MAD. Pre-commit hook bloque si non listé.
- `CLAUDE.md` / ce fichier pour les conventions générales.
- `/root/workspace/atelier-philippe/CONVENTIONS.md` pour les patterns ops (staging, expand-contract, lint).
- L'audit architectural `/root/workspace/reports/2026-05-13-2150-audit-architectural-ocre.md` pour la dette systémique.

## Agents en présence

- **Claude Code** (`claude:0` tmux) : agent principal en lecture/écriture/commit. Mode normal.
- **Codex CLI** (`codex:0` tmux, M/2026/05/14/8) : consultant secondaire en read-only strict (sandbox `read-only`, approval `on-request`). Utilisé pour second avis, audit indépendant, pair-review. Ne commit jamais. Voir M/83 (différée) pour le handoff write avec ownership lock.

## Whitelist commandes auto-acceptées

- Claude Code : `/root/workspace/ocre/`, `/tmp/`, `/root/bin/`, `/var/lib/atelier/`, curl vers hosts whitelist.
- Codex : aucune écriture, donc whitelist read-only naturelle (sandbox enforce).
