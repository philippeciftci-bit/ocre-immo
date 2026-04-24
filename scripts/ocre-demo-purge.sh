#!/usr/bin/env bash
# V20 — purge dossiers démo Philippe [DEMO-2026-04-24].
# Usage : ocre-demo-purge.sh [--yes]  (sans --yes = dry-run + prompt)
set -euo pipefail

ENDPOINT_BASE="https://app.ocre.immo/api/demo_purge_v20.php"
LOG="/var/log/ocre-demo-purge.log"
touch "$LOG"

log() { printf '[%s] %s\n' "$(date -u +%FT%TZ)" "$*" >> "$LOG"; echo "$*"; }

# Dry-run : liste ce qui serait supprimé.
RAW=$(curl -sS -4 --max-time 30 "${ENDPOINT_BASE}?dry=1")
COUNT=$(echo "$RAW" | jq -r '.matched_ids | length')
IDS=$(echo "$RAW" | jq -r '.matched_ids[]?' | tr '\n' ' ')

if [ "$COUNT" = "0" ]; then
  log "Aucun dossier démo à purger."
  exit 0
fi

log "Dossiers démo détectés ($COUNT) : $IDS"

if [ "${1:-}" != "--yes" ]; then
  read -r -p "Supprimer $COUNT dossiers démo ? [y/N] " ans
  case "$ans" in y|Y|yes) ;; *) echo "Annulé."; exit 0 ;; esac
fi

# Purge en DB.
RES=$(curl -sS -4 --max-time 30 "$ENDPOINT_BASE")
DELETED=$(echo "$RES" | jq -r '.deleted_count')
log "DB purge OK — $DELETED dossiers supprimés."

# Purge photos FTP via lftp (réutilise mêmes ids).
if [ -n "$IDS" ]; then
  {
    echo "set ftp:ssl-allow no"
    echo "cd /ocre/app/uploads/"
    # Dossiers démo v20 stockent leurs photos dans users/user_1/imports — partagé avec
    # les autres dossiers Philippe : on ne purge PAS ce répertoire, trop risqué.
    # Note : les photos démo restent sur disk mais leurs refs en DB sont perdues.
    echo "bye"
  } | lftp -u "expergh,$(cat /root/.secrets/ovh_ftp_expergh)" ftp.cluster121.hosting.ovh.net >/dev/null 2>&1 || log "WARN lftp cleanup non-fatal"
fi

# Notif Telegram.
if [ -x /root/bin/notify ]; then
  /root/bin/notify --project ocre --priority success \
    --title "Demo purge OK" \
    --body "$DELETED dossiers démo [DEMO-2026-04-24] supprimés. Log : $LOG" 2>&1 | tail -1 >> "$LOG" || true
fi

log "Purge terminée."
