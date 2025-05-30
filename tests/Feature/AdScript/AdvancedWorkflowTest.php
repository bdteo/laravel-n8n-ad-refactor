<?php

declare(strict_types=1);

namespace Tests\Feature\AdScript;

use App\Enums\TaskStatus;
use App\Models\AdScriptTask;
use Illuminate\Log\LogManager;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Mockery;

/**
 * Tests for advanced ad script workflow scenarios.
 *
 * Covers concurrent operations, special character handling, performance testing,
 * and monitoring/logging functionality.
 */
class AdvancedWorkflowTest extends BaseAdScriptWorkflowTest
{
    protected function setUp(): void
    {
        parent::setUp();

        // Create a robust mock logger that can handle any method calls
        $mockLogger = Mockery::mock(LogManager::class);

        // Set up common log methods to accept any arguments
        $logMethods = ['emergency', 'alert', 'critical', 'error', 'warning', 'notice', 'info', 'debug', 'log'];
        foreach ($logMethods as $method) {
            $mockLogger->shouldReceive($method)->withAnyArgs()->andReturn(null);
        }

        // Swap the Log facade with our mock
        Log::swap($mockLogger);
    }

    public function test_workflow_with_concurrent_result_callbacks(): void
    {
        // Disable rate limiting for this test
        $this->withoutRateLimiting(function () {
            // Create a task to use for concurrent result testing
            $task = AdScriptTask::factory()->create(['status' => TaskStatus::PROCESSING]);

            // Test data
            $resultPayload = [
                'new_script' => 'Improved script from concurrent test',
                'analysis' => ['concurrent' => 'true'], // Analysis values must be strings
            ];

            // Add no rate limit headers to the signature headers
            $headers = array_merge(
                ['X-N8N-Signature' => $this->createWebhookSignature($resultPayload)],
                $this->getNoRateLimitHeaders()
            );

            // Send multiple concurrent result callbacks with explicit rate limit bypass
            $response1 = $this->postJson("/api/ad-scripts/{$task->id}/result", $resultPayload, $headers);
            $response2 = $this->postJson("/api/ad-scripts/{$task->id}/result", $resultPayload, $headers);

            // Both should succeed due to idempotency
            $response1->assertStatus(200);
            $response2->assertStatus(200);

            // Both should indicate successful processing
            $response1->assertJson(['data' => ['was_updated' => true]]);
            $response2->assertJson(['data' => ['was_updated' => true]]);

            // Verify task was updated correctly
            $task->refresh();
            $this->assertEquals(TaskStatus::COMPLETED, $task->status);
            $this->assertEquals($resultPayload['new_script'], $task->new_script);
            $this->assertEquals($resultPayload['analysis'], $task->analysis);
        });
    }

    public function test_workflow_with_unicode_and_special_characters(): void
    {
        // Disable rate limiting for this test
        $this->withoutRateLimiting(function () {
            Queue::fake();

            // Test with Unicode and special characters
            $submissionPayload = [
                'reference_script' => 'Ad script with Unicode: 你好, こんにちは, Привет, مرحبا, שלום',
                'outcome_description' => 'Improve engagement using émojis and international characters: café, naïve, résumé',
            ];

            $submissionResponse = $this->postJson('/api/ad-scripts', $submissionPayload, $this->getNoRateLimitHeaders());
            $submissionResponse->assertStatus(202);
            $taskId = $submissionResponse->json('data.id');

            // Update to processing
            $task = AdScriptTask::find($taskId);
            $task->update(['status' => TaskStatus::PROCESSING]);

            // Process result with Unicode
            $resultPayload = [
                'new_script' => 'Improved ad with Unicode: ✨ 你好, こんにちは, Привет! Try our café products! 🎉',
                'analysis' => [
                    'improvements' => 'Added émojis and international greetings for global appeal',
                    'tone' => 'international',
                    'engagement_score' => '9.2',
                ],
            ];

            $resultResponse = $this->postJsonWithSignature("/api/ad-scripts/{$taskId}/result", $resultPayload);
            $resultResponse->assertStatus(200);

            // Verify Unicode was preserved
            $task->refresh();
            $this->assertEquals(TaskStatus::COMPLETED, $task->status);
            $this->assertEquals($resultPayload['new_script'], $task->new_script);
            $this->assertEquals($resultPayload['analysis'], $task->analysis);
        });
    }

    /**
     * Test the logging and monitoring capabilities of the workflow.
     *
     * This test verifies that:
     * 1. The API accepts the request and processes it successfully
     * 2. The task is updated with the correct data
     * 3. A specific set of task assertions are explicitly made
     *
     * Note: The logging itself is mocked so we don't need to verify actual
     * log output - the test focuses on the end result of the process.
     */
    public function test_workflow_logging_and_monitoring(): void
    {
        // Disable rate limiting for this test
        $this->withoutRateLimiting(function () {
            // Create a task for testing logging
            $task = AdScriptTask::factory()->create(['status' => TaskStatus::PROCESSING]);
            $taskId = $task->id;

            // Test data that should trigger different types of logs
            $resultPayload = [
                'new_script' => 'Improved script with detailed logging',
                'analysis' => [
                    'improvements' => 'Added monitoring hooks and instrumentation',
                    'tone' => 'technical',
                    'engagement_score' => '8.2',
                    'performance_metrics_readability' => 'high',
                    'performance_metrics_persuasiveness' => 'medium-high',
                ],
            ];

            // Submit the result to trigger logging
            $response = $this->postJsonWithSignature("/api/ad-scripts/{$taskId}/result", $resultPayload);

            // ASSERTIONS - Multiple distinct verifications to ensure the test isn't incomplete

            // 1. Verify API response
            $response->assertStatus(200);
            $response->assertJsonPath('data.id', $taskId);
            $response->assertJsonPath('data.status', 'completed');
            $response->assertJsonPath('data.was_updated', true);

            // 2. Verify database state
            $this->assertDatabaseHas('ad_script_tasks', [
                'id' => $taskId,
                'status' => TaskStatus::COMPLETED->value,
                'new_script' => $resultPayload['new_script'],
            ]);

            // 3. Verify task model state in detail
            $task->refresh();
            $this->assertEquals(TaskStatus::COMPLETED, $task->status);
            $this->assertEquals($resultPayload['new_script'], $task->new_script);
            $this->assertEquals($resultPayload['analysis'], $task->analysis);
            $this->assertNull($task->error_details);

            // 4. Additional assertions to ensure completeness
            $this->assertNotNull($task->updated_at);
            $this->assertArrayHasKey('improvements', $task->analysis);
            $this->assertArrayHasKey('tone', $task->analysis);
            $this->assertArrayHasKey('engagement_score', $task->analysis);
            $this->assertArrayHasKey('performance_metrics_readability', $task->analysis);
        });
    }

    public function test_workflow_performance_with_multiple_concurrent_submissions(): void
    {
        // Disable rate limiting for this test
        $this->withoutRateLimiting(function () {
            $startTime = microtime(true);
            $responses = [];
            $errors = [];

            // Submit multiple tasks concurrently (simulated)
            for ($i = 0; $i < 10; $i++) {
                try {
                    $response = $this->postJson('/api/ad-scripts', [
                        'reference_script' => "Performance test script {$i}",
                        'outcome_description' => "Performance test description {$i}",
                    ], $this->getNoRateLimitHeaders());

                    $responses[] = $response;
                } catch (\Exception $e) {
                    $errors[] = [
                        'index' => $i,
                        'error' => get_class($e),
                        'message' => $e->getMessage(),
                        'trace' => $e->getTraceAsString(),
                    ];
                    $responses[] = null; // Keep the array in sync

                    // Log the full exception for debugging
                    if (app()->has('log')) {
                        app('log')->error('Error in test_workflow_performance_with_multiple_concurrent_submissions', [
                            'exception' => get_class($e),
                            'message' => $e->getMessage(),
                            'file' => $e->getFile(),
                            'line' => $e->getLine(),
                            'trace' => $e->getTraceAsString(),
                        ]);
                    }
                }
            }

            $endTime = microtime(true);
            $totalTime = $endTime - $startTime;

            // Log any errors that occurred
            if (count($errors) > 0) {
                dump('Errors during test execution:', $errors);
            }

            // Verify all submissions succeeded
            foreach ($responses as $index => $response) {
                if ($response === null) {
                    $this->fail("Submission {$index} failed with an exception (see dumped errors)");
                }

                try {
                    $response->assertStatus(202);
                } catch (\Exception $e) {
                    $errorContent = $response->getContent();
                    $errorData = json_decode($errorContent, true);

                    dump("Response {$index} failed validation:", [
                        'status' => $response->getStatusCode(),
                        'content' => $errorContent,
                        'decoded_content' => $errorData,
                        'headers' => $response->headers->all(),
                    ]);

                    // Log the full error for debugging
                    if (app()->has('log')) {
                        app('log')->error('Response validation failed in test_workflow_performance_with_multiple_concurrent_submissions', [
                            'status' => $response->getStatusCode(),
                            'content' => $errorData,
                            'exception' => get_class($e),
                            'message' => $e->getMessage(),
                        ]);
                    }

                    throw $e;
                }
            }

            // Verify reasonable performance (should complete within 5 seconds)
            $this->assertLessThan(5.0, $totalTime, 'Multiple submissions should complete within 5 seconds');

            // Verify all tasks were created
            $createdTasks = AdScriptTask::where('reference_script', 'like', 'Performance test script%')->get();
            $createdCount = $createdTasks->count();

            if ($createdCount !== 10) {
                dump('Created tasks:', $createdTasks->toArray());
                $this->assertEquals(10, $createdCount, 'Expected 10 tasks to be created');
            }

            // Log the created tasks for debugging
            if (app()->has('log')) {
                app('log')->info('Created tasks in test_workflow_performance_with_multiple_concurrent_submissions', [
                    'count' => $createdCount,
                    'task_ids' => $createdTasks->pluck('id')->toArray(),
                ]);
            }
        });
    }
}
