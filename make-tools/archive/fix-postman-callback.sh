#!/bin/bash
# Fix the n8n callback test in the Postman collection
# This script directly modifies the collection file to ensure the callback request has the proper JSON body

set -e

# Define paths for both Docker and local environments
POSTMAN_DIR="/Users/boris/DevEnvs/laravel-n8n-ad-refactor/postman"
COLLECTION_FILE="$POSTMAN_DIR/Ad_Script_Refactor_API.postman_collection.json"
TEMP_FILE="$POSTMAN_DIR/collection_temp.json"

echo "🔍 Fixing Postman collection for API tests..."

# Check if collection file exists
if [ ! -f "$COLLECTION_FILE" ]; then
  echo "❌ Could not find Postman collection file at: $COLLECTION_FILE"
  exit 1
fi

# Create backup
cp "$COLLECTION_FILE" "$COLLECTION_FILE.bak"
echo "✅ Created backup at: $COLLECTION_FILE.bak"

# Use jq to find and update the callback test
# The strategy is to identify the callback test by name and update its raw request body
cat "$COLLECTION_FILE" | jq '(.item[] | select(.name == "n8n Callbacks (Simulated)") | .item[] | select(.name == "n8n Callback - Success") | .request.body.raw) = "{\"new_script\": \"console.log(\\\"Hello, wonderful world! Here are some additional details.\\\");\", \"analysis\": {\"complexity\": \"low\", \"improvements\": \"Added more descriptive text to the log message\"}}"' > "$TEMP_FILE"

if [ $? -eq 0 ]; then
  mv "$TEMP_FILE" "$COLLECTION_FILE"
  echo "✅ Successfully updated n8n Callback request body"
else
  echo "❌ Failed to update collection. Using manual approach..."
  
  # If jq fails, use manual search and replace approach
  # Extract the JSON string we need to replace
  CALLBACK_JSON="{\"new_script\": \"console.log(\\\"Hello, wonderful world! Here are some additional details.\\\");\", \"analysis\": {\"complexity\": \"low\", \"improvements\": \"Added more descriptive text to the log message\"}}"
  
  # Use sed to find and replace the request body after "n8n Callback - Success"
  sed -i.tmp -E 's/("name": "n8n Callback - Success"[^}]*"body": \{[^}]*"raw": ")[^"]*/\1'"$CALLBACK_JSON"'/' "$COLLECTION_FILE"
  
  echo "✅ Updated collection using manual approach"
fi

# Add this fix to the Makefile
if ! grep -q "fix-postman-tests" /Users/boris/DevEnvs/laravel-n8n-ad-refactor/Makefile; then
  echo "" >> /Users/boris/DevEnvs/laravel-n8n-ad-refactor/Makefile
  echo "# Fix the Postman collection for API tests" >> /Users/boris/DevEnvs/laravel-n8n-ad-refactor/Makefile
  echo "fix-postman-tests: ## Fix the Postman collection for API tests" >> /Users/boris/DevEnvs/laravel-n8n-ad-refactor/Makefile
  echo "	@bash ./make-tools/fix-postman-callback.sh" >> /Users/boris/DevEnvs/laravel-n8n-ad-refactor/Makefile
  echo "✅ Added fix-postman-tests target to Makefile"
fi

echo "✅ Postman collection has been updated with proper JSON body for n8n callback test"
echo "💡 Run 'make api-test' to verify that the tests pass now"
