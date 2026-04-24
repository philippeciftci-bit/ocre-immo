#!/usr/bin/env bash
# V18.46 — rétention graduée /var/backups/ocre-db/.
# Règles :
#   1. Garde les 30 derniers backups quotidiens.
#   2. Garde 1 par mois (le plus récent) pour les 12 derniers mois.
#   3. Garde 1 par année (le plus récent) ad vitam.
# Le reste = supprimé.
set -euo pipefail

BACKUP_DIR="/var/backups/ocre-db"
cd "$BACKUP_DIR" 2>/dev/null || exit 0

TODAY_EPOCH=$(date -u -d "$(date -u +%Y-%m-%d)" +%s)
DAY=86400

declare -A KEEP=()
declare -A MONTHLY=()
declare -A YEARLY=()

# 1. 30 derniers quotidiens (tri alphanumérique = chronologique avec YYYY-MM-DD).
for f in $(ls -1 ocre-*.sql.gz 2>/dev/null | sort -r | head -30); do
  KEEP["$f"]=1
done

# 2 + 3. Itère tous fichiers, garde le plus récent de chaque mois (12 mois) et de chaque année.
for f in $(ls -1 ocre-*.sql.gz 2>/dev/null | sort); do
  DATE_PART=${f#ocre-}
  FDATE=${DATE_PART:0:10}
  FEPOCH=$(date -u -d "$FDATE" +%s 2>/dev/null) || continue
  AGE_DAYS=$(( (TODAY_EPOCH - FEPOCH) / DAY ))
  YEAR=${FDATE:0:4}
  MONTH=${FDATE:0:7}
  # Mensuel : 12 derniers mois max.
  if [ "$AGE_DAYS" -le 365 ]; then
    MONTHLY[$MONTH]="$f"
  fi
  # Annuel : toujours.
  YEARLY[$YEAR]="$f"
done

for m in "${!MONTHLY[@]}"; do KEEP["${MONTHLY[$m]}"]=1; done
for y in "${!YEARLY[@]}"; do KEEP["${YEARLY[$y]}"]=1; done

# Suppression.
DELETED=0
for f in $(ls -1 ocre-*.sql.gz 2>/dev/null); do
  if [ -z "${KEEP[$f]:-}" ]; then
    rm -f "$f"
    DELETED=$((DELETED + 1))
    echo "prune: rm $f"
  fi
done
echo "prune: kept ${#KEEP[@]}, deleted $DELETED"
