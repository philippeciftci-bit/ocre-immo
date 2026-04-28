#!/bin/bash
# M/2026/04/29/4 — Smoke tests runner. Exécute tous tests/smoke/[0-9]*.sh.

cd "$(dirname "$0")"
LOG="/var/log/ocre-smoke-tests.log"
TOTAL_PASS=0
TOTAL_FAIL=0
START=$(date +%s)

echo "=== Smoke tests Ocre Immo · $(date -Iseconds) ===" | tee -a "$LOG"

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

if [ "$TOTAL_FAIL" -gt 0 ] && [ -x /root/bin/notify ]; then
    /root/bin/notify --project ocre --priority warning \
        --title "Smoke tests : $TOTAL_FAIL echecs" \
        --body "Tests automatises Ocre Immo : $TOTAL_PASS pass $TOTAL_FAIL fail. Voir /var/log/ocre-smoke-tests.log" \
        >/dev/null 2>&1 || true
    exit 1
fi

exit 0
