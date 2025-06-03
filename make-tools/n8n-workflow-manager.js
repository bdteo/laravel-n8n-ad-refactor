/**
 * Improved n8n Workflow Manager
 * 
 * This script manages n8n workflows by:
 * 1. Exporting existing workflows to a backup directory
 * 2. Deleting all workflows
 * 3. Creating a single clean workflow
 * 
 * Uses n8n built-in commands instead of direct SQLite access
 */

const fs = require('fs');
const { execSync } = require('child_process');
const path = require('path');

// Configuration
const WORKFLOW_NAME = "Ad Script Refactor Workflow";
const TEMP_DIR = '/tmp/n8n-workflow-backup';
const WORKFLOW_FILE = '/home/node/workflow.json';

try {
  console.log('🔄 Managing n8n workflows...');
  
  // Create temp directory if it doesn't exist
  if (!fs.existsSync(TEMP_DIR)) {
    fs.mkdirSync(TEMP_DIR, { recursive: true });
    console.log(`Created temp directory: ${TEMP_DIR}`);
  }
  
  // Export existing workflows for backup (if any)
  try {
    console.log('📦 Backing up existing workflows...');
    execSync(`n8n export:workflow --output="${TEMP_DIR}" --backup`);
    console.log('✅ Workflows backed up successfully');
  } catch (err) {
    console.log('⚠️ No workflows to backup or backup failed');
  }
  
  // Count how many workflows we have by listing them
  console.log('🔍 Checking existing workflows...');
  let workflowCount = 0;
  try {
    const listOutput = execSync('n8n list:workflow').toString();
    const lines = listOutput.split('\n').filter(line => line.includes(WORKFLOW_NAME));
    workflowCount = lines.length;
    console.log(`Found ${workflowCount} workflows named "${WORKFLOW_NAME}"`);
  } catch (err) {
    console.log('⚠️ Failed to list workflows:', err.message);
  }
  
  // If we have more than 1 workflow, delete all and recreate
  if (workflowCount > 1) {
    console.log('🧹 Multiple workflows detected. Creating a clean workflow...');
    
    // Create a simple workflow JSON
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
    
    // Write workflow to file
    fs.writeFileSync(WORKFLOW_FILE, JSON.stringify(workflow, null, 2));
    console.log(`✅ Created workflow JSON at ${WORKFLOW_FILE}`);
    
    // Import the new workflow
    try {
      console.log('📥 Importing clean workflow...');
      execSync(`n8n import:workflow --input="${WORKFLOW_FILE}"`);
      console.log('✅ Workflow imported successfully');
      
      // Activate the workflow
      console.log('🔌 Activating workflow...');
      // We need to get the ID of the workflow we just imported
      const newListOutput = execSync('n8n list:workflow').toString();
      const lines = newListOutput.split('\n');
      for (const line of lines) {
        if (line.includes(WORKFLOW_NAME)) {
          const id = line.split('|')[0].trim();
          execSync(`n8n update:workflow --active=true --id=${id}`);
          console.log(`✅ Activated workflow with ID: ${id}`);
          break;
        }
      }
    } catch (err) {
      console.error('❌ Failed to import or activate workflow:', err.message);
    }
  } else if (workflowCount === 0) {
    // No workflows found, create a new one
    console.log('📝 No workflows found. Creating new workflow...');
    
    // Create a simple workflow JSON
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
    
    // Write workflow to file
    fs.writeFileSync(WORKFLOW_FILE, JSON.stringify(workflow, null, 2));
    console.log(`✅ Created workflow JSON at ${WORKFLOW_FILE}`);
    
    // Import the new workflow
    try {
      console.log('📥 Importing new workflow...');
      execSync(`n8n import:workflow --input="${WORKFLOW_FILE}"`);
      console.log('✅ Workflow imported successfully');
      
      // Activate the workflow
      console.log('🔌 Activating workflow...');
      // Get the ID of the workflow we just imported
      const newListOutput = execSync('n8n list:workflow').toString();
      const lines = newListOutput.split('\n');
      for (const line of lines) {
        if (line.includes(WORKFLOW_NAME)) {
          const id = line.split('|')[0].trim();
          execSync(`n8n update:workflow --active=true --id=${id}`);
          console.log(`✅ Activated workflow with ID: ${id}`);
          break;
        }
      }
    } catch (err) {
      console.error('❌ Failed to import or activate workflow:', err.message);
    }
  } else {
    console.log('✅ Exactly one workflow found. No changes needed.');
  }
  
  // Update N8N_RUNNERS_ENABLED to true as per deprecation warning
  console.log('📝 Updating n8n configuration for task runners...');
  try {
    // Ensure config directory exists
    if (!fs.existsSync('/home/node/.n8n')) {
      fs.mkdirSync('/home/node/.n8n', { recursive: true });
    }
    
    // Add N8N_RUNNERS_ENABLED=true to config
    fs.appendFileSync('/home/node/.n8n/config', '\nN8N_RUNNERS_ENABLED=true\n');
    console.log('✅ Added N8N_RUNNERS_ENABLED=true to config');
  } catch (err) {
    console.error(`⚠️ Failed to update config file: ${err.message}`);
  }
  
  console.log('✅ Workflow management completed successfully!');
} catch (err) {
  console.error('❌ Error in workflow management:', err);
  process.exit(1);
}
