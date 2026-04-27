#!/bin/bash
# V20 M/2026/04/27/4 — Provisioning tenant : crée DBs ocre_wsp_<slug> + _test, clone schema/seed depuis ocre_wsp_ozkan_test.
set -euo pipefail
SLUG="${1:?usage: $0 <slug>}"
SLUG=$(echo "$SLUG" | tr '[:upper:]' '[:lower:]' | sed 's/[^a-z0-9_-]//g')
[[ -z "$SLUG" ]] && { echo "Slug invalide" >&2; exit 1; }

ROOT_PWD=$(cat /root/.secrets/mysql-root.pwd)
DB_AGENT="ocre_wsp_${SLUG}"
DB_TEST="ocre_wsp_${SLUG}_test"
APP_USER="ocre_app"

# 1. Créer les 2 DBs
mysql -uroot -p"$ROOT_PWD" -e "
CREATE DATABASE IF NOT EXISTS ${DB_AGENT} CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE DATABASE IF NOT EXISTS ${DB_TEST} CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
GRANT ALL PRIVILEGES ON ${DB_AGENT}.* TO '${APP_USER}'@'localhost';
GRANT ALL PRIVILEGES ON ${DB_TEST}.* TO '${APP_USER}'@'localhost';
FLUSH PRIVILEGES;
"

# 2. Cloner le schema depuis ocre_wsp_ozkan_test (référence) vers les 2 DBs
mysqldump -uroot -p"$ROOT_PWD" --no-data ocre_wsp_ozkan_test 2>/dev/null | mysql -uroot -p"$ROOT_PWD" "${DB_AGENT}"
mysqldump -uroot -p"$ROOT_PWD" --no-data ocre_wsp_ozkan_test 2>/dev/null | mysql -uroot -p"$ROOT_PWD" "${DB_TEST}"

# 3. Mode TEST : copier les seed + users de référence
mysqldump -uroot -p"$ROOT_PWD" ocre_wsp_ozkan_test users clients 2>/dev/null \
  | grep -v "^/\*\|^--\|^$\|^DROP\|^CREATE TABLE" \
  | mysql -uroot -p"$ROOT_PWD" "${DB_TEST}" 2>&1 | grep -v "Warning" || true

# 4. Mode AGENT : juste les users (table users vide cliente, mais user owner doit exister pour FK)
mysqldump -uroot -p"$ROOT_PWD" --no-data ocre_wsp_ozkan_test users 2>/dev/null \
  | mysql -uroot -p"$ROOT_PWD" "${DB_AGENT}" 2>&1 | grep -v "Warning" || true

echo "OK ${DB_AGENT} + ${DB_TEST}"
