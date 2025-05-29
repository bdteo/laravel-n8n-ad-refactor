/**
 * Script to create webhook authentication credentials in n8n
 * This ensures proper authentication between Laravel and n8n
 */

const fs = require('fs');
const path = '/home/node/.n8n/credentials.json';

try {
  // Create or load existing credentials file
  let credentials = [];
  if (fs.existsSync(path)) {
    try {
      credentials = JSON.parse(fs.readFileSync(path, 'utf8'));
      // Filter out existing webhook authentication to avoid duplicates
      credentials = credentials.filter(cred => cred.id !== 'webhook-auth');
    } catch (e) {
      console.error('Error parsing existing credentials.json:', e);
      // If parsing fails, start with empty credentials
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
  fs.writeFileSync(path, JSON.stringify(credentials, null, 2));
  console.log('Successfully created/updated credentials.json with webhook authentication');
} catch (err) {
  console.error('Error handling credentials:', err);
  process.exit(1);
}
