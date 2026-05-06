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
DB_TEST="ocre_wsp_${SLUG}_test"
APP_USER="ocre_app"
SCHEMA="$(cd "$(dirname "$0")/.." && pwd)/migrations/tenant-schema-v2.sql"

if [[ ! -f "$SCHEMA" ]]; then
  echo "Schema canonique introuvable : $SCHEMA" >&2
  exit 2
fi

mysql -uroot -p"$ROOT_PWD" -e "
CREATE DATABASE IF NOT EXISTS \`${DB_AGENT}\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE DATABASE IF NOT EXISTS \`${DB_TEST}\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
GRANT ALL PRIVILEGES ON \`${DB_AGENT}\`.* TO '${APP_USER}'@'localhost';
GRANT ALL PRIVILEGES ON \`${DB_TEST}\`.* TO '${APP_USER}'@'localhost';
FLUSH PRIVILEGES;
"

mysql -uroot -p"$ROOT_PWD" --database="${DB_AGENT}" < "$SCHEMA"
mysql -uroot -p"$ROOT_PWD" --database="${DB_TEST}"  < "$SCHEMA"

for DB in "${DB_AGENT}" "${DB_TEST}"; do
  mysql -uroot -p"$ROOT_PWD" --database="${DB}" -e "
    INSERT IGNORE INTO users (id, email, active)
    VALUES (${OWNER_UID}, 'local@${SLUG}', 1);
  "
done

DISPLAY_ESC="${DISPLAY_NAME//\'/\\\'}"
[[ -z "$DISPLAY_ESC" ]] && DISPLAY_ESC="$SLUG"

mysql -uroot -p"$ROOT_PWD" -e "
INSERT INTO ocre_meta.workspaces (slug, type, display_name, country_code)
VALUES ('${SLUG}', '${WS_TYPE}', '${DISPLAY_ESC}', '${COUNTRY_CODE}')
ON DUPLICATE KEY UPDATE display_name = VALUES(display_name);

INSERT IGNORE INTO ocre_meta.workspace_members (workspace_id, user_id, role)
SELECT id, ${OWNER_UID}, 'owner' FROM ocre_meta.workspaces WHERE slug = '${SLUG}';
"

echo "OK ${DB_AGENT} + ${DB_TEST} + meta workspaces+members (owner=${OWNER_UID})"
