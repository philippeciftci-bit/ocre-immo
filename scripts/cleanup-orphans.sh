#!/bin/bash
# M/2026/05/14/8 — Audit + reparation anti-orphelins.
# Listes :
#   A. Users ocre_meta WHERE status='active' SANS DB tenant ocre_wsp_<slug> avec table clients
#   B. DBs ocre_wsp_* SANS user actif les pointant
# Mode dry-run par defaut : log + Telegram rapport, ne touche rien.
# Mode --fix : pour les users sans DB, relance provision-tenant.sh.
#              (Les DB orphelines sont JAMAIS dropees automatiquement, juste reportees.)

set -uo pipefail

MODE="dry-run"
[[ "${1:-}" == "--fix" ]] && MODE="fix"

ROOT_PWD=$(cat /root/.secrets/mysql-root.pwd)
LOG="/var/log/ocre/cleanup-orphans.log"
mkdir -p /var/log/ocre 2>/dev/null || true
TS="$(date -Iseconds)"
echo "[$TS] === cleanup-orphans MODE=$MODE ===" >> "$LOG"

ORPHAN_USERS=()
ORPHAN_DBS=()
FIXED=()

# Liste users actifs
mapfile -t ACTIVE_USERS < <(mysql -uroot -p"$ROOT_PWD" -BNe "SELECT id,slug FROM ocre_meta.users WHERE status='active' AND archived_at IS NULL" 2>/dev/null)

for ROW in "${ACTIVE_USERS[@]}"; do
  UID_VAL=$(echo "$ROW" | cut -f1)
  SLUG=$(echo "$ROW" | cut -f2)
  [[ -z "$SLUG" ]] && continue
  DB="ocre_wsp_${SLUG}"
  HAS_CLIENTS=$(mysql -uroot -p"$ROOT_PWD" -BNe "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema='${DB}' AND table_name='clients'" 2>/dev/null)
  if [[ "$HAS_CLIENTS" != "1" ]]; then
    ORPHAN_USERS+=("uid=$UID_VAL slug=$SLUG db=$DB")
    echo "[$TS] ORPHAN-USER uid=$UID_VAL slug=$SLUG db=$DB missing-or-incomplete" >> "$LOG"
    if [[ "$MODE" == "fix" ]]; then
      OUT=$(sudo /opt/ocre-app/scripts/provision-tenant.sh "$SLUG" "$UID_VAL" 2>&1)
      RC=$?
      if [[ $RC -eq 0 ]]; then
        FIXED+=("uid=$UID_VAL slug=$SLUG")
        echo "[$TS] FIXED uid=$UID_VAL slug=$SLUG" >> "$LOG"
      else
        echo "[$TS] FIX-FAILED uid=$UID_VAL slug=$SLUG rc=$RC" >> "$LOG"
        echo "$OUT" | tail -10 >> "$LOG"
      fi
    fi
  fi
done

# Liste DB orphelines (ocre_wsp_* sans user actif)
mapfile -t ALL_DBS < <(mysql -uroot -p"$ROOT_PWD" -BNe "SHOW DATABASES LIKE 'ocre_wsp_%'" 2>/dev/null)
for DB in "${ALL_DBS[@]}"; do
  SLUG="${DB#ocre_wsp_}"
  HAS_USER=$(mysql -uroot -p"$ROOT_PWD" -BNe "SELECT COUNT(*) FROM ocre_meta.users WHERE slug='${SLUG}' AND status='active' AND archived_at IS NULL" 2>/dev/null)
  if [[ "$HAS_USER" == "0" ]]; then
    ORPHAN_DBS+=("$DB")
    echo "[$TS] ORPHAN-DB $DB (no active user)" >> "$LOG"
  fi
done

# Rapport stdout (utilise par notif Telegram externe)
echo "=== Cleanup orphans report ($MODE) ==="
echo "Users actifs scannés : ${#ACTIVE_USERS[@]}"
echo "Orphan users (active sans DB) : ${#ORPHAN_USERS[@]}"
for x in "${ORPHAN_USERS[@]}"; do echo "  - $x"; done
echo "Orphan DBs (DB sans user actif) : ${#ORPHAN_DBS[@]}"
for x in "${ORPHAN_DBS[@]}"; do echo "  - $x"; done
if [[ "$MODE" == "fix" ]]; then
  echo "Réparés : ${#FIXED[@]}"
  for x in "${FIXED[@]}"; do echo "  ✓ $x"; done
fi
echo "Log : $LOG"
