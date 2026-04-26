#!/usr/bin/env bash
# V20 phase 2 — provision_wsp.sh : crée DB agent + DB test seedée + user + workspace + membership.
# Usage : provision_wsp.sh <slug> <display_name> <email> <country_code> [<password>]
set -euo pipefail

SLUG="${1:?slug requis}"
DISPLAY_NAME="${2:?display_name requis}"
EMAIL="${3:?email requis}"
COUNTRY="${4:?country_code requis}"
PWD_ARG="${5:-}"

# Validation slug : [a-z0-9-]
if ! [[ "$SLUG" =~ ^[a-z0-9][a-z0-9-]*$ ]]; then
    echo "ERR: slug invalide (lowercase + chiffres + tirets)" >&2; exit 2
fi
if ! [[ "$COUNTRY" =~ ^[A-Z]{2}$ ]]; then
    echo "ERR: country_code doit etre 2 lettres maj (FR/MA)" >&2; exit 2
fi

ROOT_PWD=$(cat /root/.secrets/mysql-root.pwd)
APP_PWD=$(grep DB_PASS /root/.secrets/ocre-db.env | cut -d= -f2)
SCHEMA_FILE="/opt/ocre-app/api/migrations/wsp_schema_v20.sql"

# Generate password if not provided
if [ -z "$PWD_ARG" ]; then
    PWD_ARG=$(openssl rand -base64 18 | tr -d '/+=' | head -c 16)
fi
PWD_HASH=$(php -r "echo password_hash('$PWD_ARG', PASSWORD_BCRYPT, ['cost'=>10]);")

DB_AGENT="ocre_wsp_${SLUG}"
DB_TEST="ocre_wsp_${SLUG}_test"

echo "=== provision_wsp $SLUG ($DISPLAY_NAME / $EMAIL / $COUNTRY) ==="

# 1. DBs
mysql -u ocre_app -p"$APP_PWD" -e "
CREATE DATABASE IF NOT EXISTS \`$DB_AGENT\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE DATABASE IF NOT EXISTS \`$DB_TEST\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
"

# 2. Schema commun (v20)
mysql -u ocre_app -p"$APP_PWD" "$DB_AGENT" < "$SCHEMA_FILE"
mysql -u ocre_app -p"$APP_PWD" "$DB_TEST" < "$SCHEMA_FILE"

# Schema heritage clients/biens : copie depuis ocre_wsp_ozkan (DDL only)
TMP_DDL=$(mktemp)
mysqldump -u ocre_app -p"$APP_PWD" --no-data --skip-add-drop-table --skip-add-locks --skip-comments \
  ocre_wsp_ozkan clients suivi_events suivi_todos suivi_journal countries_config 2>/dev/null > "$TMP_DDL" || true
if [ -s "$TMP_DDL" ]; then
    mysql -u ocre_app -p"$APP_PWD" "$DB_AGENT" < "$TMP_DDL" 2>/dev/null || true
    mysql -u ocre_app -p"$APP_PWD" "$DB_TEST" < "$TMP_DDL" 2>/dev/null || true
fi
rm -f "$TMP_DDL"

# 3. Seed mode test : 3 clients + 5 biens fictifs (cohérent country)
if [ "$COUNTRY" = "MA" ]; then
    SEED_VILLES=("Marrakech" "Casablanca" "Rabat")
    SEED_DEVISE="MAD"
else
    SEED_VILLES=("Nantes" "Paris" "Lyon")
    SEED_DEVISE="EUR"
fi

mysql -u ocre_app -p"$APP_PWD" "$DB_TEST" -e "
INSERT IGNORE INTO settings_branding (k, v) VALUES ('display_name', '$DISPLAY_NAME — TEST'), ('primary_color', '#8B5E3C');
" 2>/dev/null || true

# Insert seed clients (3) avec data JSON
for i in 1 2 3; do
    case $i in
        1) PRENOM="Marie"; NOM="Dupont"; PROJET="Acheteur"; TEL="+33612345601";;
        2) PRENOM="Jean"; NOM="Martin"; PROJET="Vendeur"; TEL="+33612345602";;
        3) PRENOM="Sophie"; NOM="Bernard"; PROJET="Investisseur"; TEL="+33612345603";;
    esac
    VILLE="${SEED_VILLES[$((i % 3))]}"
    BIEN_JSON="{\"type\":\"Appartement\",\"ville\":\"$VILLE\",\"surface\":80,\"chambres\":2,\"sdb\":1}"
    DATA="{\"prenom\":\"$PRENOM\",\"nom\":\"$NOM\",\"tel\":\"$TEL\",\"projet\":\"$PROJET\",\"bien\":$BIEN_JSON,\"is_demo\":true}"
    mysql -u ocre_app -p"$APP_PWD" "$DB_TEST" -e "
    INSERT INTO clients (user_id, prenom, nom, tel, email, projet, archived, is_draft, data, created_at, updated_at)
    VALUES (1, '$PRENOM', '$NOM', '$TEL', '${PRENOM,,}.${NOM,,}@example.com', '$PROJET', 0, 0, '$DATA', NOW(), NOW())
    " 2>/dev/null || echo "  seed client $i KO (probable schema non aligné, on continue)"
done

# 4. Insert user dans ocre_meta (idempotent)
mysql -u ocre_app -p"$APP_PWD" ocre_meta -e "
INSERT INTO users (email, password_hash, display_name, role, country_code, must_change_password)
VALUES ('$EMAIL', '$PWD_HASH', '$DISPLAY_NAME', 'agent', '$COUNTRY', 1)
ON DUPLICATE KEY UPDATE
  password_hash='$PWD_HASH', display_name=VALUES(display_name), country_code=VALUES(country_code);

INSERT INTO workspaces (slug, type, display_name, country_code)
VALUES ('$SLUG', 'wsp', '$DISPLAY_NAME', '$COUNTRY')
ON DUPLICATE KEY UPDATE display_name=VALUES(display_name);

INSERT INTO workspace_members (workspace_id, user_id, role, joined_at)
SELECT w.id, u.id, 'owner', NOW()
FROM workspaces w JOIN users u ON u.email='$EMAIL'
WHERE w.slug='$SLUG'
ON DUPLICATE KEY UPDATE role='owner', left_at=NULL;
"

# 5. Vérif DNS résolution
DNS_R=$(dig +short "${SLUG}.ocre.immo" @8.8.8.8 2>&1 | head -1)
echo "  DNS ${SLUG}.ocre.immo -> ${DNS_R:-(not propagated yet)}"

# 6. Affiche password si nouveau
echo ""
echo "WSP_PROVISIONED slug=$SLUG email=$EMAIL"
if [ -n "${5:-}" ]; then
    echo "INITIAL_PASSWORD=<provided>"
else
    echo "INITIAL_PASSWORD=$PWD_ARG"
fi
echo "DB_AGENT=$DB_AGENT DB_TEST=$DB_TEST"
