<?php

/**
 * Comprehensive Direct API Test Script
 * 
 * This script conducts a series of API tests directly within the Laravel application
 * without requiring external services like n8n to be functioning. It follows the
 * task-driven development workflow principle by providing reliable test coverage
 * even when the network or external services are not available.
 */

// Load Laravel environment
require_once '/var/www/vendor/autoload.php';
$app = require_once '/var/www/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Http\Kernel::class);

// Function to run a test case and report results
function runTest($name, $request, $expectedStatus, $additionalChecks = null) {
    global $kernel;
    
    echo "\n🧪 Running test: $name\n";
    echo "--------------------------------------------\n";
    
    // Add Accept header for JSON responses
    $headers = $request->headers->all();
    $request->headers->set('Accept', 'application/json');
    
    // Execute the request
    $response = $kernel->handle($request);
    $statusCode = $response->getStatusCode();
    $content = $response->getContent();
    $data = json_decode($content, true);
    
    // Display results
    echo "Status Code: $statusCode (Expected: $expectedStatus)\n";
    echo "Response Body: $content\n";
    
    // Basic status code check
    $passed = $statusCode === $expectedStatus;
    
    // Run additional checks if provided
    if ($passed && $additionalChecks !== null) {
        $checkResult = $additionalChecks($data, $response);
        $passed = $passed && $checkResult;
    }
    
    // Success indicator
    if ($passed) {
        echo "\n✅ Test PASSED!\n";
        return true;
    } else {
        echo "\n❌ Test FAILED!\n";
        return false;
    }
}

// Track overall test status
$allTestsPassed = true;
$createdTaskId = null;

echo "\n=============================================\n";
echo "🚀 BEGINNING COMPREHENSIVE API TESTS\n";
echo "=============================================\n";

// TEST 1: Create Ad Script Task - Success Case
$request1 = Illuminate\Http\Request::create(
    '/api/ad-scripts',
    'POST',
    [],
    [],
    [],
    ['CONTENT_TYPE' => 'application/json'],
    json_encode([
        'reference_script' => 'console.log("Hello, world!");',
        'outcome_description' => 'Improve the logging message with more details.'
    ])
);

$test1Result = runTest('Create Ad Script Task (Success)', $request1, 202, function($data) use (&$createdTaskId) {
    // Extract data from the nested structure if it exists
    $taskData = isset($data['data']) ? $data['data'] : $data;
    
    if (!isset($taskData['id']) || !isset($taskData['status'])) {
        echo "  - Failed: Response missing expected fields\n";
        return false;
    }
    
    // Accept either 'pending' or 'processing' as valid status values
    if ($taskData['status'] !== 'pending' && $taskData['status'] !== 'processing') {
        echo "  - Failed: Status must be 'pending' or 'processing', got '{$taskData['status']}'\n";
        return false;
    }
    
    $createdTaskId = $taskData['id'];
    echo "  - Created task ID: $createdTaskId\n";
    return true;
});
$allTestsPassed = $allTestsPassed && $test1Result;

// TEST 2: Create Ad Script Task - Validation Error (Missing Fields)
$request2 = Illuminate\Http\Request::create(
    '/api/ad-scripts',
    'POST',
    [],
    [],
    [],
    ['CONTENT_TYPE' => 'application/json'],
    json_encode([])
);

$test2Result = runTest('Create Ad Script Task (Validation Error - Missing Fields)', $request2, 422, function($data) {
    if (!isset($data['errors']) || 
        !isset($data['errors']['reference_script']) || 
        !isset($data['errors']['outcome_description'])) {
        echo "  - Failed: Response missing expected validation errors\n";
        return false;
    }
    return true;
});
$allTestsPassed = $allTestsPassed && $test2Result;

// TEST 3: Create Ad Script Task - Validation Error (Min Length)
$request3 = Illuminate\Http\Request::create(
    '/api/ad-scripts',
    'POST',
    [],
    [],
    [],
    ['CONTENT_TYPE' => 'application/json'],
    json_encode([
        'reference_script' => 'short',
        'outcome_description' => 'Shrt' // 4 chars, below min:5
    ])
);

$test3Result = runTest('Create Ad Script Task (Validation Error - Min Length)', $request3, 422, function($data) {
    if (!isset($data['errors']) || 
        !isset($data['errors']['reference_script']) || 
        !isset($data['errors']['outcome_description'])) {
        echo "  - Failed: Response missing expected validation errors\n";
        return false;
    }
    
    $refScriptError = false;
    $outcomeError = false;
    
    // Check for min length validation messages
    foreach ($data['errors']['reference_script'] as $error) {
        if (strpos($error, 'at least 10 characters') !== false) {
            $refScriptError = true;
        }
    }
    
    foreach ($data['errors']['outcome_description'] as $error) {
        if (strpos($error, 'at least 5 characters') !== false) {
            $outcomeError = true;
        }
    }
    
    if (!$refScriptError || !$outcomeError) {
        echo "  - Failed: Response missing expected min length validation errors\n";
        return false;
    }
    
    return true;
});
$allTestsPassed = $allTestsPassed && $test3Result;

// Only run callback test if we have a task ID from test 1
if ($createdTaskId) {
    // TEST 4: n8n Callback - Success (simulated)
    $request4 = Illuminate\Http\Request::create(
        "/api/ad-scripts/$createdTaskId/result",
        'POST',
        [],
        [],
        [],
        [
            'CONTENT_TYPE' => 'application/json',
            'X-N8N-Signature' => 'sha256=dummy-signature-will-be-bypassed-in-dev-mode'
        ],
        json_encode([
            'new_script' => 'console.log("Hello, wonderful world! Here are some additional details.");',
            'analysis' => [
                'improvements' => 'Added more descriptive text to the log message',
                'complexity' => 'low'
            ]
        ])
    );
    
    $test4Result = runTest('n8n Callback - Success', $request4, 200, function($data) {
        // Extract data from the nested structure if it exists
        $resultData = isset($data['data']) ? $data['data'] : $data;
        
        if (!isset($resultData['status'])) {
            echo "  - Failed: Response missing status field\n";
            return false;
        }
        
        // Check for 'completed' status which is what our API actually returns
        if ($resultData['status'] !== 'completed') {
            echo "  - Failed: Expected status 'completed', got '{$resultData['status']}'\n";
            return false;
        }
        
        echo "  - Task successfully completed with status: {$resultData['status']}\n";
        return true;
    });
    $allTestsPassed = $allTestsPassed && $test4Result;
    
    // TEST 5: n8n Callback - Validation Error
    $request5 = Illuminate\Http\Request::create(
        "/api/ad-scripts/$createdTaskId/result",
        'POST',
        [],
        [],
        [],
        [
            'CONTENT_TYPE' => 'application/json',
            'X-N8N-Signature' => 'sha256=dummy-signature-will-be-bypassed-in-dev-mode'
        ],
        json_encode([
            'analysis' => ['invalid' => 'payload without new_script or error']
        ])
    );
    
    $test5Result = runTest('n8n Callback - Validation Error', $request5, 422, function($data) {
        if (!isset($data['errors']) || !isset($data['errors']['payload'])) {
            echo "  - Failed: Response missing expected payload validation error\n";
            return false;
        }
        return true;
    });
    $allTestsPassed = $allTestsPassed && $test5Result;
}

// Final results
echo "\n=============================================\n";
if ($allTestsPassed) {
    echo "✅ ALL TESTS PASSED! The API is functioning correctly.\n";
} else {
    echo "❌ SOME TESTS FAILED! Please review the output above.\n";
}
echo "=============================================\n";
