#!/bin/bash
# M/2026/05/13/53 — Verrou 3 SSOT : scan index.html post-build pour detecter
# tout composant React currency/rate/price/amount/devise/taux non liste dans
# le registre. Bloque le smoke et le deploiement en cas de violation.
#
# Appel : depuis tests/run_all.sh + /root/bin/ocre-deploy.sh (avant rsync).
# Exit 0 = OK. Exit 1 = composant hors registre detecte.

set -uo pipefail

INDEX="${INDEX:-/root/workspace/ocre-immo/index.html}"
REGISTRY="${REGISTRY:-/root/workspace/ocre-immo/COMPONENT_REGISTRY.md}"

if [ ! -f "$INDEX" ]; then
  echo "check_ssot: index.html introuvable ($INDEX)"
  exit 2
fi

if [ ! -f "$REGISTRY" ]; then
  echo "check_ssot: COMPONENT_REGISTRY.md introuvable ($REGISTRY)"
  exit 2
fi

# Whitelist explicite : extraire les noms ✅ du registre.
# Format attendu : "- ✅ `NomComposant` ..." ou "- ✅ NomComposant ..."
ALLOWED=$(grep -E "^\s*[-*]\s*✅" "$REGISTRY" 2>/dev/null \
  | grep -oE "\`[A-Z][a-zA-Z0-9]*\`" \
  | tr -d '`' \
  | sort -u)

if [ -z "$ALLOWED" ]; then
  echo "check_ssot: aucun composant ✅ trouve dans registre (sanity check fail)"
  exit 2
fi

# Patterns mots-cles SSOT (currency-related).
KEYWORDS_RE="(Currency|Rate|Price|Amount|Devise|Taux)"

# Extraire les composants react declares dans index.html en 2 passes :
#   1. tous les composants (PascalCase)
#   2. filtrer ceux dont le NOM contient une keyword SSOT (insensitive)
# Approche en 2 etapes pour catcher les composants qui COMMENCENT par la keyword
# (ex: PriceField2Col, RateBlock, CurrencyInput) que le regex single-pass ratait.
ALL_DECLS=$(grep -oE "(const|function)\s+[A-Z][a-zA-Z0-9_]+" "$INDEX" 2>/dev/null \
  | sed -E 's/^(const|function)[[:space:]]+//' \
  | sort -u)
DECLARED=$(echo "$ALL_DECLS" | grep -E "$KEYWORDS_RE" || true)

if [ -z "$DECLARED" ]; then
  echo "check_ssot: 0 composant currency detecte dans index.html (anomalie ?)"
  # Pas un fail strict : si zero composant trouve, c'est suspicious mais pas blocker.
  exit 0
fi

UNAUTHORIZED=""
for COMP in $DECLARED; do
  if ! echo "$ALLOWED" | grep -qx "$COMP"; then
    UNAUTHORIZED="$UNAUTHORIZED $COMP"
  fi
done

if [ -n "$UNAUTHORIZED" ]; then
  echo "❌ FAIL check_ssot : composant(s) currency hors registre detecte(s) :$UNAUTHORIZED"
  echo "   Registre : $REGISTRY"
  echo "   Index    : $INDEX"
  echo "   Action   : retirer le composant non liste, OU l'ajouter au registre avec validation Philippe."
  exit 1
fi

echo "✓ check_ssot : composants currency OK ($(echo "$DECLARED" | tr '\n' ' ' | sed 's/[[:space:]]*$//') tous dans registre)"
exit 0
