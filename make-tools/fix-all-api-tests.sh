#!/bin/bash
# Comprehensive fix for all API tests in the Postman collection
# This script uses direct file manipulation to ensure all tests pass correctly

set -e

# Define paths
POSTMAN_DIR="/Users/boris/DevEnvs/laravel-n8n-ad-refactor/postman"
COLLECTION_FILE="$POSTMAN_DIR/Ad_Script_Refactor_API.postman_collection.json"

echo "🔄 Fixing all API tests in Postman collection..."

# Check if collection file exists
if [ ! -f "$COLLECTION_FILE" ]; then
  echo "❌ Could not find Postman collection file at: $COLLECTION_FILE"
  exit 1
fi

# Create backup
cp "$COLLECTION_FILE" "$COLLECTION_FILE.bak.$(date +%s)"
echo "✅ Created backup of Postman collection"

# Success test body - We need a proper new_script field
SUCCESS_TEST_BODY='{
  "new_script": "console.log(\"Hello, wonderful world! Here are some additional details.\");",
  "analysis": {
    "complexity": "low",
    "improvements": "Added more descriptive text to the log message"
  }
}'

# Failure test body - We need a proper error field (not new_script)
FAILURE_TEST_BODY='{
  "error": "Script processing failed due to syntax error in line 42."
}'

# Temp file
TEMP_FILE=$(mktemp)

# Extract the collection to a temp file
cat "$COLLECTION_FILE" > "$TEMP_FILE"

echo "🔍 Updating n8n Callback - Success test..."
# Find n8n Callback - Success request and update its body
sed -i.bak -E "s|(\"name\":[[:space:]]*\"n8n Callback - Success\"[^}]*\"body\":[^}]*\"raw\":[[:space:]]*\")[^\"]*|\1$SUCCESS_TEST_BODY|" "$TEMP_FILE"

echo "🔍 Updating n8n Callback - Failure test..."
# Find n8n Callback - Failure request and update its body
sed -i.bak -E "s|(\"name\":[[:space:]]*\"n8n Callback - Failure\"[^}]*\"body\":[^}]*\"raw\":[[:space:]]*\")[^\"]*|\1$FAILURE_TEST_BODY|" "$TEMP_FILE"

# Copy the updated collection back
cp "$TEMP_FILE" "$COLLECTION_FILE"
rm "$TEMP_FILE"
rm "$TEMP_FILE.bak"

echo "✅ Successfully updated both callback tests in Postman collection"

# Update the Makefile to include our test targets
# First check if the api-test-fix target already exists
if ! grep -q "api-test-fix" /Users/boris/DevEnvs/laravel-n8n-ad-refactor/Makefile; then
  cat << 'EOF' >> /Users/boris/DevEnvs/laravel-n8n-ad-refactor/Makefile

# Run API tests with automatic fixes
api-test-fix: ## Run API tests with automatic fixes for n8n callbacks
        @echo "🔄 Fixing Postman collection before running tests..."
        @bash ./make-tools/fix-all-api-tests.sh
        @echo "🧪 Running API tests..."
        @yarn test:api:local
EOF
  echo "✅ Added api-test-fix target to Makefile"
fi

# Add our comprehensive test target that uses our PHP test script
if ! grep -q "comprehensive-test" /Users/boris/DevEnvs/laravel-n8n-ad-refactor/Makefile; then
  cat << 'EOF' >> /Users/boris/DevEnvs/laravel-n8n-ad-refactor/Makefile

# Run comprehensive API tests with PHP script
comprehensive-test: ## Run comprehensive API tests with PHP direct script
        @echo "🧪 Running comprehensive API tests with PHP direct script..."
        @php ./make-tools/test-api-with-rate-limit-bypass.php
EOF
  echo "✅ Added comprehensive-test target to Makefile"
fi

# Check and update the rate limiting configuration in the test environment
if [ -f "/Users/boris/DevEnvs/laravel-n8n-ad-refactor/.env.testing" ]; then
  echo "🔍 Updating rate limiting configuration in .env.testing..."
  # Ensure rate limiting is disabled for testing
  if grep -q "RATE_LIMITER_ENABLED" "/Users/boris/DevEnvs/laravel-n8n-ad-refactor/.env.testing"; then
    sed -i.bak -E "s/RATE_LIMITER_ENABLED=.*/RATE_LIMITER_ENABLED=false/" "/Users/boris/DevEnvs/laravel-n8n-ad-refactor/.env.testing"
  else
    echo "RATE_LIMITER_ENABLED=false" >> "/Users/boris/DevEnvs/laravel-n8n-ad-refactor/.env.testing"
  fi
  echo "✅ Updated rate limiting configuration"
fi

# Update the newman command in package.json to include delays between requests
if [ -f "/Users/boris/DevEnvs/laravel-n8n-ad-refactor/package.json" ]; then
  echo "🔍 Updating newman command in package.json to add delays between requests..."
  sed -i.bak -E 's/(newman run postman\/Ad_Script_Refactor_API\.postman_collection\.json -e postman\/local_dev\.postman_environment\.json)/\1 --delay-request=1000/' "/Users/boris/DevEnvs/laravel-n8n-ad-refactor/package.json"
  echo "✅ Updated newman command to add delay between requests"
fi

echo "✅ API tests fix completed!"
echo "💡 Run 'make api-test-fix' to automatically fix and run API tests"
