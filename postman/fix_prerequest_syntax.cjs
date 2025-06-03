const fs = require('fs');

// Read the final collection with the syntax error
const collectionPath = './Ad_Script_Refactor_API_final.postman_collection.json';
const outputPath = './Ad_Script_Refactor_API_corrected.postman_collection.json';

const collection = JSON.parse(fs.readFileSync(collectionPath, 'utf8'));

// Function to fix the syntax error in the failure test pre-request script
function fixPreRequestSyntax(items) {
    items.forEach(item => {
        if (item.name === 'n8n Callback - Failure' && item.event) {
            const preRequestEvent = item.event.find(e => e.listen === 'prerequest');
            if (preRequestEvent && preRequestEvent.script && preRequestEvent.script.exec) {
                const scriptLines = preRequestEvent.script.exec;
                
                // Fix the syntax error - add missing comma after Accept header
                for (let i = 0; i < scriptLines.length; i++) {
                    if (scriptLines[i].includes("'Accept': 'application/json'") && 
                        !scriptLines[i].includes(',')) {
                        scriptLines[i] = "        'Accept': 'application/json',";
                        console.log('Fixed missing comma after Accept header');
                        break;
                    }
                }
            }
        }
        
        if (item.item) {
            fixPreRequestSyntax(item.item);
        }
    });
}

// Process all items in the collection
fixPreRequestSyntax(collection.item);

// Write the corrected collection
fs.writeFileSync(outputPath, JSON.stringify(collection, null, 2));
console.log('Corrected collection saved to:', outputPath); 