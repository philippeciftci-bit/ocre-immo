#!/usr/bin/env bash
# V18.41 — active la page maintenance en appendant une RewriteRule au .htaccess prod.
# Non destructif : vérifie absence avant append.
set -euo pipefail

FTP_HOST="ftp.cluster121.hosting.ovh.net"
FTP_USER="expergh"
FTP_PASS_FILE="/root/.secrets/ovh_ftp_expergh"
TMP_HT=$(mktemp)
RULE_MARKER="# MAINTENANCE_ON_V18_41"
RULE_BLOCK="$RULE_MARKER
RewriteCond %{REQUEST_URI} !^/maintenance\.html$
RewriteCond %{REQUEST_URI} !^/icons/
RewriteCond %{REQUEST_URI} !^/favicon\.
RewriteRule ^(.*)$ /maintenance.html [R=503,L]
# /MAINTENANCE_ON_V18_41"

lftp -u "$FTP_USER,$(cat "$FTP_PASS_FILE")" "$FTP_HOST" <<LFTP
set ftp:ssl-allow no
cd /ocre/app/
get .htaccess -o $TMP_HT
bye
LFTP

if grep -q "$RULE_MARKER" "$TMP_HT"; then
    echo "⚠ maintenance déjà active (marker trouvé dans .htaccess)"
    rm -f "$TMP_HT"
    exit 0
fi

printf '\n%s\n' "$RULE_BLOCK" >> "$TMP_HT"

lftp -u "$FTP_USER,$(cat "$FTP_PASS_FILE")" "$FTP_HOST" <<LFTP
set ftp:ssl-allow no
cd /ocre/app/
put $TMP_HT -o .htaccess
bye
LFTP

rm -f "$TMP_HT"
echo "✓ maintenance activée — trafic → /maintenance.html (503)"
