#!/bin/bash
source "$(dirname "$0")/helpers.sh"

# Super admin endpoints — Philippe uid=2 a role super_admin
code=$(api GET '/api/admin/overview.php')
assert_eq "$code" "200" "admin.overview with super_admin returns 200"

code=$(api GET '/api/admin/users.php?action=list')
assert_eq "$code" "200" "admin.users.list returns 200"

code=$(api GET '/api/admin/clients_xt.php?action=list&limit=5')
assert_eq "$code" "200" "admin.clients_xt.list returns 200"

code=$(api GET '/api/admin/audit_log.php?action=list&limit=5')
assert_eq "$code" "200" "admin.audit_log.list returns 200"

code=$(api GET '/api/admin/recycle_bin.php?action=list')
assert_eq "$code" "200" "admin.recycle_bin.list returns 200"

# Feature flags
code=$(api GET '/api/feature_flags.php?action=check_enabled&flag_key=scan_web_enabled')
assert_eq "$code" "200" "feature_flags.check_enabled returns 200"

# Sans token → 401
code=$(curl -sk -o /dev/null -w "%{http_code}" "$TENANT_BASE/api/admin/overview.php")
assert_eq "$code" "401" "admin.overview without token returns 401"

print_summary "admin"
