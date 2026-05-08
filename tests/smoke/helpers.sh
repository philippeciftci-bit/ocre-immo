#!/bin/bash
# M/2026/04/29/4 — Helpers communs aux smoke tests.
# M/2026/05/08/36 — tenant éphémère : TENANT_BASE pointe app.ocre.immo + header X-Tenant-Slug.
#                   ADMIN_TOKEN injecté par run_all.sh après setup. Plus de zefk fixe.

TENANT_BASE="${TENANT_BASE:-https://app.ocre.immo}"
ADMIN_TOKEN="${ADMIN_TOKEN:-${SMOKE_ADMIN_TOKEN:-$(cat /root/.secrets/test_admin_token 2>/dev/null)}}"
SMOKE_TENANT_SLUG="${SMOKE_TENANT_SLUG:-}"
PASS=0
FAIL=0
FAILED_LIST=""

assert_eq() {
    local actual="$1"
    local expected="$2"
    local label="$3"
    if [ "$actual" = "$expected" ]; then
        PASS=$((PASS+1))
        echo "  ✓ $label"
    else
        FAIL=$((FAIL+1))
        FAILED_LIST="${FAILED_LIST}    $label (expected '$expected' got '$actual')\n"
        echo "  ✗ $label (expected '$expected' got '$actual')"
    fi
}

assert_contains() {
    local haystack="$1"
    local needle="$2"
    local label="$3"
    if echo "$haystack" | grep -q "$needle"; then
        PASS=$((PASS+1))
        echo "  ✓ $label"
    else
        FAIL=$((FAIL+1))
        FAILED_LIST="${FAILED_LIST}    $label (needle '$needle' not in haystack)\n"
        echo "  ✗ $label (needle '$needle' not found)"
    fi
}

api() {
    local method="$1" url="$2"
    shift 2
    local tenant_header=()
    if [ -n "$SMOKE_TENANT_SLUG" ]; then
        tenant_header=(-H "X-Tenant-Slug: $SMOKE_TENANT_SLUG")
    fi
    curl -sk -o /tmp/api_body.txt -w "%{http_code}" -X "$method" \
        -H "X-Session-Token: $ADMIN_TOKEN" \
        -H "Content-Type: application/json" \
        "${tenant_header[@]}" \
        "$TENANT_BASE$url" "$@"
}

api_body() { cat /tmp/api_body.txt 2>/dev/null; }

print_summary() {
    local domain="$1"
    echo ""
    echo "  → $domain : $PASS pass · $FAIL fail"
    # Export pour run_all.sh
    echo "$PASS $FAIL" > /tmp/smoke_result.txt
}
