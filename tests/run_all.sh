#!/bin/bash
# M/2026/04/29/4 — Smoke tests runner. Exécute tous tests/smoke/[0-9]*.sh.
# M/2026/05/08/36 — Tenant éphémère : setup user+workspace dédié au démarrage,
# teardown garanti via trap EXIT (même en cas d'erreur). Élimine la dépendance
# au tenant fixe `zefk` qui disparaît à chaque Reset TOTAL phase test.

cd "$(dirname "$0")"
LOG="/var/log/ocre-smoke-tests.log"
TOTAL_PASS=0
TOTAL_FAIL=0
START=$(date +%s)
SMOKE_TENANT_SLUG=""
SMOKE_USER_ID=""
SMOKE_ADMIN_TOKEN=""

teardown() {
    if [ -n "$SMOKE_TENANT_SLUG" ] && [ -n "$SMOKE_USER_ID" ]; then
        echo "" | tee -a "$LOG"
        echo "[teardown] tenant=$SMOKE_TENANT_SLUG user_id=$SMOKE_USER_ID" | tee -a "$LOG"
        php /root/workspace/ocre-immo/tests/smoke_tenant.php teardown "$SMOKE_TENANT_SLUG" "$SMOKE_USER_ID" 2>&1 | tee -a "$LOG" || true
    fi
}
trap teardown EXIT INT TERM

echo "=== Smoke tests Ocre Immo · $(date -Iseconds) ===" | tee -a "$LOG"

# 1. Cleanup orphelins (run précédent crashé sans teardown).
echo "[cleanup_orphans]" | tee -a "$LOG"
php /root/workspace/ocre-immo/tests/smoke_tenant.php cleanup_orphans 2>&1 | tee -a "$LOG" || true

# 2. Setup tenant éphémère.
echo "" | tee -a "$LOG"
echo "[setup tenant éphémère]" | tee -a "$LOG"
SETUP_OUT=$(php /root/workspace/ocre-immo/tests/smoke_tenant.php setup 2>&1)
SETUP_RC=$?
echo "$SETUP_OUT" | tee -a "$LOG"
if [ "$SETUP_RC" -ne 0 ]; then
    echo "=== ABORT : setup tenant éphémère échoué (rc=$SETUP_RC) ===" | tee -a "$LOG"
    exit 1
fi
# Parse KEY=VALUE
while IFS='=' read -r k v; do
    case "$k" in
        SMOKE_TENANT_SLUG) SMOKE_TENANT_SLUG="$v" ;;
        SMOKE_ADMIN_TOKEN) SMOKE_ADMIN_TOKEN="$v" ;;
        SMOKE_USER_ID)     SMOKE_USER_ID="$v" ;;
    esac
done <<< "$SETUP_OUT"
export SMOKE_TENANT_SLUG SMOKE_ADMIN_TOKEN SMOKE_USER_ID
export ADMIN_TOKEN="$SMOKE_ADMIN_TOKEN"
# M/2026/05/08/38 — DNS wildcard *.ocre.immo en place. nginx vhost route via subdomain
# et OVERRIDE le header X-Tenant-Slug client. Solution : URL via subdomain dédié smoke-<ts>.
export TENANT_BASE="https://${SMOKE_TENANT_SLUG}.ocre.immo"

if [ -z "$SMOKE_TENANT_SLUG" ] || [ -z "$SMOKE_ADMIN_TOKEN" ]; then
    echo "=== ABORT : tenant slug ou token manquant après setup ===" | tee -a "$LOG"
    exit 1
fi
echo "[setup OK] slug=$SMOKE_TENANT_SLUG user_id=$SMOKE_USER_ID" | tee -a "$LOG"

# 3. Run smoke tests.
for f in smoke/[0-9]*.sh; do
    name=$(basename "$f" .sh)
    echo "" | tee -a "$LOG"
    echo "[$name]" | tee -a "$LOG"
    bash "$f" 2>&1 | tee -a "$LOG"
    if [ -f /tmp/smoke_result.txt ]; then
        read p f_count < /tmp/smoke_result.txt
        TOTAL_PASS=$((TOTAL_PASS + p))
        TOTAL_FAIL=$((TOTAL_FAIL + f_count))
        rm -f /tmp/smoke_result.txt
    fi
done

END=$(date +%s)
DURATION=$((END - START))

echo "" | tee -a "$LOG"
echo "=== RÉSULTAT : $TOTAL_PASS pass · $TOTAL_FAIL fail · ${DURATION}s ===" | tee -a "$LOG"

# Trap teardown s'occupe du cleanup automatiquement.

if [ "$TOTAL_FAIL" -gt 0 ] && [ -x /root/bin/notify ]; then
    # M/2026/05/13/50 — Baseline hash : ne notifier QUE si état changé vs dernier run.
    # Override 24h : si même état mais >24h sans notif, force ré-alerte (sanity check).
    SMOKE_BASELINE=/var/lib/atelier/smoke-baseline.txt
    mkdir -p "$(dirname "$SMOKE_BASELINE")"
    SMOKE_NOW=$(date +%s)
    # LIST_FAILED_TESTS = noms des smoke/*.sh ayant échoué (extrait du log courant).
    LIST_FAILED_TESTS=$(grep -E "^\[.*\]$" "$LOG" 2>/dev/null | head -50 | tr '\n' ',' | head -c 500)
    CURRENT_HASH=$(echo "${TOTAL_FAIL}:${LIST_FAILED_TESTS}" | md5sum | cut -d' ' -f1)
    LAST_HASH=$(cat "$SMOKE_BASELINE" 2>/dev/null || echo "")
    LAST_TIME=$(stat -c %Y "$SMOKE_BASELINE" 2>/dev/null || echo 0)
    AGE=$((SMOKE_NOW - LAST_TIME))
    if [ "$CURRENT_HASH" = "$LAST_HASH" ] && [ "$AGE" -lt 86400 ]; then
        echo "smoke-baseline: skip notif (état identique, age=${AGE}s < 24h)" | tee -a "$LOG"
    else
        echo "$CURRENT_HASH" > "$SMOKE_BASELINE"
        /root/bin/notify --project ocre --priority warning \
            --title "Smoke tests : $TOTAL_FAIL echecs" \
            --body "Tests automatises Ocre Immo : $TOTAL_PASS pass $TOTAL_FAIL fail. Voir /var/log/ocre-smoke-tests.log" \
            >/dev/null 2>&1 || true
    fi
    exit 1
fi

exit 0
