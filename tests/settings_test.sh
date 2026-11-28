#!/bin/bash
# Test settings pages and other authenticated pages for DB errors

BASE_URL="http://localhost:1627"
CURL="curl -s -c /tmp/cookies.txt -b /tmp/cookies.txt"

echo "========================================"
echo "Settings & Authenticated Pages Test"
echo "========================================"
echo ""

# Login first
echo "Logging in..."
LOGIN=$($CURL -L "$BASE_URL/?page=auth" \
    -H "Content-Type: application/x-www-form-urlencoded" \
    --data-urlencode "action=login" \
    --data-urlencode "email=admin@example.com" \
    --data-urlencode "password=admin123" \
    -w "\nHTTP_CODE: %{http_code}")

if echo "$LOGIN" | grep -q "HTTP_CODE: 302"; then
    echo "✅ Login successful"
else
    echo "❌ Login failed"
    echo "$LOGIN" | tail -5
fi

echo ""

passed=0
failed=0

test_page() {
    local name="$1"
    local url="$2"
    
    response=$($CURL -L "$BASE_URL$url" 2>&1)
    
    has_sql=$(echo "$response" | grep -c "SQLSTATE" || true)
    has_fatal=$(echo "$response" | grep -c "Fatal error" || true)
    has_parse=$(echo "$response" | grep -c "Parse error" || true)
    has_notice=$(echo "$response" | grep -c "Undefined" || true)
    
    if [ "$has_sql" -gt 0 ]; then
        echo "❌ FAIL: $name - Database error"
        echo "$response" | grep "SQLSTATE" | head -1
        failed=$((failed + 1))
    elif [ "$has_fatal" -gt 0 ]; then
        echo "❌ FAIL: $name - Fatal error"
        echo "$response" | grep "Fatal error" | head -1
        failed=$((failed + 1))
    elif [ "$has_parse" -gt 0 ]; then
        echo "❌ FAIL: $name - Parse error"
        echo "$response" | grep "Parse error" | head -1
        failed=$((failed + 1))
    elif [ "$has_notice" -gt 0 ]; then
        echo "⚠️  WARN: $name - Notice/Undefined"
        echo "$response" | grep "Undefined" | head -1
        passed=$((passed + 1))
    else
        echo "✅ PASS: $name"
        passed=$((passed + 1))
    fi
}

echo "📋 Settings Pages"
echo "----------------------------------------"
test_page "Settings Main" "/?page=settings"
test_page "Settings Billing" "/?page=settings&tab=billing"
test_page "Settings System" "/?page=settings&tab=system"
test_page "Settings Taxes" "/?page=settings&tab=taxes"
test_page "Settings Documents" "/?page=settings&tab=documents"
test_page "Settings Links" "/?page=settings&tab=links"
test_page "Item Library" "/?page=settings/item-library"
test_page "Custom Fields" "/?page=settings/custom-fields"

echo ""
echo "📋 Document Pages"
echo "----------------------------------------"
test_page "Quote Create" "/?page=quote/quotes-create"
test_page "Contract Create" "/?page=contract/contracts-create"
test_page "Invoice Create" "/?page=invoice/invoices-create"

echo ""
echo "📋 Financial Pages"
echo "----------------------------------------"
test_page "Financial Dashboard" "/?page=financial-dashboard"
test_page "Reports" "/?page=financial/reports"

echo ""
echo "📋 Organization Pages"
echo "----------------------------------------"
test_page "Organizations List" "/?page=organization/organizations-list"

echo ""
echo "========================================"
echo "TEST RESULTS SUMMARY"
echo "========================================"
echo "✅ Passed: $passed"
echo "❌ Failed: $failed"
echo "----------------------------------------"
echo "Total: $((passed + failed)) tests run"

if [ "$failed" -gt 0 ]; then
    exit 1
else
    exit 0
fi
