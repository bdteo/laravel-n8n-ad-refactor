/**
 * Fix Postman Collection Script
 * 
 * This script updates the Postman collection to fix:
 * 1. The validation test by ensuring the outcome_description is shorter than the min:5 requirement
 * 2. The n8n callback test by providing a proper JSON body with new_script field
 */

import fs from 'fs';
import path from 'path';
import { fileURLToPath } from 'url';

// Get current directory in ESM
const __filename = fileURLToPath(import.meta.url);
const __dirname = path.dirname(__filename);

// Path to the Postman collection - support both local and Docker environments
let collectionPath = path.join('/var/www/postman', 'Ad_Script_Refactor_API.postman_collection.json');

// Check if running locally (not in Docker)
if (!fs.existsSync(collectionPath)) {
  collectionPath = path.join(__dirname, '..', 'postman', 'Ad_Script_Refactor_API.postman_collection.json');
}

try {
  // Read the collection file
  const collection = JSON.parse(fs.readFileSync(collectionPath, 'utf8'));
  
  // Find and update requests
  let validationTestFixed = false;
  let callbackTestFixed = false;
  
  const findAndUpdateRequests = (items) => {
    for (const item of items) {
      // Fix the Min Length validation test
      if (item.name === 'Create Ad Script Task (Validation Error - Min Length)') {
        // Update the request body to use a shorter outcome_description
        const requestBody = JSON.parse(item.request.body.raw);
        requestBody.outcome_description = 'Shrt'; // 4 characters to fail min:5 validation
        item.request.body.raw = JSON.stringify(requestBody, null, 4);
        console.log('✅ Successfully updated Min Length validation test');
        validationTestFixed = true;
      }
      
      // Fix the n8n Callback test
      if (item.name === 'n8n Callback - Success') {
        // Set a proper JSON body with new_script field
        const callbackBody = {
          "new_script": "console.log(\"Hello, wonderful world! Here are some additional details.\");",
          "analysis": {
            "complexity": "low",
            "improvements": "Added more descriptive text to the log message"
          }
        };
        
        // Update the request body
        item.request.body.raw = JSON.stringify(callbackBody, null, 4);
        console.log('✅ Successfully updated n8n Callback test');
        callbackTestFixed = true;
      }
      
      // Recursively check nested items
      if (item.item) {
        findAndUpdateRequests(item.item);
      }
    }
  };
  
  // Update the collection
  findAndUpdateRequests(collection.item);
  
  // Write the updated collection back to the file
  fs.writeFileSync(collectionPath, JSON.stringify(collection, null, 2));
  
  // Report results
  if (validationTestFixed && callbackTestFixed) {
    console.log('✅ Postman collection successfully updated');
  } else {
    if (!validationTestFixed) {
      console.error('❌ Could not find or update the Min Length validation test');
    }
    if (!callbackTestFixed) {
      console.error('❌ Could not find or update the n8n Callback test');
    }
  }
} catch (error) {
  console.error('❌ Error updating Postman collection:', error.message);
}
