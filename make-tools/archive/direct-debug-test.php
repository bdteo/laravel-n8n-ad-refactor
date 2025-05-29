<?php
/**
 * Debug script to test error callback processing
 */

// Include the autoloader 
require_once '/var/www/vendor/autoload.php';

use App\Models\AdScriptTask;
use App\DTOs\N8nResultPayload;
use App\Services\AdScriptTaskService;
use App\Services\AuditLogService;
use Illuminate\Support\Facades\App;

// Simple output formatting
function printHeader($text) {
    echo "\n" . str_repeat("=", 80) . "\n";
    echo " $text \n";
    echo str_repeat("=", 80) . "\n";
}

printHeader("TESTING ERROR CALLBACK PROCESSING");

// First, get the last created task to use for testing
$task = AdScriptTask::latest()->first();

if (!$task) {
    echo "❌ No tasks found in the database. Run the direct-api-test.php script first.\n";
    exit(1);
}

echo "✅ Using task ID: " . $task->id . " with current status: " . $task->status->value . "\n";

// Create service instances
$auditLogService = App::make(AuditLogService::class);
$adScriptTaskService = new AdScriptTaskService($auditLogService);

// First, let's check the payload validation using both patterns
$successPayload = N8nResultPayload::fromArray([
    'new_script' => 'console.log("Hello, wonderful world! Here are some additional details.");',
    'analysis' => [
        'complexity' => 'low',
        'improvements' => 'Added more descriptive text to the log message'
    ]
]);

$errorPayload = N8nResultPayload::fromArray([
    'error' => 'Script processing failed due to syntax error in line 42.'
]);

// Test payload validation
echo "\n🔍 Testing payload validation:\n";
echo "  Success payload isSuccess(): " . ($successPayload->isSuccess() ? "true" : "false") . "\n";
echo "  Success payload isError(): " . ($successPayload->isError() ? "true" : "false") . "\n";
echo "  Error payload isSuccess(): " . ($errorPayload->isSuccess() ? "true" : "false") . "\n";
echo "  Error payload isError(): " . ($errorPayload->isError() ? "true" : "false") . "\n";

// Test processing an error payload
printHeader("TESTING ERROR PAYLOAD PROCESSING");

// First, ensure task is in a processable state
if ($task->isFinal()) {
    echo "⚠️ Task is already in final state. Creating a new task for testing...\n";
    $task = AdScriptTask::create([
        'reference_script' => 'console.log("Test script");',
        'outcome_description' => 'Test debugging the error callback',
    ]);
    echo "✅ Created new task with ID: " . $task->id . "\n";
}

echo "\n📊 Task status before processing: " . $task->status->value . "\n";

// Process the error payload
$result = $adScriptTaskService->processResultIdempotent($task, $errorPayload);
$task->refresh();

echo "📊 Processing result: " . ($result['success'] ? "Success" : "Failed") . "\n";
echo "📊 Task status after processing: " . $task->status->value . "\n";
echo "📊 Error details: " . ($task->error_details ?? 'None') . "\n";

// Now test the direct method to mark as failed to verify it works
printHeader("TESTING DIRECT markAsFailed METHOD");

// First, ensure task is in a processable state 
if ($task->isFinal()) {
    echo "⚠️ Task is already in final state. Creating a new task for testing...\n";
    $task = AdScriptTask::create([
        'reference_script' => 'console.log("Test script 2");',
        'outcome_description' => 'Test debugging the markAsFailed method',
    ]);
    echo "✅ Created new task with ID: " . $task->id . "\n";
}

echo "\n📊 Task status before direct markAsFailed: " . $task->status->value . "\n";

// Call markAsFailed directly
$result = $adScriptTaskService->markAsFailed($task, "Direct test error message");
$task->refresh();

echo "📊 markAsFailed result: " . ($result ? "Success" : "Failed") . "\n";
echo "📊 Task status after direct markAsFailed: " . $task->status->value . "\n";
echo "📊 Error details: " . ($task->error_details ?? 'None') . "\n";

printHeader("TEST COMPLETE");
echo "🚀 You can use these results to debug the error callback issues in the Postman tests.\n";
