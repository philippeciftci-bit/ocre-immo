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

# Agent profile reset check.
PROFILE_RESET=$(echo "$RES" | jq -r '.agent_profile_reset // false')
PID=$(echo "$RES" | jq -r '.philippe_user_id')
if [ "$PROFILE_RESET" = "true" ]; then
  log "Profil agent Philippe (user_id=$PID) reset (bio démo détectée)."
  # rm avatars FTP
  lftp -u "expergh,$(cat /root/.secrets/ovh_ftp_expergh)" ftp.cluster121.hosting.ovh.net <<LFTP >/dev/null 2>&1 || log "WARN lftp avatar rm non-fatal"
set ftp:ssl-allow no
cd /ocre/app/uploads/agents/$PID
rm avatar-400.jpg
rm avatar-120.jpg
bye
LFTP
fi

# Note : photos des dossiers démo stockées /uploads/users/user_1/imports/ — partagées
# avec les imports légitimes de Philippe, on ne purge PAS ce répertoire (trop risqué).
# Les photos orphelines restent sur disk mais ne sont plus référencées en DB.

# Notif Telegram.
if [ -x /root/bin/notify ]; then
  /root/bin/notify --project ocre --priority success \
    --title "Demo purge OK" \
    --body "$DELETED dossiers démo [DEMO-2026-04-24] supprimés. Log : $LOG" 2>&1 | tail -1 >> "$LOG" || true
fi

log "Purge terminée."
