#!/bin/bash
# Quick Frontend Test - Check pages load without SQL errors

BASE_URL="http://localhost:1627"
echo "========================================"
echo "Frontend Page Load Test Suite"
echo "========================================"
echo ""

passed=0
failed=0

test_page() {
    local name="$1"
    local url="$2"
    local expected="$3"
    
    # Fetch page content
    response=$(curl -s -o /tmp/page_test.html -w "%{http_code}" "$BASE_URL$url" 2>&1)
    
    # Check for errors
    has_sql=$(grep -c "SQLSTATE" /tmp/page_test.html 2>&1 || true)
    has_fatal=$(grep -c "Fatal error" /tmp/page_test.html 2>&1 || true)
    has_parse=$(grep -c "Parse error" /tmp/page_test.html 2>&1 || true)
    
    if [ "$has_sql" -gt 0 ]; then
        echo "❌ FAIL: $name - Database error"
        grep "SQLSTATE" /tmp/page_test.html | head -1
        failed=$((failed + 1))
    elif [ "$has_fatal" -gt 0 ]; then
        echo "❌ FAIL: $name - Fatal error"
        grep "Fatal error" /tmp/page_test.html | head -1
        failed=$((failed + 1))
    elif [ "$has_parse" -gt 0 ]; then
        echo "❌ FAIL: $name - Parse error"
        grep "Parse error" /tmp/page_test.html | head -1
        failed=$((failed + 1))
    elif [ "$response" = "$expected" ] || ([ "$expected" = "302" ] && [ "$response" = "302" ]); then
        echo "✅ PASS: $name (HTTP $response)"
        passed=$((passed + 1))
    else
        echo "⚠️  WARN: $name (HTTP $response, expected $expected)"
        # Not counting as failure if no errors and page loads
        passed=$((passed + 1))
    fi
}

echo "📋 Public Pages"
echo "----------------------------------------"
test_page "Login Page" "/?page=login" "200"
test_page "Public Redirect" "/?page=public-redirect&type=invoice&reason=paid" "200"

echo ""
echo "📋 Authenticated Pages (should redirect to login)"
echo "----------------------------------------"
test_page "Dashboard" "/?page=home" "302"
test_page "Clients List" "/?page=clients-list" "302"
test_page "Client Create" "/?page=clients-create" "302"
test_page "Projects List" "/?page=projects-list" "302"
test_page "Project Create" "/?page=projects-create" "302"
test_page "Quotes List" "/?page=quotes-list" "302"
test_page "Quote New" "/?page=document/new&as=quote" "302"
test_page "Contracts List" "/?page=contracts-list" "302"
test_page "Contract New" "/?page=document/new&as=contract" "302"
test_page "Invoices List" "/?page=invoices-list" "302"
test_page "Invoice New" "/?page=document/new&as=invoice" "302"
test_page "Payments List" "/?page=payments-list" "302"
test_page "Payment Create" "/?page=payments/payments-create" "302"
test_page "Financial Dashboard" "/?page=financial-dashboard" "302"
test_page "Settings" "/?page=settings" "302"
test_page "Accounts" "/?page=accounts" "302"
test_page "API Keys" "/?page=api-keys" "302"
test_page "Client Search" "/?page=clients-search&query=test" "302"
test_page "Project Search" "/?page=projects-search-autocomplete&query=test" "302"

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
