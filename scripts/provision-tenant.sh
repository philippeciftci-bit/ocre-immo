#!/bin/bash
# M/2026/04/28/17 — Provisioning tenant Ocre. Crée les 2 DBs ocre_wsp_<slug>
# (mode agent) + ocre_wsp_<slug>_test (mode test) et applique le schéma
# canonique versionné migrations/tenant-schema-v1.sql.
#
# Remplacement franc de la version précédente qui clonait depuis
# ocre_wsp_ozkan_test (DB qui n'existe plus → DBs créées vides → bug
# diagnostiqué pour zefkicin@gmail.com).
set -euo pipefail

SLUG="${1:?usage: $0 <slug>}"
SLUG=$(echo "$SLUG" | tr '[:upper:]' '[:lower:]' | sed 's/[^a-z0-9_-]//g')
[[ -z "$SLUG" ]] && { echo "Slug invalide" >&2; exit 1; }

ROOT_PWD=$(cat /root/.secrets/mysql-root.pwd)
DB_AGENT="ocre_wsp_${SLUG}"
DB_TEST="ocre_wsp_${SLUG}_test"
APP_USER="ocre_app"
SCHEMA="$(cd "$(dirname "$0")/.." && pwd)/migrations/tenant-schema-v1.sql"

if [[ ! -f "$SCHEMA" ]]; then
  echo "Schéma canonique introuvable : $SCHEMA" >&2
  exit 2
fi

# 1. Créer les 2 DBs + droits applicatifs.
mysql -uroot -p"$ROOT_PWD" -e "
CREATE DATABASE IF NOT EXISTS ${DB_AGENT} CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE DATABASE IF NOT EXISTS ${DB_TEST} CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
GRANT ALL PRIVILEGES ON ${DB_AGENT}.* TO '${APP_USER}'@'localhost';
GRANT ALL PRIVILEGES ON ${DB_TEST}.* TO '${APP_USER}'@'localhost';
FLUSH PRIVILEGES;
"

# 2. Appliquer le schéma canonique idempotent aux 2 DBs.
mysql -uroot -p"$ROOT_PWD" "${DB_AGENT}" < "$SCHEMA"
mysql -uroot -p"$ROOT_PWD" "${DB_TEST}"  < "$SCHEMA"

# 3. Insérer le user local owner (id=1) dans les 2 DBs. Sert de cible FK pour
#    clients.user_id ; credentials et identité réelle vivent en ocre_meta.users.
for DB in "${DB_AGENT}" "${DB_TEST}"; do
  mysql -uroot -p"$ROOT_PWD" "${DB}" -e "
    INSERT IGNORE INTO users (id, email, active)
    VALUES (1, 'local@${SLUG}', 1);
  "
done

echo "OK ${DB_AGENT} + ${DB_TEST} (schéma + user owner local)"
