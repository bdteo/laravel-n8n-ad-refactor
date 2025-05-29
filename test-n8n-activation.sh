#!/bin/bash

echo "Checking n8n container status..."
docker compose ps n8n

echo -e "\nChecking n8n workflow files..."
ls -la ./n8n/workflows/

echo -e "\nWaiting for n8n to be fully ready..."
sleep 5

echo -e "\nTrying to get workflows via API..."

# First approach - using curl from host to n8n container
echo "Approach 1: Direct API call to n8n container"
curl -v -X GET http://localhost:5678/rest/workflows

echo -e "\n\nApproach 2: Testing n8n API authentication"
curl -v -X GET http://localhost:5678/rest/me

echo -e "\n\nApproach 3: Using workflow ID from the JSON file"
WORKFLOW_NAME="Ad Script Refactor Workflow"
echo "Looking for workflow: $WORKFLOW_NAME"

# Let's check if we can parse the ID from the JSON file
if grep -q "\"name\": \"$WORKFLOW_NAME\"" ./n8n/workflows/ad-script-refactor-workflow.json; then
    echo "Found workflow in JSON file"
    
    # Extract the workflow ID if present in the file
    ID=$(grep -o '"id": *"[^"]*"' ./n8n/workflows/ad-script-refactor-workflow.json | head -1 | cut -d'"' -f4)
    
    if [ -n "$ID" ]; then
        echo "Found workflow ID: $ID"
        echo "Attempting to activate workflow using the extracted ID..."
        
        # Try to activate the workflow
        curl -v -X PUT \
            -H "Content-Type: application/json" \
            -d '{"active": true}' \
            http://localhost:5678/rest/workflows/$ID/activate
    else
        echo "No workflow ID found in the JSON file"
    fi
else
    echo "Workflow not found in JSON file"
fi

echo -e "\n\nTest complete."
