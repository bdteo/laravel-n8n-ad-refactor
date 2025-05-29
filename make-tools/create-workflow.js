/**
 * Script to create a properly authenticated workflow in n8n
 * This ensures the webhook authentication works correctly
 */

const fs = require('fs');
const { execSync } = require('child_process');

// First, ensure credentials exist
try {
  const credPath = '/home/node/.n8n/credentials.json';
  let credentials = [];
  
  if (fs.existsSync(credPath)) {
    try {
      credentials = JSON.parse(fs.readFileSync(credPath, 'utf8'));
      // Filter out existing webhook authentication to avoid duplicates
      credentials = credentials.filter(cred => cred.id !== 'webhook-auth');
    } catch (e) {
      console.error('Error parsing existing credentials.json:', e);
      credentials = [];
    }
  }

  // Add webhook authentication credentials
  credentials.push({
    id: 'webhook-auth',
    name: 'Laravel Webhook Auth',
    data: {
      never_expires: true,
      value: 'a-very-strong-static-secret-laravel-sends-to-n8n',
      name: 'X-Laravel-Trigger-Auth'
    },
    type: 'httpHeaderAuth'
  });

  // Add OpenAI credentials if they don't exist
  if (!credentials.some(cred => cred.id === 'openai-credentials')) {
    credentials.push({
      id: 'openai-credentials',
      name: 'OpenAI API',
      data: {
        apiKey: 'sk-mock-key-for-testing-not-real',
        baseUrl: 'https://api.openai.com/v1'
      },
      type: 'openAiApi'
    });
  }

  // Write the updated credentials file
  fs.writeFileSync(credPath, JSON.stringify(credentials, null, 2));
  console.log('Successfully created/updated credentials.json with webhook authentication');

  // Now create the workflow with proper authentication
  const workflow = {
    name: 'Ad Script Refactor Workflow',
    active: false,
    nodes: [
      {
        parameters: {
          httpMethod: 'POST',
          path: 'ad-script-processing',
          options: {},
          authentication: 'headerAuth'
        },
        id: 'webhook-trigger',
        name: 'Webhook Trigger',
        type: 'n8n-nodes-base.webhook',
        typeVersion: 1,
        position: [240, 300],
        webhookId: 'ad-script-processing',
        credentials: {
          httpHeaderAuth: {
            id: 'webhook-auth',
            name: 'Laravel Webhook Auth'
          }
        }
      },
      {
        parameters: {
          values: {
            string: [
              {
                name: 'task_id',
                value: '={{ $json.task_id }}'
              },
              {
                name: 'reference_script',
                value: '={{ $json.reference_script }}'
              },
              {
                name: 'outcome_description',
                value: '={{ $json.outcome_description }}'
              }
            ]
          }
        },
        id: 'set-variables',
        name: 'Set Variables',
        type: 'n8n-nodes-base.set',
        typeVersion: 1,
        position: [460, 300]
      },
      {
        parameters: {
          authentication: 'predefinedCredentialType',
          operation: 'completion',
          model: 'gpt-4',
          promptType: 'text',
          promptValues: {
            text: 'You are an expert JavaScript developer specializing in advertising script optimization.\n\nPlease refactor the following advertising script to improve performance, readability, and maintainability:\n\n```javascript\n{{ $json.reference_script }}\n```\n\nDesired outcome: {{ $json.outcome_description }}\n\nRespond with a valid JSON object containing:\n- "new_script": The refactored code\n- "analysis": Object with analysis details including improvements made, performance impact, maintainability improvements, potential issues, and recommendations.'
          },
          options: {
            temperature: 0.3,
            maxTokens: 2000
          }
        },
        id: 'ai-agent',
        name: 'AI Agent',
        type: 'n8n-nodes-base.openAi',
        typeVersion: 1,
        position: [680, 300],
        credentials: {
          openAiApi: {
            id: 'openai-credentials',
            name: 'OpenAI API'
          }
        }
      },
      {
        parameters: {
          jsCode: "// Parse AI response and prepare data\ntry {\n  const aiResponse = $input.first().json.text || $input.first().json.choices[0].text || $input.first().json.choices[0].message.content;\n  let parsedResponse;\n  \n  try {\n    parsedResponse = JSON.parse(aiResponse);\n  } catch (parseError) {\n    // Try to extract JSON if wrapped in markdown or other text\n    const jsonMatch = aiResponse.match(/```json\\s*([\\s\\S]*?)\\s*```/) || \n                      aiResponse.match(/\\{[\\s\\S]*\\}/);\n    if (jsonMatch) {\n      parsedResponse = JSON.parse(jsonMatch[1] || jsonMatch[0]);\n    } else {\n      throw new Error('Could not parse JSON from response');\n    }\n  }\n  \n  // Validate required fields\n  if (!parsedResponse.new_script || !parsedResponse.analysis) {\n    throw new Error('Invalid AI response: missing required fields');\n  }\n  \n  // Get task_id from the original data\n  const taskId = $('set-variables').first().json.task_id;\n  \n  return {\n    task_id: taskId,\n    new_script: parsedResponse.new_script,\n    analysis: parsedResponse.analysis,\n    status: 'success'\n  };\n} catch (error) {\n  // Get task_id from the original data\n  const taskId = $('set-variables').first().json.task_id;\n  \n  return {\n    task_id: taskId,\n    error: `Failed to process AI response: ${error.message}`,\n    status: 'error'\n  };\n}"
        },
        id: 'process-response',
        name: 'Process Response',
        type: 'n8n-nodes-base.code',
        typeVersion: 1,
        position: [900, 300]
      },
      {
        parameters: {
          url: 'http://app/api/ad-scripts/{{ $json.task_id }}/result',
          authentication: 'predefinedCredentialType',
          nodeCredentialType: 'httpHeaderAuth',
          method: 'POST',
          sendHeaders: true,
          headerParameters: {
            parameters: [
              {
                name: 'Content-Type',
                value: 'application/json'
              },
              {
                name: 'Accept',
                value: 'application/json'
              }
            ]
          },
          sendBody: true,
          specifyBody: 'json',
          jsonBody: '={{ $json }}',
          options: {}
        },
        id: 'callback-request',
        name: 'Callback Request',
        type: 'n8n-nodes-base.httpRequest',
        typeVersion: 3,
        position: [1120, 300],
        credentials: {
          httpHeaderAuth: {
            id: 'webhook-auth',
            name: 'Laravel Webhook Auth'
          }
        }
      },
      {
        parameters: {
          respondWith: 'json',
          responseBody: '={{ { "status": "processing", "message": "Processing started", "task_id": $json.task_id } }}'
        },
        id: 'webhook-response',
        name: 'Webhook Response',
        type: 'n8n-nodes-base.respondToWebhook',
        typeVersion: 1,
        position: [680, 460]
      }
    ],
    connections: {
      'webhook-trigger': {
        main: [
          [
            {
              node: 'set-variables',
              type: 'main',
              index: 0
            }
          ]
        ]
      },
      'set-variables': {
        main: [
          [
            {
              node: 'ai-agent',
              type: 'main',
              index: 0
            },
            {
              node: 'webhook-response',
              type: 'main',
              index: 0
            }
          ]
        ]
      },
      'ai-agent': {
        main: [
          [
            {
              node: 'process-response',
              type: 'main',
              index: 0
            }
          ]
        ]
      },
      'process-response': {
        main: [
          [
            {
              node: 'callback-request',
              type: 'main',
              index: 0
            }
          ]
        ]
      }
    }
  };

  // Write the workflow to a file
  const workflowPath = '/home/node/.n8n/workflows/ad-script-refactor-workflow.json';
  fs.writeFileSync(workflowPath, JSON.stringify(workflow, null, 2));
  console.log('Successfully created workflow JSON with proper authentication');

  // Now let's directly create and activate the workflow using the n8n API
  try {
    // Create the workflow
    console.log('Importing workflow with n8n CLI...');
    execSync('n8n import:workflow --input=/home/node/.n8n/workflows/ad-script-refactor-workflow.json');
    
    // Get the workflow ID using a more robust approach
    console.log('Getting workflow ID...');
    fs.writeFileSync('/tmp/workflow_list.txt', execSync('n8n list:workflow').toString());
    const listOutput = fs.readFileSync('/tmp/workflow_list.txt', 'utf8');
    console.log('Output from n8n list:workflow:');
    console.log(listOutput);
    
    // Use a more flexible regex pattern to match the workflow ID
    const lines = listOutput.split('\n');
    let workflowId = null;
    
    for (const line of lines) {
      if (line.includes('Ad Script Refactor Workflow')) {
        const parts = line.split('|').map(p => p.trim());
        if (parts.length > 0) {
          workflowId = parts[0];
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
    
    console.log('✅ Workflow successfully created and activated!');
  } catch (error) {
    console.error('Error creating or activating workflow:', error.message);
    process.exit(1);
  }
} catch (err) {
  console.error('Error creating workflow:', err);
  process.exit(1);
}
