<?php

/**
 * Validation Test Script
 * 
 * This script tests the min length validation rules for the API endpoint
 */

// Load Laravel environment
require_once '/var/www/vendor/autoload.php';
$app = require_once '/var/www/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Http\Kernel::class);

// Simulate a request with short values
$request = Illuminate\Http\Request::create(
    '/api/ad-scripts',
    'POST',
    [],
    [],
    [],
    [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_ACCEPT' => 'application/json' // Add Accept header
    ],
    json_encode([
        'reference_script' => 'short',
        'outcome_description' => 'Shrt' // 4 chars, less than min:5
    ])
);

// Execute the request
$response = $kernel->handle($request);
$statusCode = $response->getStatusCode();
$content = $response->getContent();

// Display results
echo "Status Code: $statusCode\n";
echo "Response Body: $content\n";

// Parse and display the validation errors
$data = json_decode($content, true);
if (isset($data['errors'])) {
    echo "\nValidation Errors:\n";
    foreach ($data['errors'] as $field => $errors) {
        echo "$field: " . implode(', ', $errors) . "\n";
    }
}
