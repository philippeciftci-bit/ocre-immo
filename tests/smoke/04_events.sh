#!/bin/bash
source "$(dirname "$0")/helpers.sh"

code=$(api GET '/api/events.php?action=list_all')
assert_eq "$code" "200" "events.list_all returns 200"

code=$(api GET '/api/notifications.php?action=count_unread')
assert_eq "$code" "200" "notifications.count_unread returns 200"

print_summary "events"
