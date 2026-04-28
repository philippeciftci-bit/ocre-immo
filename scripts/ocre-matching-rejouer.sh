#!/usr/bin/env bash
# M/2026/04/28/59 — Rejouer matching complet par tenant (cron 6h).
set -uo pipefail

LOG="/var/log/ocre-matching.log"
log() { printf '[%s] %s\n' "$(date -Iseconds)" "$*" >> "$LOG"; }

KEY_FILE="/root/.secrets/ocre_dev_key"
if [[ ! -r "$KEY_FILE" ]]; then
    log "FAIL no ocre_dev_key"
    exit 1
fi
KEY=$(cat "$KEY_FILE")

# Liste tenants depuis MySQL
ENV_FILE="/root/.secrets/ocre-db.env"
[[ -f "$ENV_FILE" ]] && set -a && source "$ENV_FILE" && set +a

DBS=$(mysql -uocre_app -p"$DB_PASS" -BNe "SHOW DATABASES LIKE 'ocre_wsp_%';" 2>/dev/null)

total=0
for db in $DBS; do
    # Slug = strip 'ocre_wsp_' prefix + '_test' suffix
    slug=$(echo "$db" | sed -E 's/^ocre_wsp_//' | sed -E 's/_test$//')
    host="${slug}.ocre.immo"
    # Curl avec X-Tenant-Slug header pour cibler la bonne DB
    resp=$(curl -sk --max-time 60 \
        -H "X-Tenant-Slug: ${slug}_test" \
        -H "X-Internal-Token: ${KEY}" \
        "https://${host}/api/matching.php?action=rejouer_complet" 2>&1)
    log "tenant=${db} slug=${slug} resp=${resp:0:200}"
    total=$((total + 1))
done

log "rejouer_complet ok tenants=${total}"
