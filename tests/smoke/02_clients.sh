#!/bin/bash
source "$(dirname "$0")/helpers.sh"

code=$(api GET '/api/clients.php?action=list')
assert_eq "$code" "200" "clients.list returns 200"
body=$(api_body)
assert_contains "$body" '"ok":true' "clients.list ok=true"

# Detail d'un dossier seedé (Bouzid devrait exister)
code=$(api GET '/api/clients.php?action=list&search=Bouzid')
assert_eq "$code" "200" "clients.search Bouzid returns 200"

print_summary "clients"
