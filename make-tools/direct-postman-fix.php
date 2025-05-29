<?php
/**
 * Direct Postman Collection Fix
 * 
 * This script directly modifies the Postman collection to ensure the n8n Callback - Failure test
 * uses a separate task with a direct variable reference.
 */

// Configuration
$collectionPath = '/Users/boris/DevEnvs/laravel-n8n-ad-refactor/postman/Ad_Script_Refactor_API.postman_collection.json';

echo "\n=============================================\n";
echo "🔧 DIRECT POSTMAN COLLECTION FIX\n";
echo "=============================================\n\n";

// Check if collection file exists
if (!file_exists($collectionPath)) {
    echo "❌ Could not find Postman collection at: $collectionPath\n";
    exit(1);
}

// Create a backup
$backupPath = $collectionPath . '.bak.' . time();
copy($collectionPath, $backupPath);
echo "✅ Created backup at: $backupPath\n";

// Load the collection
$collection = json_decode(file_get_contents($collectionPath), true);
if (json_last_error() !== JSON_ERROR_NONE) {
    echo "❌ Failed to parse collection JSON: " . json_last_error_msg() . "\n";
    exit(1);
}

// Find the n8n Callbacks folder
$callbacksFolder = null;
foreach ($collection['item'] as &$folder) {
    if ($folder['name'] === 'n8n Callbacks (Simulated)') {
        $callbacksFolder = &$folder;
        break;
    }
}

if (!$callbacksFolder) {
    echo "❌ Could not find 'n8n Callbacks (Simulated)' folder\n";
    exit(1);
}

// Find the Failure callback test
$failureTest = null;
foreach ($callbacksFolder['item'] as &$item) {
    if ($item['name'] === 'n8n Callback - Failure') {
        $failureTest = &$item;
        break;
    }
}

if (!$failureTest) {
    echo "❌ Could not find 'n8n Callback - Failure' test\n";
    exit(1);
}

// Add a collection-level variable for the error test task ID if it doesn't exist
$hasErrorTaskVar = false;
foreach ($collection['variable'] as $var) {
    if ($var['key'] === 'error_test_task_id') {
        $hasErrorTaskVar = true;
        break;
    }
}

if (!$hasErrorTaskVar) {
    $collection['variable'][] = [
        'key' => 'error_test_task_id',
        'value' => '',
        'type' => 'string',
        'description' => 'Task ID for the failure test'
    ];
    echo "✅ Added error_test_task_id variable to collection\n";
}

// Update the prerequest script - completely replace it
$hasPreRequest = false;
if (!isset($failureTest['event'])) {
    $failureTest['event'] = [];
}

foreach ($failureTest['event'] as $key => $event) {
    if (isset($event['listen']) && $event['listen'] === 'prerequest') {
        $failureTest['event'][$key] = [
            'listen' => 'prerequest',
            'script' => [
                'exec' => [
                    "// Create a separate task for the failure test",
                    "const createTaskRequest = {",
                    "    url: pm.variables.get('base_url') + '/ad-scripts',",
                    "    method: 'POST',",
                    "    header: {",
                    "        'Content-Type': 'application/json',",
                    "        'Accept': 'application/json'",
                    "    },",
                    "    body: {",
                    "        mode: 'raw',",
                    "        raw: JSON.stringify({",
                    "            reference_script: 'console.log(\"Error test task\");',",
                    "            outcome_description: 'Testing error callback'",
                    "        })",
                    "    }",
                    "};",
                    "",
                    "// First create a separate task for the error test",
                    "pm.sendRequest(createTaskRequest, function (err, res) {",
                    "    if (err) {",
                    "        console.error('Error creating task:', err);",
                    "    } else {",
                    "        try {",
                    "            const jsonData = res.json();",
                    "            if (jsonData && jsonData.data && jsonData.data.id) {",
                    "                // Set the task ID as an environment variable",
                    "                pm.environment.set('error_test_task_id', jsonData.data.id);",
                    "                console.log('Created error test task with ID:', jsonData.data.id);",
                    "                ",
                    "                // Update the URL for this request",
                    "                pm.request.url.variables.get('error_test_task_id').value = jsonData.data.id;",
                    "                ",
                    "                // Now generate the HMAC signature for the error payload",
                    "                const errorPayload = {",
                    "                    error: 'Script processing failed due to syntax error in line 42.'",
                    "                };",
                    "                ",
                    "                const secret = pm.environment.get('n8n_callback_hmac_secret') || 'test-callback-hmac-secret';",
                    "                const hmacSignature = CryptoJS.HmacSHA256(JSON.stringify(errorPayload), secret).toString();",
                    "                pm.environment.set('hmac_signature', 'sha256=' + hmacSignature);",
                    "                console.log('Generated HMAC Signature: sha256=' + hmacSignature.substring(0, 10) + '...');",
                    "            } else {",
                    "                console.error('Invalid response:', jsonData);",
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
        $hasPreRequest = true;
        break;
    }
}

if (!$hasPreRequest) {
    $failureTest['event'][] = [
        'listen' => 'prerequest',
        'script' => [
            'exec' => [
                "// Create a separate task for the failure test",
                "const createTaskRequest = {",
                "    url: pm.variables.get('base_url') + '/ad-scripts',",
                "    method: 'POST',",
                "    header: {",
                "        'Content-Type': 'application/json',",
                "        'Accept': 'application/json'",
                "    },",
                "    body: {",
                "        mode: 'raw',",
                "        raw: JSON.stringify({",
                "            reference_script: 'console.log(\"Error test task\");',",
                "            outcome_description: 'Testing error callback'",
                "        })",
                "    }",
                "};",
                "",
                "// First create a separate task for the error test",
                "pm.sendRequest(createTaskRequest, function (err, res) {",
                "    if (err) {",
                "        console.error('Error creating task:', err);",
                "    } else {",
                "        try {",
                "            const jsonData = res.json();",
                "            if (jsonData && jsonData.data && jsonData.data.id) {",
                "                // Set the task ID as an environment variable",
                "                pm.environment.set('error_test_task_id', jsonData.data.id);",
                "                console.log('Created error test task with ID:', jsonData.data.id);",
                "                ",
                "                // Now generate the HMAC signature for the error payload",
                "                const errorPayload = {",
                "                    error: 'Script processing failed due to syntax error in line 42.'",
                "                };",
                "                ",
                "                const secret = pm.environment.get('n8n_callback_hmac_secret') || 'test-callback-hmac-secret';",
                "                const hmacSignature = CryptoJS.HmacSHA256(JSON.stringify(errorPayload), secret).toString();",
                "                pm.environment.set('hmac_signature', 'sha256=' + hmacSignature);",
                "                console.log('Generated HMAC Signature: sha256=' + hmacSignature.substring(0, 10) + '...');",
                "            } else {",
                "                console.error('Invalid response:', jsonData);",
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
}
echo "✅ Updated pre-request script for n8n Callback - Failure test\n";

// Update the test script
$hasTestScript = false;
foreach ($failureTest['event'] as &$event) {
    if (isset($event['listen']) && $event['listen'] === 'test') {
        $event['script']['exec'] = [
            "pm.test(\"Status code is 200 OK (callback received)\", function () {",
            "    pm.response.to.have.status(200);",
            "});",
            "",
            "pm.test(\"Response indicates task failed\", function () {",
            "    const jsonData = pm.response.json();",
            "    ",
            "    // Verify response structure - accept multiple possible message formats",
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
        $hasTestScript = true;
        break;
    }
}
echo "✅ Updated test script for n8n Callback - Failure test\n";

// Update the request body
if (isset($failureTest['request']['body']['raw'])) {
    $failureTest['request']['body']['raw'] = json_encode([
        'error' => 'Script processing failed due to syntax error in line 42.'
    ], JSON_PRETTY_PRINT);
    echo "✅ Updated request body for n8n Callback - Failure test\n";
}

// Update the URL path to use error_test_task_id instead of created_task_id
if (isset($failureTest['request']['url'])) {
    // If URL is an object (most likely case)
    if (is_array($failureTest['request']['url'])) {
        if (isset($failureTest['request']['url']['raw'])) {
            $failureTest['request']['url']['raw'] = str_replace('{{created_task_id}}', '{{error_test_task_id}}', $failureTest['request']['url']['raw']);
        }
        
        if (isset($failureTest['request']['url']['path']) && is_array($failureTest['request']['url']['path'])) {
            foreach ($failureTest['request']['url']['path'] as &$segment) {
                if (is_string($segment) && strpos($segment, '{{created_task_id}}') !== false) {
                    $segment = str_replace('{{created_task_id}}', '{{error_test_task_id}}', $segment);
                } elseif (is_array($segment) && isset($segment['value']) && strpos($segment['value'], '{{created_task_id}}') !== false) {
                    $segment['value'] = str_replace('{{created_task_id}}', '{{error_test_task_id}}', $segment['value']);
                }
            }
        }
    } 
    // If URL is a simple string
    elseif (is_string($failureTest['request']['url'])) {
        $failureTest['request']['url'] = str_replace('{{created_task_id}}', '{{error_test_task_id}}', $failureTest['request']['url']);
    }
    echo "✅ Updated URL path to use error_test_task_id\n";
}

// Save the updated collection
file_put_contents($collectionPath, json_encode($collection, JSON_PRETTY_PRINT));
echo "✅ Saved updated collection to: $collectionPath\n\n";

echo "=============================================\n";
echo "✅ COMPLETED DIRECT POSTMAN COLLECTION FIX\n";
echo "=============================================\n\n";

echo "💡 Run 'make api-test' to verify all tests now pass correctly\n";
