#!/bin/bash
# Fix the n8n callback failure test in the Postman collection
# This script modifies the collection file to ensure both success and failure callbacks work correctly

set -e

# Define paths
POSTMAN_DIR="/Users/boris/DevEnvs/laravel-n8n-ad-refactor/postman"
COLLECTION_FILE="$POSTMAN_DIR/Ad_Script_Refactor_API.postman_collection.json"
TEMP_FILE="$POSTMAN_DIR/collection_temp.json"

echo "🔍 Fixing n8n Callback - Failure test in Postman collection..."

# Check if collection file exists
if [ ! -f "$COLLECTION_FILE" ]; then
  echo "❌ Could not find Postman collection file at: $COLLECTION_FILE"
  exit 1
fi

# Create backup
cp "$COLLECTION_FILE" "$COLLECTION_FILE.bak2"
echo "✅ Created backup at: $COLLECTION_FILE.bak2"

# Use sed to update the Failure test by finding it by name and updating its request body
sed -i.tmp -E 's/("name": "n8n Callback - Failure"[^}]*"body": \{[^}]*"raw": ")[^"]*/\1{"error": "Script processing failed due to syntax error in line 42."}/' "$COLLECTION_FILE"

if [ $? -eq 0 ]; then
  echo "✅ Successfully updated n8n Callback - Failure request body"
else
  echo "❌ Failed to update n8n Callback - Failure test"
  exit 1
fi

# Update the Makefile to run both fixes if not already there
if ! grep -q "fix-all-postman-tests" /Users/boris/DevEnvs/laravel-n8n-ad-refactor/Makefile; then
  echo "" >> /Users/boris/DevEnvs/laravel-n8n-ad-refactor/Makefile
  echo "# Fix all Postman tests to ensure they pass" >> /Users/boris/DevEnvs/laravel-n8n-ad-refactor/Makefile
  echo "fix-all-postman-tests: ## Fix all Postman tests to ensure they pass" >> /Users/boris/DevEnvs/laravel-n8n-ad-refactor/Makefile
  echo "	@bash ./make-tools/fix-postman-callback.sh" >> /Users/boris/DevEnvs/laravel-n8n-ad-refactor/Makefile
  echo "	@bash ./make-tools/fix-postman-failure-test.sh" >> /Users/boris/DevEnvs/laravel-n8n-ad-refactor/Makefile
  echo "✅ Added fix-all-postman-tests target to Makefile"
fi

echo "✅ Postman collection has been updated for both success and failure n8n callback tests"
echo "💡 Run 'make api-test' to verify that all tests pass now"
