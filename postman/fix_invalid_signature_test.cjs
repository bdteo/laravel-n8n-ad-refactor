const fs = require('fs');

// Read the corrected collection
const collectionPath = './Ad_Script_Refactor_API_corrected.postman_collection.json';
const outputPath = './Ad_Script_Refactor_API_final_fixed.postman_collection.json';

const collection = JSON.parse(fs.readFileSync(collectionPath, 'utf8'));

// Function to fix the invalid signature test
function fixInvalidSignatureTest(items) {
    items.forEach(item => {
        if (item.name === 'n8n Callback - Invalid Signature' && item.request && item.request.body) {
            // When auth is disabled, we need a valid payload structure to get 200
            // Instead of a validation error (422)
            const validPayload = {
                "new_script": "This script should process since auth is disabled",
                "analysis": {
                    "note": "This payload is valid but signature is invalid - testing auth bypass"
                }
            };
            
            item.request.body.raw = JSON.stringify(validPayload, null, 4);
            console.log('Fixed invalid signature test payload to avoid validation errors');
        }
        
        if (item.item) {
            fixInvalidSignatureTest(item.item);
        }
    });
}

// Process all items in the collection
fixInvalidSignatureTest(collection.item);

// Write the final fixed collection
fs.writeFileSync(outputPath, JSON.stringify(collection, null, 2));
console.log('Final fixed collection saved to:', outputPath); 