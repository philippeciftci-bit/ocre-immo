#!/bin/bash
# M/2026/04/27/15 — drop tenant DBs (utilisé en cas de rollback register).
set -euo pipefail
SLUG="${1:?usage: $0 <slug>}"
SLUG=$(echo "$SLUG" | tr '[:upper:]' '[:lower:]' | sed 's/[^a-z0-9_-]//g')
[[ -z "$SLUG" ]] && exit 1
ROOT_PWD=$(cat /root/.secrets/mysql-root.pwd)
mysql -uroot -p"$ROOT_PWD" -e "DROP DATABASE IF EXISTS ocre_wsp_${SLUG}; DROP DATABASE IF EXISTS ocre_wsp_${SLUG}_test;" 2>&1 | grep -v Warning
echo "OK dropped ocre_wsp_${SLUG} + _test"
