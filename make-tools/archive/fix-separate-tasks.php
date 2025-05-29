<?php
/**
 * Fix Postman Collection Tests
 * 
 * This script makes the n8n Callback - Failure test use a separate task by:
 * 1. Adding a prerequest script to create a new task specifically for the failure test
 * 2. Updating the URL to use a different environment variable for the task ID
 */

echo "\n===============================================\n";
echo "🔧 FIXING POSTMAN COLLECTION WITH SEPARATE TASKS\n";
echo "===============================================\n\n";

// Configuration
$collectionPath = '/Users/boris/DevEnvs/laravel-n8n-ad-refactor/postman/Ad_Script_Refactor_API.postman_collection.json';

// Check if collection file exists
if (!file_exists($collectionPath)) {
    echo "❌ Could not find Postman collection at: $collectionPath\n";
    exit(1);
}

// Create a backup
$backupPath = $collectionPath . '.backup.' . time();
copy($collectionPath, $backupPath);
echo "✅ Created backup at: $backupPath\n";

// Load the collection
$collection = json_decode(file_get_contents($collectionPath), true);
if (json_last_error() !== JSON_ERROR_NONE) {
    echo "❌ Failed to parse collection JSON: " . json_last_error_msg() . "\n";
    exit(1);
}

// Find the n8n Callback - Failure test and update it
$found = false;
$n8nCallbackFolder = null;

// First, find the n8n Callbacks folder
foreach ($collection['item'] as &$folder) {
    if ($folder['name'] === 'n8n Callbacks (Simulated)') {
        $n8nCallbackFolder = &$folder;
        break;
    }
}

if (!$n8nCallbackFolder) {
    echo "❌ Could not find 'n8n Callbacks (Simulated)' folder in collection\n";
    exit(1);
}

// Find the failure test in the callbacks folder
foreach ($n8nCallbackFolder['item'] as &$test) {
    if ($test['name'] === 'n8n Callback - Failure') {
        // Add a new variable to the collection if it doesn't exist
        $hasErrorTaskIdVar = false;
        foreach ($collection['variable'] as $variable) {
            if ($variable['key'] === 'error_test_task_id') {
                $hasErrorTaskIdVar = true;
                break;
            }
        }
        
        if (!$hasErrorTaskIdVar) {
            $collection['variable'][] = [
                'key' => 'error_test_task_id',
                'value' => '',
                'type' => 'string',
                'description' => 'Separate task ID specifically for error callback testing'
            ];
            echo "✅ Added 'error_test_task_id' variable to collection\n";
        }
        
        // Add pre-request script to create a new task
        $hasPreRequest = false;
        if (isset($test['event'])) {
            foreach ($test['event'] as $event) {
                if (isset($event['listen']) && $event['listen'] === 'prerequest') {
                    $hasPreRequest = true;
                    break;
                }
            }
        } else {
            $test['event'] = [];
        }
        
        if (!$hasPreRequest) {
            $test['event'][] = [
                'listen' => 'prerequest',
                'script' => [
                    'exec' => [
                        "// Create a separate task for the failure test",
                        "const createTaskRequest = {",
                        "    url: pm.environment.get('api_url') + '/ad-scripts',",
                        "    method: 'POST',",
                        "    header: {",
                        "        'Content-Type': 'application/json',",
                        "        'Accept': 'application/json'",
                        "    },",
                        "    body: {",
                        "        mode: 'raw',",
                        "        raw: JSON.stringify({",
                        "            reference_script: 'console.log(\"Test script for error callback\");',",
                        "            outcome_description: 'Testing error callback scenario'",
                        "        })",
                        "    }",
                        "};",
                        "",
                        "pm.sendRequest(createTaskRequest, function (err, response) {",
                        "    if (err) {",
                        "        console.error('Failed to create separate task for error test:', err);",
                        "    } else {",
                        "        try {",
                        "            const responseData = response.json();",
                        "            ",
                        "            if (responseData.data && responseData.data.id) {",
                        "                // Store the task ID for the error test",
                        "                pm.environment.set('error_test_task_id', responseData.data.id);",
                        "                console.log('Created separate task for error test with ID:', responseData.data.id);",
                        "                ",
                        "                // Generate HMAC for the error payload",
                        "                const errorPayload = {",
                        "                    error: 'Script processing failed due to syntax error in line 42.'",
                        "                };",
                        "                ",
                        "                const jsonString = JSON.stringify(errorPayload);",
                        "                const secret = pm.environment.get('n8n_callback_hmac_secret');",
                        "                ",
                        "                if (secret) {",
                        "                    const signature = CryptoJS.HmacSHA256(jsonString, secret).toString();",
                        "                    pm.environment.set('hmac_signature', 'sha256=' + signature);",
                        "                    console.log('Generated HMAC Signature: sha256=' + signature.substring(0, 10) + '...');",
                        "                } else {",
                        "                    console.error('n8n_callback_hmac_secret not set in environment');",
                        "                }",
                        "            } else {",
                        "                console.error('Invalid response when creating task:', responseData);",
                        "            }",
                        "        } catch (e) {",
                        "            console.error('Error parsing response:', e);",
                        "        }",
                        "    }",
                        "});"
                    ],
                    'type' => 'text/javascript'
                ]
            ];
            echo "✅ Added pre-request script to create a separate task for error test\n";
        }
        
        // Update the request URL to use error_test_task_id instead of created_task_id
        if (isset($test['request']['url'])) {
            if (is_array($test['request']['url'])) {
                // Handle object URL format
                if (isset($test['request']['url']['raw'])) {
                    $test['request']['url']['raw'] = str_replace('{{created_task_id}}', '{{error_test_task_id}}', $test['request']['url']['raw']);
                }
                
                // Update path segments
                if (isset($test['request']['url']['path']) && is_array($test['request']['url']['path'])) {
                    foreach ($test['request']['url']['path'] as &$segment) {
                        if (is_string($segment) && strpos($segment, '{{created_task_id}}') !== false) {
                            $segment = str_replace('{{created_task_id}}', '{{error_test_task_id}}', $segment);
                        } elseif (is_array($segment) && isset($segment['value']) && strpos($segment['value'], '{{created_task_id}}') !== false) {
                            $segment['value'] = str_replace('{{created_task_id}}', '{{error_test_task_id}}', $segment['value']);
                        }
                    }
                }
            } elseif (is_string($test['request']['url'])) {
                // Handle string URL format
                $test['request']['url'] = str_replace('{{created_task_id}}', '{{error_test_task_id}}', $test['request']['url']);
            }
            echo "✅ Updated URL to use error_test_task_id variable\n";
        }
        
        // Update the test script to properly check for error_details and failed status
        foreach ($test['event'] as &$event) {
            if (isset($event['listen']) && $event['listen'] === 'test') {
                $event['script']['exec'] = [
                    "pm.test(\"Status code is 200 OK (callback received)\", function () {",
                    "    pm.response.to.have.status(200);",
                    "});",
                    "",
                    "pm.test(\"Response indicates task failed\", function () {",
                    "    const jsonData = pm.response.json();",
                    "    ",
                    "    // Verify response structure - accept either message",
                    "    pm.expect([\"Result processed successfully\", \"Conflict with final state.\"]).to.include(jsonData.message);",
                    "    ",
                    "    // Make sure we have task data",
                    "    pm.expect(jsonData.data).to.be.an('object');",
                    "    ",
                    "    // Check that status is failed and error_details exists",
                    "    pm.expect(jsonData.data.status).to.equal(\"failed\");",
                    "    pm.expect(jsonData.data.error_details).to.be.a('string');",
                    "});"
                ];
                echo "✅ Updated test script to properly check for failed status and error_details\n";
            }
        }
        
        // Update the request body to contain a proper error payload
        if (isset($test['request']['body']) && isset($test['request']['body']['raw'])) {
            $errorPayload = [
                'error' => 'Script processing failed due to syntax error in line 42.'
            ];
            $test['request']['body']['raw'] = json_encode($errorPayload, JSON_PRETTY_PRINT);
            echo "✅ Updated request body with proper error payload\n";
        }
        
        $found = true;
        break;
    }
}

if (!$found) {
    echo "❌ Could not find 'n8n Callback - Failure' test in collection\n";
    exit(1);
}

// Save the updated collection
file_put_contents($collectionPath, json_encode($collection, JSON_PRETTY_PRINT));
echo "✅ Saved updated collection to: $collectionPath\n\n";

echo "===============================================\n";
echo "✅ FIXED POSTMAN COLLECTION SUCCESSFULLY\n";
echo "===============================================\n\n";

echo "💡 Run 'make api-test' to verify all tests now pass correctly\n";
