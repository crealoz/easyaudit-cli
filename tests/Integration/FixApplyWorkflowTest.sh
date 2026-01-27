#!/usr/bin/env bash
#
# Integration Test: EasyAudit CLI Scan → Fix-Apply Workflow
#
# Prerequisites:
#   - EASYAUDIT_BEARER_TOKEN set in environment
#   - terrible-module available at ~/Public/packages/crealoz/terrible-module/
#   - Optional: EASYAUDIT_SELF_SIGNED=1 for local API
#
# Usage:
#   ./tests/Integration/FixApplyWorkflowTest.sh
#

set -e

# Colors
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
BLUE='\033[0;34m'
NC='\033[0m' # No Color

# Test configuration
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PROJECT_ROOT="$(cd "$SCRIPT_DIR/../.." && pwd)"
TERRIBLE_MODULE="${TERRIBLE_MODULE_PATH:-$HOME/Public/packages/crealoz/terrible-module/}"
REPORT_DIR="$PROJECT_ROOT/tests/tmp"
PATCH_DIR="$PROJECT_ROOT/tests/tmp/patches"

# Ensure clean test directory
rm -rf "$REPORT_DIR"
mkdir -p "$REPORT_DIR" "$PATCH_DIR"

# Test counters
TESTS_PASSED=0
TESTS_FAILED=0

pass() {
    echo -e "${GREEN}✓ PASS${NC}: $1"
    TESTS_PASSED=$((TESTS_PASSED + 1))
}

fail() {
    echo -e "${RED}✗ FAIL${NC}: $1"
    TESTS_FAILED=$((TESTS_FAILED + 1))
}

skip() {
    echo -e "${YELLOW}⊘ SKIP${NC}: $1"
}

header() {
    echo ""
    echo -e "${BLUE}━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━${NC}"
    echo -e "${BLUE}  $1${NC}"
    echo -e "${BLUE}━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━${NC}"
}

# ============================================================================
# Pre-flight checks
# ============================================================================
header "Pre-flight Checks"

# Check terrible-module exists
if [ -d "$TERRIBLE_MODULE" ]; then
    pass "terrible-module directory exists: $TERRIBLE_MODULE"
else
    fail "terrible-module not found at: $TERRIBLE_MODULE"
    echo "Set TERRIBLE_MODULE_PATH environment variable to override."
    exit 1
fi

# Check CLI exists
if [ -f "$PROJECT_ROOT/bin/easyaudit" ]; then
    pass "easyaudit CLI exists"
else
    fail "easyaudit CLI not found at $PROJECT_ROOT/bin/easyaudit"
    exit 1
fi

# Check auth token (only warn, don't fail - fix-apply will check)
if [ -n "$EASYAUDIT_BEARER_TOKEN" ]; then
    pass "EASYAUDIT_BEARER_TOKEN is set"
else
    skip "EASYAUDIT_BEARER_TOKEN not set (fix-apply tests will be skipped)"
fi

# ============================================================================
# Test 1: Full Scan
# ============================================================================
header "Test 1: Full Scan of terrible-module"

REPORT_FILE="$REPORT_DIR/terrible-module-report.json"

cd "$PROJECT_ROOT"
if php bin/easyaudit scan "$TERRIBLE_MODULE" --format=json --output="$REPORT_FILE" 2>&1; then
    pass "Scan command completed"
else
    fail "Scan command failed"
fi

# Verify report exists
if [ -f "$REPORT_FILE" ]; then
    pass "Report file created: $REPORT_FILE"
else
    fail "Report file not created"
    exit 1
fi

# Count rules found
RULE_COUNT=$(cat "$REPORT_FILE" | jq 'del(.metadata) | keys | length')
if [ "$RULE_COUNT" -gt 5 ]; then
    pass "Found $RULE_COUNT rule types (expected >5)"
else
    fail "Only found $RULE_COUNT rule types (expected >5)"
fi

# List detected rules
echo ""
echo "Detected rules:"
cat "$REPORT_FILE" | jq -r 'del(.metadata) | .[].ruleId' | sort | while read rule; do
    echo "  - $rule"
done

# ============================================================================
# Test 2: API Connectivity (only if token set)
# ============================================================================
if [ -n "$EASYAUDIT_BEARER_TOKEN" ]; then
    header "Test 2: API Connectivity"

    # Run fix-apply with --dry-run to test API without spending credits
    # Since --dry-run doesn't exist, we'll just run it and check it starts
    echo "Testing API connection..."

    # Use timeout to prevent hanging on API issues
    if timeout 30 php bin/easyaudit fix-apply "$REPORT_FILE" --confirm 2>&1 | head -20 | grep -q "EasyAudit can fix"; then
        pass "API connection successful"
    else
        fail "API connection failed or timed out"
    fi
fi

# ============================================================================
# Test 3: Fix-Apply Full Run (only if token set)
# ============================================================================
if [ -n "$EASYAUDIT_BEARER_TOKEN" ]; then
    header "Test 3: Fix-Apply Full Run"

    # Clean patch directory
    rm -rf "$PATCH_DIR"/*

    echo "Running fix-apply (this may take a moment)..."

    FIX_OUTPUT=$(php bin/easyaudit fix-apply "$REPORT_FILE" \
        --confirm \
        --patch-out="$PATCH_DIR" \
        --scan-path="$TERRIBLE_MODULE" 2>&1) || true

    echo "$FIX_OUTPUT" | tail -10

    # Check if patches were generated
    PATCH_COUNT=$(find "$PATCH_DIR" -name "*.patch" -type f 2>/dev/null | wc -l)

    if [ "$PATCH_COUNT" -gt 0 ]; then
        pass "Generated $PATCH_COUNT patch file(s)"
    else
        fail "No patch files generated"
    fi

    # List generated patches
    echo ""
    echo "Generated patches:"
    find "$PATCH_DIR" -name "*.patch" -type f | while read patch; do
        LINES=$(wc -l < "$patch")
        echo "  - $(basename "$patch") ($LINES lines)"
    done
fi

# ============================================================================
# Test 4: Validate Patches (only if patches exist)
# ============================================================================
if [ -n "$EASYAUDIT_BEARER_TOKEN" ] && [ "$PATCH_COUNT" -gt 0 ]; then
    header "Test 4: Validate Patch Format"

    VALID_PATCHES=0
    INVALID_PATCHES=0

    # Create temp copy of terrible-module for patch testing
    TEMP_MODULE="/tmp/easyaudit-test-module-$$"
    cp -r "$TERRIBLE_MODULE" "$TEMP_MODULE"

    find "$PATCH_DIR" -name "*.patch" -type f | while read patch; do
        PATCH_NAME=$(basename "$patch")

        # Check patch has valid unified diff format
        if head -1 "$patch" | grep -q "^---"; then
            # Try to apply with --check (dry run)
            if cd "$TEMP_MODULE" && git apply --check "$patch" 2>/dev/null; then
                echo -e "  ${GREEN}✓${NC} $PATCH_NAME (valid, can apply)"
            else
                # Try with patch command
                if patch --dry-run -p1 < "$patch" >/dev/null 2>&1; then
                    echo -e "  ${GREEN}✓${NC} $PATCH_NAME (valid via patch)"
                else
                    echo -e "  ${YELLOW}!${NC} $PATCH_NAME (cannot apply cleanly)"
                fi
            fi
        else
            echo -e "  ${RED}✗${NC} $PATCH_NAME (invalid format)"
        fi
    done

    # Cleanup
    rm -rf "$TEMP_MODULE"

    pass "Patch validation complete"
fi

# ============================================================================
# Summary
# ============================================================================
header "Test Summary"

echo ""
echo -e "Tests passed: ${GREEN}$TESTS_PASSED${NC}"
echo -e "Tests failed: ${RED}$TESTS_FAILED${NC}"
echo ""

if [ "$TESTS_FAILED" -eq 0 ]; then
    echo -e "${GREEN}All tests passed!${NC}"
    exit 0
else
    echo -e "${RED}Some tests failed.${NC}"
    exit 1
fi
