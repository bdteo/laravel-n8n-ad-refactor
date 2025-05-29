#!/bin/bash
# Script to run the n8n workflow manager inside the n8n container

# Copy the script to the n8n container
echo "📋 Copying workflow management script to n8n container..."
docker cp "$(dirname "$0")/n8n-workflow-manager.js" laravel-n8n:/home/node/n8n-workflow-manager.js

# Run the script inside the n8n container
echo "🔄 Running workflow management script in n8n container..."
docker exec laravel-n8n node /home/node/n8n-workflow-manager.js

# Return status
exit_code=$?
if [ $exit_code -eq 0 ]; then
  echo "✅ n8n workflow management completed successfully!"
else
  echo "❌ n8n workflow management failed with exit code $exit_code"
fi

exit $exit_code
