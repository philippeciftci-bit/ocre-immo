#!/usr/bin/env bash
# V18.46 — backup quotidien DB OCRE immo via endpoint HTTP gzip-streamé.
# Storage local /var/backups/ocre-db/, rétention gérée par ocre-db-prune.sh.
# Notify Telegram priority=warning uniquement en cas d'échec (silent success).
set -euo pipefail

DUMP_URL="https://app.ocre.immo/api/db_dump.php"
TOKEN_FILE="/root/.secrets/ocre_dump_token"
BACKUP_DIR="/var/backups/ocre-db"
LOG_FILE="/var/log/ocre-backup.log"
MIN_SIZE=10240   # 10 Ko
DATE=$(date -u +%Y-%m-%d)
HOUR=$(date -u +%H%M)
OUT="$BACKUP_DIR/ocre-${DATE}.sql.gz"

mkdir -p "$BACKUP_DIR"
touch "$LOG_FILE"

log() { printf '[%s] %s\n' "$(date -u +%FT%TZ)" "$*" >> "$LOG_FILE"; }

fail() {
  local msg="$1"
  log "FAIL: $msg"
  /root/bin/notify --project ocre --priority warning \
    --title "⚠ DB backup OCRE échoué" \
    --body "$(date -u +%FT%TZ) — $msg. Log : $LOG_FILE. Check systemctl status ocre-db-backup." 2>&1 | tail -1 >> "$LOG_FILE" || true
  exit 1
}

log "Start backup → $OUT"
TOKEN=$(tr -d '\n' < "$TOKEN_FILE") || fail "token file illisible"

# Si fichier du jour existe déjà et valide, on écrase (re-run manuel OK).
TMP=$(mktemp --suffix=.sql.gz)
trap 'rm -f "$TMP"' EXIT

HTTP=$(curl -sS -4 -o "$TMP" -w '%{http_code}' --max-time 240 \
  -H "X-Dump-Token: $TOKEN" \
  "$DUMP_URL") || fail "curl error"

if [ "$HTTP" != "200" ]; then fail "HTTP $HTTP"; fi

SIZE=$(stat -c%s "$TMP")
if [ "$SIZE" -lt "$MIN_SIZE" ]; then fail "dump trop petit: $SIZE bytes (min $MIN_SIZE)"; fi

if ! gunzip -t "$TMP" 2>/dev/null; then fail "gzip invalide"; fi

if ! gunzip -c "$TMP" | grep -q 'CREATE TABLE `users`'; then fail "pas de CREATE TABLE users dans le dump"; fi

mv "$TMP" "$OUT"
trap - EXIT
chmod 600 "$OUT"

log "OK: $OUT ($(numfmt --to=iec --suffix=B "$SIZE"))"

# Prune après succès.
if [ -x /root/bin/ocre-db-prune.sh ]; then
  /root/bin/ocre-db-prune.sh >> "$LOG_FILE" 2>&1 || log "prune warning (non-fatal)"
fi

exit 0
