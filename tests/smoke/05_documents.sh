#!/bin/bash
source "$(dirname "$0")/helpers.sh"

# documents.list nécessite dossier_id ; sans param on attend une erreur structurée (400 ou 403)
code=$(api GET '/api/documents.php?action=list')
case "$code" in 200|400|403) actual="ok" ;; *) actual="bad ($code)" ;; esac
assert_eq "$actual" "ok" "documents.list endpoint responds (200/400/403 expected)"

print_summary "documents"
