#!/bin/bash
source "$(dirname "$0")/helpers.sh"

# CSV : Content-Type doit contenir text/csv
ct=$(curl -sk -o /dev/null -w "%{content_type}" -H "X-Session-Token: $ADMIN_TOKEN" "$TENANT_BASE/api/data_export.php?format=csv")
assert_contains "$ct" "text/csv" "data_export csv content-type"

# XLSX : Content-Type spreadsheet
ct=$(curl -sk -o /dev/null -w "%{content_type}" -H "X-Session-Token: $ADMIN_TOKEN" "$TENANT_BASE/api/data_export.php?format=xlsx")
assert_contains "$ct" "spreadsheet" "data_export xlsx content-type"

# Notifications endpoint
code=$(api GET '/api/notifications.php?action=list&limit=5')
assert_eq "$code" "200" "notifications.list returns 200"

# Calendar subscription URL
code=$(api GET '/api/calendar_subscription.php')
assert_eq "$code" "200" "calendar_subscription returns 200"

# Preferences
code=$(api GET '/api/preferences.php?action=get')
assert_eq "$code" "200" "preferences.get returns 200"

print_summary "export"
