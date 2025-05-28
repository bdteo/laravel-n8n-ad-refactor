#!/bin/bash

# Test script for n8n webhook configuration
# This script tests the webhook endpoint with sample data

set -e

echo "🚀 Testing n8n Webhook Configuration"
echo "======================================"

# Configuration
N8N_URL="http://localhost:5678"
WEBHOOK_PATH="/webhook/ad-script-processing"
WEBHOOK_SECRET="your-webhook-secret-here"

# Colors for output
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
NC='\033[0m' # No Color

# Function to print colored output
print_status() {
    local status=$1
    local message=$2
    case $status in
        "success")
            echo -e "${GREEN}✅ $message${NC}"
            ;;
        "error")
            echo -e "${RED}❌ $message${NC}"
            ;;
        "warning")
            echo -e "${YELLOW}⚠️  $message${NC}"
            ;;
        "info")
            echo -e "ℹ️  $message"
            ;;
    esac
}

# Test 1: Check if n8n is running
echo ""
print_status "info" "Test 1: Checking if n8n is accessible..."
if curl -s -f "$N8N_URL" > /dev/null; then
    print_status "success" "n8n is accessible at $N8N_URL"
else
    print_status "error" "n8n is not accessible at $N8N_URL"
    print_status "warning" "Make sure Docker containers are running: docker-compose up -d"
    exit 1
fi

# Test 2: Test webhook endpoint without authentication
echo ""
print_status "info" "Test 2: Testing webhook endpoint without authentication (should fail)..."
response=$(curl -s -w "%{http_code}" -o /dev/null \
    -X POST \
    -H "Content-Type: application/json" \
    -d '{"test": "data"}' \
    "$N8N_URL$WEBHOOK_PATH" || echo "000")

if [ "$response" = "401" ] || [ "$response" = "403" ]; then
    print_status "success" "Webhook correctly rejects unauthenticated requests (HTTP $response)"
elif [ "$response" = "404" ]; then
    print_status "error" "Webhook endpoint not found (HTTP 404)"
    print_status "warning" "Make sure the workflow is imported and activated in n8n"
    exit 1
else
    print_status "warning" "Unexpected response: HTTP $response"
fi

# Test 3: Test webhook endpoint with authentication
echo ""
print_status "info" "Test 3: Testing webhook endpoint with authentication..."

# Sample payload matching the expected format
payload='{
    "task_id": "test-' $(date +%s) '",
    "reference_script": "function oldFunction() { var x = 1; return x; }",
    "outcome_description": "Modernize to use const/let and arrow functions"
}'

response=$(curl -s -w "%{http_code}" -o /tmp/webhook_response.json \
    -X POST \
    -H "Content-Type: application/json" \
    -H "X-Webhook-Secret: $WEBHOOK_SECRET" \
    -d "$payload" \
    "$N8N_URL$WEBHOOK_PATH" || echo "000")

if [ "$response" = "200" ] || [ "$response" = "201" ]; then
    print_status "success" "Webhook accepts authenticated requests (HTTP $response)"
    if [ -f "/tmp/webhook_response.json" ]; then
        echo "Response body:"
        cat /tmp/webhook_response.json | jq . 2>/dev/null || cat /tmp/webhook_response.json
        rm -f /tmp/webhook_response.json
    fi
elif [ "$response" = "401" ] || [ "$response" = "403" ]; then
    print_status "error" "Authentication failed (HTTP $response)"
    print_status "warning" "Check that the webhook secret matches in both .env and n8n credentials"
elif [ "$response" = "404" ]; then
    print_status "error" "Webhook endpoint not found (HTTP 404)"
    print_status "warning" "Make sure the workflow is imported and activated in n8n"
else
    print_status "error" "Unexpected response: HTTP $response"
    if [ -f "/tmp/webhook_response.json" ]; then
        echo "Response body:"
        cat /tmp/webhook_response.json
        rm -f /tmp/webhook_response.json
    fi
fi

# Test 4: Check Laravel connectivity from n8n perspective
echo ""
print_status "info" "Test 4: Testing Laravel connectivity (from host perspective)..."
LARAVEL_URL="http://localhost:8000"
if curl -s -f "$LARAVEL_URL" > /dev/null; then
    print_status "success" "Laravel is accessible at $LARAVEL_URL"
else
    print_status "warning" "Laravel not accessible at $LARAVEL_URL"
    print_status "info" "This is normal if Laravel containers are not running"
fi

# Summary
echo ""
echo "======================================"
print_status "info" "Test Summary Complete"
echo ""
print_status "info" "Next Steps:"
echo "  1. Ensure Docker containers are running: docker-compose up -d"
echo "  2. Import the workflow: n8n/workflows/ad-script-refactor-workflow.json"
echo "  3. Configure credentials in n8n interface"
echo "  4. Set proper webhook secrets in .env file"
echo "  5. Test the full integration with Laravel"
echo ""
print_status "info" "For detailed setup instructions, see: n8n/README.md" 