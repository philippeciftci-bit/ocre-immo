#!/bin/bash
# M/2026/04/29/12 — Monitoring uptime self-hosted Ocre Immo.
# Tourné toutes les 2 min via ocre-uptime.timer.
# Notif Telegram via /root/bin/notify priority high si DOWN, info si recovery.
# Dedup via state file (notif uniquement à transition OK→DOWN ou DOWN→OK).

ENDPOINTS=(
  "https://ocre.immo/|200|Vitrine"
  "https://zefk.ocre.immo/|200|Tenant zefk"
  "https://zefk.ocre.immo/api/health.php|200|API health"
)

LOG="/var/log/ocre-uptime.log"
STATE_DIR="/var/lib/ocre-uptime"
mkdir -p "$STATE_DIR"
touch "$LOG"

for ep in "${ENDPOINTS[@]}"; do
  IFS='|' read -r url expected_code label <<< "$ep"
  state_file="$STATE_DIR/$(echo "$label" | tr ' /' '__').state"
  prev_status=$(cat "$state_file" 2>/dev/null || echo "OK")

  start=$(date +%s%N)
  http_code=$(curl -sk -o /dev/null -w "%{http_code}" --max-time 10 "$url" 2>/dev/null)
  end=$(date +%s%N)
  duration_ms=$(( (end - start) / 1000000 ))

  if [ "$http_code" = "$expected_code" ]; then
    current_status="OK"
    if [ "$prev_status" = "DOWN" ]; then
      /root/bin/notify --project ocre --priority info \
        --title "Recovery $label" \
        --body "$url repond a nouveau ($http_code en ${duration_ms}ms)" >/dev/null 2>&1 || true
    fi
  else
    current_status="DOWN"
    if [ "$prev_status" = "OK" ]; then
      /root/bin/notify --project ocre --priority high \
        --title "ALERTE DOWNTIME $label" \
        --body "$url retourne $http_code (attendu $expected_code) en ${duration_ms}ms. Verifier nginx php-fpm mysql." >/dev/null 2>&1 || true
    fi
  fi

  echo "$current_status" > "$state_file"
  printf '[%s] %s %s %sms %s\n' "$(date -Iseconds)" "$label" "$http_code" "$duration_ms" "$current_status" >> "$LOG"
done

# Rotation log : keep last 5000 lines
tail -n 5000 "$LOG" > "$LOG.tmp" && mv "$LOG.tmp" "$LOG" 2>/dev/null || true
