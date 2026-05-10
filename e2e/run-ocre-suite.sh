#!/usr/bin/env bash
# M_PLAYWRIGHT_OCRE_PARCOURS — runner suite tests E2E Ocre
# Usage : /opt/atelier-tools/e2e/run-ocre-suite.sh
set -euo pipefail

cd /opt/atelier-tools/e2e
NOW=$(date +%Y%m%d-%H%M%S)
REPORT_DIR="/opt/atelier-tools/e2e/reports/$NOW"
mkdir -p "$REPORT_DIR"

# Vérification prérequis
if ! [ -d node_modules/@playwright ]; then
    echo "⚠ Playwright pas installé. npm install + npx playwright install chromium requis."
    exit 1
fi

# Run tests Ocre
npx playwright test tests/ocre/ \
    --reporter=html,line \
    --output="$REPORT_DIR/test-results" \
    2>&1 | tee "$REPORT_DIR/run.log"

EXIT=${PIPESTATUS[0]}

# Copier rapport HTML vers maquettes pour accès URL
mkdir -p /opt/atelier-tools/maquettes/e2e-reports
cp -r reports/html "/opt/atelier-tools/maquettes/e2e-reports/$NOW" 2>/dev/null || true

REPORT_URL="https://46-225-215-148.sslip.io/maquettes/e2e-reports/$NOW/"

# Notif Telegram
if [ "$EXIT" -eq 0 ]; then
    /root/bin/notify --project ocre --priority info \
        --title "Tests Ocre OK" \
        --body "Suite complete passee. Rapport : $REPORT_URL"
else
    FAILED=$(grep -cE "✘|FAIL" "$REPORT_DIR/run.log" 2>/dev/null || echo "?")
    /root/bin/notify --project ocre --priority warning \
        --title "Tests Ocre echec" \
        --body "$FAILED tests en echec. Rapport : $REPORT_URL"
fi

exit "$EXIT"
