const fs = require('fs');

// Read the original collection
const collectionPath = './Ad_Script_Refactor_API.postman_collection.json';
const outputPath = './Ad_Script_Refactor_API_modified.postman_collection.json';

const collection = JSON.parse(fs.readFileSync(collectionPath, 'utf8'));

// Function to add header to a request
function addHeader(request) {
    if (!request.header) {
        request.header = [];
    }
    
    // Check if the header already exists
    const existingHeader = request.header.find(h => h.key === 'X-Disable-Rate-Limiting');
    if (!existingHeader) {
        request.header.push({
            key: 'X-Disable-Rate-Limiting',
            value: 'true'
        });
    }
}

// Function to recursively process items
function processItems(items) {
    items.forEach(item => {
        if (item.request) {
            addHeader(item.request);
        }
        if (item.item) {
            processItems(item.item);
        }
    });
}

// Process all items in the collection
processItems(collection.item);

// Write the modified collection
fs.writeFileSync(outputPath, JSON.stringify(collection, null, 2));
console.log('Modified collection saved to:', outputPath); 