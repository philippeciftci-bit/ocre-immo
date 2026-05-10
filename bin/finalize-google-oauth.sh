#!/usr/bin/env bash
# M_OAUTH_GOOGLE_CREDENTIALS_AUTO — Finalize Google OAuth credentials
# Usage : finalize-google-oauth.sh <CLIENT_ID> <CLIENT_SECRET>
# Ecrit /root/.secrets/google-oauth.env mode 600 + test endpoint init.php redirige vers accounts.google.com
set -euo pipefail

CLIENT_ID="${1:-}"
CLIENT_SECRET="${2:-}"
ENV_FILE="/root/.secrets/google-oauth.env"

if [ -z "$CLIENT_ID" ] || [ -z "$CLIENT_SECRET" ]; then
    cat <<EOF
Usage : $0 <GOOGLE_CLIENT_ID> <GOOGLE_CLIENT_SECRET>

Etapes prealables Philippe pour obtenir Client ID + Client Secret :

1. Aller sur https://console.cloud.google.com/projectcreate
   Nom : "Ocre Immo Auth"
   ID : ocre-immo-auth (ou auto)
   Cliquer "Créer"

2. Aller sur https://console.cloud.google.com/apis/credentials/consent
   User Type : External
   App name : Ocre Immo
   Support email : philippe.ciftci@gmail.com
   Developer contact : philippe.ciftci@gmail.com
   Cliquer "Save and Continue" 3 fois (Scopes: skip / Test users: skip / Summary: back to dashboard)

3. Aller sur https://console.cloud.google.com/apis/credentials
   Cliquer "+ CREATE CREDENTIALS" → "OAuth client ID"
   Type : Web application
   Name : Ocre Web
   Authorized JavaScript origins :
     - https://ocre.immo
     - https://auth.ocre.immo
   Authorized redirect URIs :
     - https://auth.ocre.immo/api/oauth/google/callback.php
   Cliquer "Create"

4. Copier le Client ID + Client Secret affichés.

5. Sur le VPS : $0 <CLIENT_ID> <CLIENT_SECRET>

Le script desactive automatiquement le mode mock pour Google et active le vrai OAuth.
EOF
    exit 1
fi

mkdir -p "$(dirname "$ENV_FILE")"
cat > "$ENV_FILE" <<EOF
# /root/.secrets/google-oauth.env — credentials Google OAuth (Web application)
# Genere $(date -Iseconds) par finalize-google-oauth.sh
GOOGLE_CLIENT_ID=$CLIENT_ID
GOOGLE_CLIENT_SECRET=$CLIENT_SECRET
EOF
chmod 600 "$ENV_FILE"
chown root:root "$ENV_FILE"
echo "✓ Ecrit $ENV_FILE (mode 600)"

# Test : init.php doit rediriger vers accounts.google.com (mode prod activé)
echo
echo "Test endpoint init.php avec ces credentials..."
LOC=$(curl -sI "https://auth.ocre.immo/api/oauth/google/init.php?app=agent" -m 5 | grep -i "^location:" | head -1 | tr -d '\r')
if echo "$LOC" | grep -q "accounts.google.com"; then
    echo "✓ Mode PROD actif : init.php redirige vers $LOC"
    echo "✓ Google OAuth reel pret. Tester depuis ocre.immo popup."
else
    echo "⚠ init.php ne redirige pas vers accounts.google.com :"
    echo "   $LOC"
    echo "   Verifier que le fichier $ENV_FILE est lu par PHP-FPM (permission)."
fi
