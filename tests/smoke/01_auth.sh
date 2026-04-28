#!/bin/bash
source "$(dirname "$0")/helpers.sh"

# Auth checks via session token long-lived déjà émis.
code=$(api GET '/api/auth.php?action=me')
assert_eq "$code" "200" "auth.me with valid token"

# Sans token → 401
code=$(curl -sk -o /dev/null -w "%{http_code}" "$TENANT_BASE/api/clients.php?action=list")
assert_eq "$code" "401" "clients.list without token returns 401"

# Token invalide → 401
code=$(curl -sk -o /dev/null -w "%{http_code}" -H "X-Session-Token: invalid_xxx" "$TENANT_BASE/api/clients.php?action=list")
assert_eq "$code" "401" "clients.list with invalid token returns 401"

print_summary "auth"
