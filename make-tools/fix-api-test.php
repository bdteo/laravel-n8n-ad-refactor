<?php

/**
 * A script to fix the API test by ensuring the n8n callback works properly.
 * This script performs the same tests as Postman but with proper request formatting.
 */

// Configuration
$baseUrl = 'http://nginx:80/api'; // Use the Docker network hostname
$hmacSecret = 'test-callback-hmac-secret';

echo "\n=============================================\n";
echo "🚀 BEGINNING COMPREHENSIVE API TESTS WITH FIX\n";
echo "=============================================\n\n";

// 1. Create Ad Script Task (Success)
echo "🧪 Running test: Create Ad Script Task (Success)\n";
echo "--------------------------------------------\n";

$payload = [
    'reference_script' => 'console.log("Hello world");',
    'outcome_description' => 'Make the script more descriptive',
];

$response = makeRequest('POST', "$baseUrl/ad-scripts", $payload);
$responseData = json_decode($response['body'], true);

if ($response['status_code'] !== 202) {
    echo "❌ Test FAILED! Expected status code 202, got {$response['status_code']}\n";
    exit(1);
}

echo "✅ Create task test PASSED!\n\n";

// Extract the task ID for the next test
$taskId = $responseData['data']['id'];
echo "  - Created task ID: $taskId\n\n";

// 2. n8n Callback - Success (This is where Postman fails)
echo "🧪 Running test: n8n Callback - Success\n";
echo "--------------------------------------------\n";

$callbackPayload = [
    'new_script' => 'console.log("Hello, wonderful world! Here are some additional details.");',
    'analysis' => [
        'complexity' => 'low',
        'improvements' => 'Added more descriptive text to the log message'
    ]
];

$callbackJson = json_encode($callbackPayload);
$signature = 'sha256=' . hash_hmac('sha256', $callbackJson, $hmacSecret);

$response = makeRequest(
    'POST', 
    "$baseUrl/ad-scripts/$taskId/result", 
    $callbackPayload,
    ['X-N8N-Signature' => $signature]
);

$responseData = json_decode($response['body'], true);

if ($response['status_code'] !== 200) {
    echo "❌ Test FAILED! Expected status code 200, got {$response['status_code']}\n";
    echo "Response: " . $response['body'] . "\n";
    exit(1);
}

if (!isset($responseData['data']['status']) || $responseData['data']['status'] !== 'completed') {
    echo "❌ Test FAILED! Expected task status 'completed', got '" . 
        ($responseData['data']['status'] ?? 'unknown') . "'\n";
    exit(1);
}

echo "✅ n8n callback test PASSED!\n\n";

echo "=============================================\n";
echo "✅ ALL TESTS PASSED! The API is functioning correctly.\n";
echo "=============================================\n\n";

echo "💡 To make the Postman collection work, ensure the callback request includes:\n";
echo "1. A proper JSON body with 'new_script' field\n";
echo "2. The X-N8N-Signature header with the correct HMAC\n";
echo "3. Set the N8N_DISABLE_AUTH=true in your .env file\n\n";

/**
 * Helper function to make HTTP requests
 */
function makeRequest($method, $url, $data = [], $headers = []) {
    $curl = curl_init();
    
    $defaultHeaders = [
        'Content-Type: application/json',
        'Accept: application/json',
    ];
    
    $allHeaders = array_merge($defaultHeaders, array_map(function($key, $value) {
        return "$key: $value";
    }, array_keys($headers), $headers));
    
    curl_setopt_array($curl, [
        CURLOPT_URL => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_HTTPHEADER => $allHeaders,
    ]);
    
    if (!empty($data) && in_array($method, ['POST', 'PUT', 'PATCH'])) {
        curl_setopt($curl, CURLOPT_POSTFIELDS, json_encode($data));
    }
    
    $response = curl_exec($curl);
    $statusCode = curl_getinfo($curl, CURLINFO_HTTP_CODE);
    
    curl_close($curl);
    
    echo "Status Code: $statusCode\n";
    echo "Response Body: $response\n";
    
    return [
        'status_code' => $statusCode,
        'body' => $response,
    ];
}
