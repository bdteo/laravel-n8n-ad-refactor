<?php
/**
 * Comprehensive API test script with rate limit bypass
 * 
 * This script tests the entire API flow including n8n callbacks with proper handling
 * of rate limiting and error callbacks.
 */

// Configuration
$baseUrl = 'http://localhost:8000/api'; // Use the host machine's address
$hmacSecret = 'test-callback-hmac-secret';
$isDebug = true; // Set to true to see full response bodies

echo "\n=============================================\n";
echo "🚀 COMPREHENSIVE API TESTS WITH RATE LIMIT BYPASS\n";
echo "=============================================\n\n";

// Add a small delay between requests to avoid any potential rate limiting
function sleepBetweenRequests() {
    echo "⏱️  Adding delay between requests to avoid rate limiting...\n";
    sleep(1);
}

// 1. Create Ad Script Task (Success)
echo "🧪 Running test: Create Ad Script Task (Success)\n";
echo "--------------------------------------------\n";

$payload = [
    'reference_script' => 'console.log("Hello world");',
    'outcome_description' => 'Make the script more descriptive',
];

$response = makeRequest('POST', "$baseUrl/ad-scripts", $payload);

if ($response['status_code'] !== 202) {
    echo "❌ Test FAILED! Expected status code 202, got {$response['status_code']}\n";
    exit(1);
}

$responseData = json_decode($response['body'], true);
if (!$responseData || !isset($responseData['data']) || !isset($responseData['data']['id'])) {
    echo "❌ Test FAILED! Invalid response format. Could not find task ID in response.\n";
    if ($isDebug) {
        echo "Full response body: {$response['body']}\n";
    }
    exit(1);
}

echo "✅ Create task test PASSED!\n\n";

// Extract the task ID for the next test
$taskId = $responseData['data']['id'];
echo "  - Created task ID: $taskId\n\n";

sleepBetweenRequests();

// 2. n8n Callback - Success
echo "🧪 Running test: n8n Callback - Success\n";
echo "--------------------------------------------\n";

$successPayload = [
    'new_script' => 'console.log("Hello, wonderful world! Here are some additional details.");',
    'analysis' => [
        'complexity' => 'low',
        'improvements' => 'Added more descriptive text to the log message'
    ]
];

$callbackJson = json_encode($successPayload);
$signature = 'sha256=' . hash_hmac('sha256', $callbackJson, $hmacSecret);

// Construct the correct URL - making sure we're using the right format
echo "Using URL: $baseUrl/ad-scripts/$taskId/result\n";

$response = makeRequest(
    'POST', 
    "$baseUrl/ad-scripts/$taskId/result", 
    $successPayload,
    ['X-N8N-Signature' => $signature]
);

if ($response['status_code'] !== 200) {
    echo "❌ Test FAILED! Expected status code 200, got {$response['status_code']}\n";
    echo "Response: " . $response['body'] . "\n";
    exit(1);
}

$responseData = json_decode($response['body'], true);
if (!$responseData || !isset($responseData['data'])) {
    echo "❌ Test FAILED! Invalid response format or could not parse JSON response.\n";
    echo "Full response: {$response['body']}\n";
    exit(1);
}

if (!isset($responseData['data']['status']) || $responseData['data']['status'] !== 'completed') {
    echo "❌ Test FAILED! Expected task status 'completed', got '" . 
        ($responseData['data']['status'] ?? 'unknown') . "'\n";
    exit(1);
}

echo "✅ n8n success callback test PASSED!\n\n";

sleepBetweenRequests();

// 3. Create another task for error testing
echo "🧪 Running test: Create Second Task For Error Testing\n";
echo "--------------------------------------------\n";

$response = makeRequest('POST', "$baseUrl/ad-scripts", $payload);
$responseData = json_decode($response['body'], true);

if ($response['status_code'] !== 202) {
    echo "❌ Test FAILED! Expected status code 202, got {$response['status_code']}\n";
    exit(1);
}

$taskId = $responseData['data']['id'];
echo "✅ Created second task with ID: $taskId\n\n";

sleepBetweenRequests();

// 4. n8n Callback - Error
echo "🧪 Running test: n8n Callback - Error\n";
echo "--------------------------------------------\n";

$errorPayload = [
    'error' => 'Script processing failed due to syntax error in line 42.'
];

$callbackJson = json_encode($errorPayload);
$signature = 'sha256=' . hash_hmac('sha256', $callbackJson, $hmacSecret);

// Construct the correct URL for error callback
echo "Using URL: $baseUrl/ad-scripts/$taskId/result\n";

$response = makeRequest(
    'POST', 
    "$baseUrl/ad-scripts/$taskId/result", 
    $errorPayload,
    ['X-N8N-Signature' => $signature]
);

if ($response['status_code'] !== 200) {
    echo "❌ Test FAILED! Expected status code 200, got {$response['status_code']}\n";
    echo "Response: " . $response['body'] . "\n";
    exit(1);
}

$responseData = json_decode($response['body'], true);
if (!$responseData || !isset($responseData['data'])) {
    echo "❌ Test FAILED! Invalid response format or could not parse JSON response.\n";
    echo "Full response: {$response['body']}\n";
    exit(1);
}

// Check if task is marked as failed and error_details is present
if (!isset($responseData['data']['status']) || $responseData['data']['status'] !== 'failed') {
    echo "❌ Test FAILED! Expected task status 'failed', got '" . 
        ($responseData['data']['status'] ?? 'unknown') . "'\n";
    exit(1);
}

if (!isset($responseData['data']['error_details']) || empty($responseData['data']['error_details'])) {
    echo "❌ Test FAILED! Expected error_details to contain the error message\n";
    exit(1);
}

echo "✅ n8n error callback test PASSED!\n\n";

echo "=============================================\n";
echo "✅ ALL TESTS PASSED! The API is functioning correctly.\n";
echo "=============================================\n\n";

echo "📋 Test Summary:\n";
echo "1. Task creation works correctly\n";
echo "2. n8n success callback updates task properly\n";
echo "3. n8n error callback marks task as failed\n";
echo "4. Rate limiting has been bypassed for tests\n\n";

echo "💡 Postman collection should now work with the updated test scripts.\n";
echo "   Run 'make api-test-fix' to automatically fix and run the Postman tests.\n\n";

/**
 * Helper function to make HTTP requests
 */
function makeRequest($method, $url, $data = [], $headers = []) {
    global $isDebug;
    $curl = curl_init();
    
    $defaultHeaders = [
        'Content-Type: application/json',
        'Accept: application/json',
        'X-Test-Bypass-Rate-Limit: true', // Our custom header to help with debugging
    ];
    
    $allHeaders = array_merge($defaultHeaders, array_map(function($key, $value) {
        return "$key: $value";
    }, array_keys($headers), array_values($headers)));
    
    curl_setopt_array($curl, [
        CURLOPT_URL => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_HTTPHEADER => $allHeaders,
    ]);
    
    if (!empty($data) && in_array($method, ['POST', 'PUT', 'PATCH'])) {
        $jsonData = json_encode($data);
        curl_setopt($curl, CURLOPT_POSTFIELDS, $jsonData);
        
        if ($isDebug) {
            echo "Request Body: $jsonData\n";
        }
    }
    
    $response = curl_exec($curl);
    $statusCode = curl_getinfo($curl, CURLINFO_HTTP_CODE);
    $error = curl_error($curl);
    
    curl_close($curl);
    
    echo "Status Code: $statusCode\n";
    
    if ($error) {
        echo "cURL Error: $error\n";
    }
    
    // Print truncated response for better readability if not in debug mode
    if ($isDebug) {
        echo "Full Response Body: $response\n";
    } else {
        $truncatedResponse = strlen($response) > 300 
            ? substr($response, 0, 300) . "...(truncated)" 
            : $response;
        echo "Response Body: $truncatedResponse\n";
    }
    
    return [
        'status_code' => $statusCode,
        'body' => $response,
        'error' => $error,
    ];
}
