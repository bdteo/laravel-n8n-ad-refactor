const fs = require('fs');

// Read the fixed collection
const collectionPath = './Ad_Script_Refactor_API_fixed.postman_collection.json';
const outputPath = './Ad_Script_Refactor_API_final.postman_collection.json';

const collection = JSON.parse(fs.readFileSync(collectionPath, 'utf8'));

// Function to fix the failure callback pre-request script
function fixFailurePreRequest(items) {
    items.forEach(item => {
        if (item.name === 'n8n Callback - Failure' && item.event) {
            const preRequestEvent = item.event.find(e => e.listen === 'prerequest');
            if (preRequestEvent && preRequestEvent.script && preRequestEvent.script.exec) {
                // Find the line with header definition and add X-Disable-Rate-Limiting
                const scriptLines = preRequestEvent.script.exec;
                
                for (let i = 0; i < scriptLines.length; i++) {
                    if (scriptLines[i].includes("'Accept': 'application/json'")) {
                        // Insert the rate limiting bypass header after Accept header
                        scriptLines.splice(i + 1, 0, "        'X-Disable-Rate-Limiting': 'true',");
                        console.log('Added X-Disable-Rate-Limiting header to failure test pre-request script');
                        break;
                    }
                }
            }
        }
        
        if (item.item) {
            fixFailurePreRequest(item.item);
        }
    });
}

// Process all items in the collection
fixFailurePreRequest(collection.item);

// Write the final collection
fs.writeFileSync(outputPath, JSON.stringify(collection, null, 2));
console.log('Final collection saved to:', outputPath); 