#!/bin/bash
source "$(dirname "$0")/helpers.sh"

code=$(api GET '/api/edit_consent.php?action=list_pending')
assert_eq "$code" "200" "edit_consent.list_pending returns 200"

print_summary "edit_consent"
