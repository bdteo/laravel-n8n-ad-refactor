<?php
/**
 * Fix Postman Collection Script
 * 
 * This script updates the Postman collection to fix the validation test issue
 * by ensuring the outcome_description is shorter than the min:5 requirement
 */

// Path to the Postman collection
$collectionPath = '/var/www/postman/Ad_Script_Refactor_API.postman_collection.json';

try {
    // Read the collection file
    $collection = json_decode(file_get_contents($collectionPath), true);
    
    // Helper function to find and update the request
    function findAndUpdateRequest(&$items) {
        foreach ($items as &$item) {
            if (isset($item['name']) && $item['name'] === 'Create Ad Script Task (Validation Error - Min Length)') {
                // Update the request body to use a shorter outcome_description
                $requestBody = json_decode($item['request']['body']['raw'], true);
                $requestBody['outcome_description'] = 'Shrt'; // 4 characters to fail min:5 validation
                $item['request']['body']['raw'] = json_encode($requestBody, JSON_PRETTY_PRINT);
                echo "✅ Successfully updated Min Length validation test\n";
                return true;
            } else if (isset($item['item'])) {
                if (findAndUpdateRequest($item['item'])) {
                    return true;
                }
            }
        }
        return false;
    }
    
    // Update the collection
    if (findAndUpdateRequest($collection['item'])) {
        // Write the updated collection back to the file
        file_put_contents($collectionPath, json_encode($collection, JSON_PRETTY_PRINT));
        echo "✅ Postman collection successfully updated\n";
    } else {
        echo "❌ Could not find the Min Length validation test in the collection\n";
    }
} catch (Exception $e) {
    echo "❌ Error updating Postman collection: " . $e->getMessage() . "\n";
}
