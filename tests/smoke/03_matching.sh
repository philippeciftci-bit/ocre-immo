#!/bin/bash
source "$(dirname "$0")/helpers.sh"

code=$(api GET '/api/matches.php?action=list&status=a_traiter&limit=5')
assert_eq "$code" "200" "matches.list returns 200"
body=$(api_body)
assert_contains "$body" '"ok":true' "matches.list ok"

code=$(api GET '/api/matches.php?action=count')
assert_eq "$code" "200" "matches.count returns 200"

code=$(api GET '/api/matching.php?action=stats')
assert_eq "$code" "200" "matching.stats returns 200"

print_summary "matching"
