/**
 * Manage n8n Workflows Script
 * 
 * This script manages n8n workflows by:
 * 1. Listing all existing workflows
 * 2. Deleting duplicate workflows with the same name
 * 3. Creating a new workflow only if needed
 * 
 * This avoids the need to remove volumes and restart containers.
 */

const fs = require('fs');
const { execSync } = require('child_process');

// Configuration
const WORKFLOW_NAME = "Ad Script Refactor Workflow";
const WORKFLOW_FILE_PATH = '/home/node/.n8n/workflows/ad-script-workflow.json';

try {
  console.log('🔍 Listing existing workflows...');
  const listOutput = execSync('n8n list:workflow').toString();
  console.log(listOutput);
  
  // Parse the output to find workflow IDs
  const lines = listOutput.split('\n');
  const workflowIds = [];
  
  // Find all workflows with our target name
  for (const line of lines) {
    if (line.includes(WORKFLOW_NAME)) {
      const parts = line.split(/\s*\|\s*/);
      if (parts.length > 1) {
        const id = parts[0].trim();
        workflowIds.push(id);
      }
    }
  }
  
  console.log(`Found ${workflowIds.length} workflows named "${WORKFLOW_NAME}"`);
  
  // Keep only the most recent workflow if multiple exist
  if (workflowIds.length > 1) {
    console.log('🗑️ Cleaning up duplicate workflows...');
    
    // Keep the most recent workflow (last one in the list)
    const keepId = workflowIds[workflowIds.length - 1];
    
    // Delete all other workflows
    for (const id of workflowIds) {
      if (id !== keepId) {
        console.log(`Deleting workflow with ID: ${id}`);
        try {
          // Using SQL directly since there's no delete CLI command
          execSync(`sqlite3 /home/node/.n8n/database.sqlite "DELETE FROM workflows WHERE id='${id}'"`);
        } catch (err) {
          console.error(`Failed to delete workflow ${id}: ${err.message}`);
        }
      }
    }
  }
  
  // Create our workflow if no workflows exist with our name
  if (workflowIds.length === 0) {
    console.log('📝 Creating new workflow...');
    
    // Create a workflow that works with our Docker networking setup
    const workflow = {
      name: WORKFLOW_NAME,
      active: true,
      nodes: [
        {
          parameters: {
            httpMethod: "POST",
            path: "ad-script-refactor-openrouter",
            options: {}
          },
          id: "webhook-trigger",
          name: "Webhook",
          type: "n8n-nodes-base.webhook",
          typeVersion: 1,
          position: [240, 300]
        },
        {
          parameters: {
            respondWith: "json",
            responseBody: "={ { \"status\": \"processing\", \"message\": \"Processing started\", \"task_id\": $json.task_id } }"
          },
          id: "webhook-response",
          name: "Respond to Webhook",
          type: "n8n-nodes-base.respondToWebhook",
          typeVersion: 1,
          position: [460, 300]
        }
      ],
      connections: {
        "webhook-trigger": {
          main: [
            [
              {
                node: "webhook-response",
                type: "main",
                index: 0
              }
            ]
          ]
        }
      }
    };

    // Write the workflow to a file
    fs.writeFileSync(WORKFLOW_FILE_PATH, JSON.stringify(workflow, null, 2));
    console.log('Created workflow JSON file');

    // Import and activate the workflow
    console.log('Importing workflow...');
    execSync(`n8n import:workflow --input=${WORKFLOW_FILE_PATH}`);
    
    // Get the new workflow ID
    const newListOutput = execSync('n8n list:workflow').toString();
    const newLines = newListOutput.split('\n');
    let newWorkflowId = null;
    
    for (const line of newLines) {
      if (line.includes(WORKFLOW_NAME)) {
        const parts = line.split(/\s*\|\s*/);
        if (parts.length > 1) {
          newWorkflowId = parts[0].trim();
          break;
        }
      }
    }
    
    if (newWorkflowId) {
      console.log(`Activating workflow with ID: ${newWorkflowId}`);
      execSync(`n8n update:workflow --active=true --id=${newWorkflowId}`);
    }
  }
  
  // Update N8N_RUNNERS_ENABLED to true as per deprecation warning
  console.log('📝 Updating n8n configuration for task runners...');
  try {
    fs.appendFileSync('/home/node/.n8n/config', '\nN8N_RUNNERS_ENABLED=true\n');
    console.log('✅ Added N8N_RUNNERS_ENABLED=true to config');
  } catch (err) {
    console.error(`Failed to update config file: ${err.message}`);
  }
  
  console.log('✅ Workflow management completed successfully!');
} catch (err) {
  console.error('❌ Error in workflow management:', err);
  process.exit(1);
}
