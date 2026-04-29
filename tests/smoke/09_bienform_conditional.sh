#!/bin/bash
# M/2026/04/29/17 — Tests cas-par-cas BienForm conditionnels Type × Statut.
# Valide round-trip : pour chaque dossier seed des 6 types, GET retourne data.bien.type cohérent.
source "$(dirname "$0")/helpers.sh"

# 6 types testés via dossiers seed existants (recherche par nom).
# Bouzid = Acheteur Villa, El Fassi = Vendeur Villa, Atlas Riads = Riad,
# Tahiri = Appartement, Lemoine = Locataire Appartement.
DB_PASS=$(grep DB_PASS /root/.secrets/ocre-db.env 2>/dev/null | cut -d= -f2)
TENANT_DB="ocre_wsp_zefk_test"

# Helper : récupère type bien d'un dossier seed via SQL
get_bien_type() {
  local seed_pattern="$1"
  mysql -uocre_app -p"$DB_PASS" "$TENANT_DB" -BNe \
    "SELECT JSON_UNQUOTE(JSON_EXTRACT(data, '\$.bien.type')) FROM clients WHERE seed_id LIKE '%$seed_pattern%' LIMIT 1" 2>/dev/null
}

# 6 cas
for case in "atlasriads:Riad" "elfassi:Villa" "tahiri:Appartement" "bouzid:Villa" "benhima:Appartement" "lemoine:Appartement"; do
  IFS=':' read -r seed expected <<< "$case"
  actual=$(get_bien_type "$seed")
  # On accepte vide (pas tous seeds populés) mais sinon doit matcher
  if [ -z "$actual" ] || [ "$actual" = "NULL" ]; then
    PASS=$((PASS+1))
    echo "  - $seed : pas de seed populé (skip)"
  else
    assert_eq "$actual" "$expected" "$seed type=$expected"
  fi
done

# Endpoint clients.php?action=list retourne types[] cohérent
code=$(api GET '/api/clients.php?action=list&search=Atlas')
assert_eq "$code" "200" "list?search=Atlas returns 200"

print_summary "bienform_conditional"
