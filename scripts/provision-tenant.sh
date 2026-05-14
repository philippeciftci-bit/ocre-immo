#!/bin/bash
# M/2026/04/28/17 + M/2026/05/06/83.3 — Provisioning tenant Ocre.
# Cree les 2 DBs ocre_wsp_<slug> + ocre_wsp_<slug>_test, applique le schema
# canonique versionne migrations/tenant-schema-v2.sql, GRANT ocre_app, INSERT
# user owner local. M83.3 : ajout INSERT ocre_meta.workspaces +
# workspace_members(role=owner) + flags --owner-user-id/--display-name/--type/
# --country. Backward-compat positionnel : <slug> [<owner_meta_uid>].
set -euo pipefail

OWNER_UID=""
DISPLAY_NAME=""
WS_TYPE="wsp"
COUNTRY_CODE="FR"
POSITIONALS=()

while [[ $# -gt 0 ]]; do
  case "$1" in
    --owner-user-id=*) OWNER_UID="${1#*=}"; shift ;;
    --owner-user-id)   OWNER_UID="$2"; shift 2 ;;
    --display-name=*)  DISPLAY_NAME="${1#*=}"; shift ;;
    --display-name)    DISPLAY_NAME="$2"; shift 2 ;;
    --type=*)          WS_TYPE="${1#*=}"; shift ;;
    --type)            WS_TYPE="$2"; shift 2 ;;
    --country=*)       COUNTRY_CODE="${1#*=}"; shift ;;
    --country)         COUNTRY_CODE="$2"; shift 2 ;;
    --help|-h)
      echo "usage: $0 <slug> [--owner-user-id=N] [--display-name=NAME] [--type=wsp|wsc] [--country=FR]"
      echo "       $0 <slug> [<owner_meta_uid>]   (legacy positional)"
      exit 0
      ;;
    --*) echo "Flag inconnu: $1" >&2; exit 1 ;;
    *) POSITIONALS+=("$1"); shift ;;
  esac
done

[[ ${#POSITIONALS[@]} -ge 1 ]] || { echo "usage: $0 <slug> [--owner-user-id=N] ..." >&2; exit 1; }
SLUG="${POSITIONALS[0]}"
[[ -z "$OWNER_UID" && ${#POSITIONALS[@]} -ge 2 ]] && OWNER_UID="${POSITIONALS[1]}"

SLUG=$(echo "$SLUG" | tr '[:upper:]' '[:lower:]' | sed 's/[^a-z0-9_-]//g')
[[ -z "$SLUG" ]] && { echo "Slug invalide" >&2; exit 1; }
[[ "$WS_TYPE" =~ ^(wsp|wsc)$ ]] || { echo "type invalide (wsp|wsc): $WS_TYPE" >&2; exit 1; }

# M/2026/05/14/2 — Reservation slug "staging-*" pour environnement factice E2E.
# Refus creation utilisateur reel (display_name + email) sur ce prefixe sauf
# si flag --allow-staging explicite. Le wsp staging-001 est gere par
# /root/bin/ocre-staging-reset.sh + /root/bin/ocre-staging-seed.sh.
if [[ "$SLUG" == staging-* && "${ALLOW_STAGING_PROVISION:-0}" != "1" ]]; then
  echo "ERROR: slug '$SLUG' reserve a l environnement staging factice." >&2
  echo "  Utiliser ALLOW_STAGING_PROVISION=1 $0 $SLUG [...] pour bypass," >&2
  echo "  ou /root/bin/ocre-staging-reset.sh pour reinitialiser." >&2
  exit 4
fi

ROOT_PWD=$(cat /root/.secrets/mysql-root.pwd)

if [[ -z "$OWNER_UID" ]]; then
  OWNER_UID=$(mysql -uroot -p"$ROOT_PWD" -BNe "
    SELECT m.user_id FROM ocre_meta.workspace_members m
    JOIN ocre_meta.workspaces w ON w.id = m.workspace_id
    WHERE w.slug = '${SLUG}' AND m.role = 'owner' AND m.left_at IS NULL LIMIT 1;
  " 2>/dev/null)
  [[ -z "$OWNER_UID" ]] && OWNER_UID=1
fi

DB_AGENT="ocre_wsp_${SLUG}"
APP_USER="ocre_app"
SCHEMA="$(cd "$(dirname "$0")/.." && pwd)/migrations/tenant-schema-v2.sql"

if [[ ! -f "$SCHEMA" ]]; then
  echo "Schema canonique introuvable : $SCHEMA" >&2
  exit 2
fi

# M84 : une seule DB par tenant (suppression DB _test).
mysql -uroot -p"$ROOT_PWD" -e "
CREATE DATABASE IF NOT EXISTS \`${DB_AGENT}\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
GRANT ALL PRIVILEGES ON \`${DB_AGENT}\`.* TO '${APP_USER}'@'localhost';
FLUSH PRIVILEGES;
"

mysql -uroot -p"$ROOT_PWD" --database="${DB_AGENT}" < "$SCHEMA"

# M/2026/05/14/1 + M/2026/05/14/61 — Pipeline migrations idempotent: applique versions/V*.sql
# manquantes apres le snapshot canonique, log _schema_migrations.
# M/14/61 fix Codex : vérif post-migrate avec SCHEMA_VERSION_REQUIRED dynamique (lu depuis
# api/config.php). Rollback DROP DATABASE si migrate échoue OU si version installée
# différente de la version attendue. Avant : WARN-but-continue laissait DB partielle
# en cas d'échec migrate -> SCHEMA_DRIFT au prochain hit api/health.php.
if [[ -x /root/bin/ocre-migrate.sh ]]; then
  EXPECTED=$(grep -E "define\('SCHEMA_VERSION_REQUIRED'," /opt/ocre-app/api/config.php 2>/dev/null \
    | sed -E "s/.*'SCHEMA_VERSION_REQUIRED', *'([^']+)'.*/\1/" | head -1)
  [[ -z "$EXPECTED" ]] && EXPECTED="V001"
  echo "Running migrations via ocre-migrate.sh (expected ${EXPECTED})"
  if ! OCRE_MIGRATE_SKIP_BACKUP=1 /root/bin/ocre-migrate.sh "${DB_AGENT}" >/tmp/ocre-provision.log 2>&1; then
    cat /tmp/ocre-provision.log >&2
    echo "ERROR: migrate failed on ${DB_AGENT}" >&2
    mysql -uroot -p"$ROOT_PWD" -e "DROP DATABASE IF EXISTS \`${DB_AGENT}\`"
    exit 5
  fi
  LATEST=$(mysql -uroot -p"$ROOT_PWD" -BNe "SELECT name FROM \`${DB_AGENT}\`._schema_migrations ORDER BY id DESC LIMIT 1;" 2>/dev/null)
  if [[ -z "$LATEST" || "${LATEST:0:${#EXPECTED}}" != "$EXPECTED" ]]; then
    echo "ERROR: schema ${DB_AGENT} latest='${LATEST:-NONE}' expected prefix '$EXPECTED'" >&2
    mysql -uroot -p"$ROOT_PWD" -e "DROP DATABASE IF EXISTS \`${DB_AGENT}\`"
    exit 6
  fi
  echo "Schema ${DB_AGENT} ready at ${LATEST}"
fi

mysql -uroot -p"$ROOT_PWD" --database="${DB_AGENT}" -e "
  INSERT IGNORE INTO users (id, email, active)
  VALUES (${OWNER_UID}, 'local@${SLUG}', 1);
"

DISPLAY_ESC="${DISPLAY_NAME//\'/\\\'}"
[[ -z "$DISPLAY_ESC" ]] && DISPLAY_ESC="$SLUG"

mysql -uroot -p"$ROOT_PWD" -e "
INSERT INTO ocre_meta.workspaces (slug, type, display_name, country_code)
VALUES ('${SLUG}', '${WS_TYPE}', '${DISPLAY_ESC}', '${COUNTRY_CODE}')
ON DUPLICATE KEY UPDATE display_name = VALUES(display_name);

INSERT IGNORE INTO ocre_meta.workspace_members (workspace_id, user_id, role)
SELECT id, ${OWNER_UID}, 'owner' FROM ocre_meta.workspaces WHERE slug = '${SLUG}';
"

echo "OK ${DB_AGENT} + meta workspaces+members (owner=${OWNER_UID})"
