#!/usr/bin/env bash
# V18.41 — désactive la maintenance en retirant le bloc marqué du .htaccess prod.
set -euo pipefail

FTP_HOST="ftp.cluster121.hosting.ovh.net"
FTP_USER="expergh"
FTP_PASS_FILE="/root/.secrets/ovh_ftp_expergh"
TMP_HT=$(mktemp)
TMP_OUT=$(mktemp)
MARKER_START="# MAINTENANCE_ON_V18_41"
MARKER_END="# /MAINTENANCE_ON_V18_41"

lftp -u "$FTP_USER,$(cat "$FTP_PASS_FILE")" "$FTP_HOST" <<LFTP
set ftp:ssl-allow no
cd /ocre/app/
get .htaccess -o $TMP_HT
bye
LFTP

if ! grep -q "$MARKER_START" "$TMP_HT"; then
    echo "⚠ maintenance déjà désactivée (pas de marker)"
    rm -f "$TMP_HT" "$TMP_OUT"
    exit 0
fi

# Retire tout le bloc entre markers (inclusif).
awk -v start="$MARKER_START" -v end="$MARKER_END" '
    $0 ~ start { skip=1 }
    !skip { print }
    $0 ~ end { skip=0 }
' "$TMP_HT" > "$TMP_OUT"

# Trim trailing blank lines.
sed -i -e ':a; /^$/{$d;N;ba' -e '}' "$TMP_OUT" 2>/dev/null || true

lftp -u "$FTP_USER,$(cat "$FTP_PASS_FILE")" "$FTP_HOST" <<LFTP
set ftp:ssl-allow no
cd /ocre/app/
put $TMP_OUT -o .htaccess
bye
LFTP

rm -f "$TMP_HT" "$TMP_OUT"
echo "✓ maintenance désactivée — trafic rétabli"
