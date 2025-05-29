// Simple script to update Postman pre-request scripts
import fs from 'fs';
import path from 'path';
import { fileURLToPath } from 'url';

// Get current directory
const __filename = fileURLToPath(import.meta.url);
const __dirname = path.dirname(__filename);

const collectionFile = path.join(__dirname, 'Ad_Script_Refactor_API.postman_collection.json');
const collection = JSON.parse(fs.readFileSync(collectionFile, 'utf8'));

// Read the improved script
const improvedScript = fs.readFileSync(path.join(__dirname, 'improved_prerequest_script.js'), 'utf8');

// Find n8n callbacks folder
const n8nCallbacksFolder = collection.item.find(folder => folder.name === 'n8n Callbacks (Simulated)');

if (!n8nCallbacksFolder) {
  console.error('Could not find n8n Callbacks folder!');
  process.exit(1);
}

// List of request names to update
const requestsToUpdate = [
  'n8n Callback - Failure',
  'n8n Callback - Validation Error (e.g., missing new_script and error)'
];

// Count of updates
let updateCount = 0;

// Update pre-request scripts in those requests
n8nCallbacksFolder.item.forEach(request => {
  if (requestsToUpdate.includes(request.name)) {
    // Find the pre-request script event
    const prereqEvent = request.event?.find(evt => evt.listen === 'prerequest');
    
    if (prereqEvent && prereqEvent.script) {
      // Update the script
      prereqEvent.script.exec = improvedScript.split('\n');
      updateCount++;
      console.log(`Updated pre-request script for: ${request.name}`);
    }
  }
});

if (updateCount === 0) {
  console.log('No scripts were updated! Check request names.');
} else {
  // Write back the updated collection
  fs.writeFileSync(collectionFile, JSON.stringify(collection, null, 2));
  console.log(`Successfully updated ${updateCount} pre-request scripts.`);
}
