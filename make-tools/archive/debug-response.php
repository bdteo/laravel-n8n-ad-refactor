<?php
/**
 * Debug script to investigate the response structure for n8n callbacks
 */

// Configuration
$baseUrl = 'http://localhost:8000/api';
$taskId = null; // We'll get this from the first API call

echo "\n=============================================\n";
echo "🔍 DEBUGGING API RESPONSE STRUCTURE\n";
echo "=============================================\n\n";

// 1. Create a task
echo "Step 1: Creating a task\n";
$createPayload = [
    'reference_script' => 'console.log("Debug test");',
    'outcome_description' => 'Debugging response structure',
];

$createResponse = makeRequest('POST', "$baseUrl/ad-scripts", $createPayload);
$createData = json_decode($createResponse['body'], true);

if (!isset($createData['data']['id'])) {
    echo "❌ Failed to create task\n";
    exit(1);
}

$taskId = $createData['data']['id'];
echo "✅ Created task with ID: $taskId\n\n";

// 2. Send success callback
echo "Step 2: Testing success callback\n";
$successPayload = [
    'new_script' => 'console.log("Hello, improved world!");',
    'analysis' => [
        'complexity' => 'low',
        'improvements' => 'Enhanced greeting'
    ]
];

$successResponse = makeRequest('POST', "$baseUrl/ad-scripts/$taskId/result", $successPayload);
$successData = json_decode($successResponse['body'], true);

echo "Success callback response structure:\n";
print_r($successData);
echo "\n";

// 3. Create another task for error testing
echo "Step 3: Creating another task for error testing\n";
$createResponse = makeRequest('POST', "$baseUrl/ad-scripts", $createPayload);
$createData = json_decode($createResponse['body'], true);

if (!isset($createData['data']['id'])) {
    echo "❌ Failed to create second task\n";
    exit(1);
}

$taskId = $createData['data']['id'];
echo "✅ Created task with ID: $taskId\n\n";

// 4. Send error callback
echo "Step 4: Testing error callback\n";
$errorPayload = [
    'error' => 'Script processing failed due to syntax error in line 42.'
];

$errorResponse = makeRequest('POST', "$baseUrl/ad-scripts/$taskId/result", $errorPayload);
$errorData = json_decode($errorResponse['body'], true);

echo "Error callback response structure:\n";
print_r($errorData);
echo "\n";

// Helper function to make HTTP requests
function makeRequest($method, $url, $data = []) {
    $curl = curl_init();
    
    $options = [
        CURLOPT_URL => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/json',
            'Accept: application/json',
        ],
    ];
    
    if (!empty($data) && in_array($method, ['POST', 'PUT', 'PATCH'])) {
        $options[CURLOPT_POSTFIELDS] = json_encode($data);
    }
    
    curl_setopt_array($curl, $options);
    
    $response = curl_exec($curl);
    $statusCode = curl_getinfo($curl, CURLINFO_HTTP_CODE);
    
    curl_close($curl);
    
    echo "Status Code: $statusCode\n";
    
    return [
        'status_code' => $statusCode,
        'body' => $response,
    ];
}
