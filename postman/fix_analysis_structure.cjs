const fs = require('fs');

// Read the modified collection
const collectionPath = './Ad_Script_Refactor_API_modified.postman_collection.json';
const outputPath = './Ad_Script_Refactor_API_fixed.postman_collection.json';

const collection = JSON.parse(fs.readFileSync(collectionPath, 'utf8'));

// Function to fix the success callback payload
function fixSuccessCallbackPayload(items) {
    items.forEach(item => {
        if (item.name === 'n8n Callback - Success' && item.request && item.request.body) {
            const rawBody = item.request.body.raw;
            if (rawBody.includes('"analysis"')) {
                // Replace the complex analysis structure with simple string values
                const fixedPayload = {
                    "new_script": "☀️ Epic Summer Vibes ONLY! 🤘 Cop the freshest deals before they melt away! 🔥 #SummerSteals #LimitedDrop",
                    "analysis": {
                        "improvements_made": "Used emojis to add visual appeal, adopted trendy language for younger audience, emphasized urgency with limited-time messaging",
                        "tone_analysis": "Playful, urgent, trendy - perfect for 18-25 demographic",
                        "target_audience_fit": "High compatibility with young adults through slang and emoji usage",
                        "length_compliance": "Successfully kept under 140 characters for social media",
                        "persuasiveness_enhancements": "Created urgency with 'before they melt away' and hashtag strategy",
                        "potential_issues": "Slang might become dated quickly, emoji display varies across platforms",
                        "recommendations": "A/B test with different emoji combinations, consider specific discount percentages"
                    }
                };
                
                item.request.body.raw = JSON.stringify(fixedPayload, null, 4);
                console.log('Fixed analysis structure in success callback');
            }
        }
        
        if (item.item) {
            fixSuccessCallbackPayload(item.item);
        }
    });
}

// Process all items in the collection
fixSuccessCallbackPayload(collection.item);

// Write the fixed collection
fs.writeFileSync(outputPath, JSON.stringify(collection, null, 2));
console.log('Fixed collection saved to:', outputPath); 