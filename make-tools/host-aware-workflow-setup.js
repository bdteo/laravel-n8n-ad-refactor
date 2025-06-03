/**
 * Host-aware workflow setup script
 * Creates a workflow that works with the host.docker.internal configuration
 */

const fs = require('fs');
const { execSync } = require('child_process');

try {
  // Create a workflow that works with our Docker networking setup
  const workflow = {
    name: "Ad Script Refactor Workflow",
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
  const workflowPath = '/home/node/.n8n/workflows/ad-script-workflow.json';
  fs.writeFileSync(workflowPath, JSON.stringify(workflow, null, 2));
  console.log('Created host-aware workflow JSON');

  // Import and activate the workflow
  console.log('Importing workflow...');
  execSync('n8n import:workflow --input=/home/node/.n8n/workflows/ad-script-workflow.json');
  
  // Get workflow ID
  console.log('Getting workflow ID...');
  const listOutput = execSync('n8n list:workflow').toString();
  console.log('Workflow list:', listOutput);
  
  // Process the output to find the workflow ID
  const lines = listOutput.split('\n');
  let workflowId = null;
  
  for (const line of lines) {
    if (line.includes('Ad Script Refactor Workflow')) {
      // Different n8n versions might format this differently
      const parts = line.split(/\s*\|\s*/);
      if (parts.length > 1) {
        workflowId = parts[0].trim();
        break;
      }
    }
  }
  
  if (!workflowId) {
    throw new Error('Could not find workflow ID');
  }
  
  console.log(`Found workflow ID: ${workflowId}`);
  
  // Activate the workflow
  console.log('Activating workflow...');
  execSync(`n8n update:workflow --active=true --id=${workflowId}`);
  
  console.log('✅ Host-aware workflow successfully created and activated!');
} catch (err) {
  console.error('Error in workflow setup:', err);
  process.exit(1);
}
