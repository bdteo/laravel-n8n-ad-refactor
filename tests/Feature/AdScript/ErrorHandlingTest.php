<?php

declare(strict_types=1);

namespace Tests\Feature\AdScript;

use App\Enums\TaskStatus;
use App\Models\AdScriptTask;
use Illuminate\Support\Facades\Queue;

/**
 * Tests for error handling in ad script workflow.
 *
 * Covers error recovery, boundary conditions, malformed inputs,
 * and system failure scenarios.
 */
class ErrorHandlingTest extends BaseAdScriptWorkflowTest
{
    public function test_complete_workflow_with_processing_failure(): void
    {
        // Disable rate limiting for this test
        $this->withoutRateLimiting(function () {
            Queue::fake();

            // Step 1: Submit ad script task
            $submissionPayload = [
                'reference_script' => 'Original ad script that needs improvement.',
                'outcome_description' => 'Make it better.',
            ];

            $submissionResponse = $this->postJson('/api/ad-scripts', $submissionPayload, $this->getNoRateLimitHeaders());

            $submissionResponse->assertStatus(202);
            $taskId = $submissionResponse->json('data.id');

            // Step 2: Update task to processing status
            $task = AdScriptTask::find($taskId);
            $task->update(['status' => TaskStatus::PROCESSING]);

            // Step 3: Simulate n8n callback with error result
            $errorPayload = [
                'error' => 'AI service temporarily unavailable. Please try again later.',
            ];

            $resultResponse = $this->postJsonWithSignature("/api/ad-scripts/{$taskId}/result", $errorPayload);

            $resultResponse->assertStatus(200)
                ->assertJsonStructure([
                    'message',
                    'data' => [
                        'id',
                        'status',
                        'updated_at',
                        'was_updated',
                        'error_details',
                    ],
                ])
                ->assertJson([
                    'message' => 'Result processed successfully',
                    'data' => [
                        'id' => $taskId,
                        'status' => 'failed',
                        'was_updated' => true,
                        'error_details' => $errorPayload['error'],
                    ],
                ]);

            // Verify final task state
            $task->refresh();
            $this->assertEquals(TaskStatus::FAILED, $task->status);
            $this->assertEquals($errorPayload['error'], $task->error_details);
            $this->assertNull($task->new_script);
            $this->assertNull($task->analysis);
        });
    }

    public function test_workflow_handles_malformed_json_in_callback(): void
    {
        $this->withoutRateLimiting(function () {
            // Create a task to use for testing
            $task = AdScriptTask::factory()->create(['status' => TaskStatus::PROCESSING]);

            // Create a valid payload first to generate signature
            $validPayload = [
                'new_script' => 'Test script',
                'analysis' => ['test' => 'test'],
            ];
            $validJson = json_encode($validPayload);
            $signature = 'sha256=' . hash_hmac('sha256', $validJson, config('services.n8n.callback_hmac_secret'));

            // Prepare malformed JSON (missing closing brace)
            $malformedJson = substr($validJson, 0, -1);

            // Send request with malformed JSON but valid signature
            $response = $this->postJsonWithSignature(
                "/api/ad-scripts/{$task->id}/result",
                [],
                [
                    'Content-Type' => 'application/json',
                    'Accept' => 'application/json',
                ],
                $malformedJson
            );

            // Debug the actual response
            $statusCode = $response->getStatusCode();
            $content = $response->getContent();

            $this->assertTrue(
                in_array($statusCode, [400, 422]),
                sprintf('Expected status code 400 or 422, got %d. Response: %s', $statusCode, $content)
            );

            // Verify task remains in processing state
            $task->refresh();
            $this->assertEquals(TaskStatus::PROCESSING, $task->status);
        });
    }

    public function test_workflow_error_recovery_scenarios(): void
    {
        // Disable rate limiting for this test
        $this->withoutRateLimiting(function () {
            // Create a task and manually set it to FAILED state
            // This is necessary because we need to ensure it's in a final state
            // before testing the recovery attempt
            $task = AdScriptTask::factory()->create([
                'status' => TaskStatus::FAILED,
                'error_details' => 'Temporary processing failure',
            ]);

            // Verify initial state
            $this->assertEquals(TaskStatus::FAILED, $task->status);
            $this->assertEquals('Temporary processing failure', $task->error_details);

            // Attempt to send success result to failed task (idempotency check)
            $successPayload = [
                'new_script' => 'Recovery script',
                'analysis' => ['recovered' => 'true'],
            ];

            $recoveryResponse = $this->postJsonWithSignature("/api/ad-scripts/{$task->id}/result", $successPayload);
            $recoveryResponse->assertStatus(422)
                ->assertJsonPath('message', 'Conflict with final state.');

            // Verify task remains failed
            $task->refresh();
            $this->assertEquals(TaskStatus::FAILED, $task->status);
            $this->assertEquals('Temporary processing failure', $task->error_details);
            $this->assertNull($task->new_script);
            $this->assertNull($task->analysis);
        });
    }

    public function test_workflow_with_boundary_value_testing(): void
    {
        // Disable rate limiting for this test
        $this->withoutRateLimiting(function () {
            Queue::fake();

            // Test with boundary values for validation
            $boundaryTests = [
                // Minimum valid lengths
                [
                    'reference_script' => str_repeat('a', 10), // Minimum length
                    'outcome_description' => str_repeat('b', 5), // Minimum length
                    'should_pass' => true,
                ],
                // Maximum valid lengths
                [
                    'reference_script' => str_repeat('a', 10000), // Maximum length
                    'outcome_description' => str_repeat('b', 1000), // Maximum length
                    'should_pass' => true,
                ],
                // Just over maximum (should fail)
                [
                    'reference_script' => str_repeat('a', 10001), // Over maximum
                    'outcome_description' => 'Valid description',
                    'should_pass' => false,
                ],
            ];

            foreach ($boundaryTests as $index => $test) {
                $response = $this->postJson('/api/ad-scripts', [
                    'reference_script' => $test['reference_script'],
                    'outcome_description' => $test['outcome_description'],
                ], $this->getNoRateLimitHeaders());

                if ($test['should_pass']) {
                    $response->assertStatus(202);
                } else {
                    $response->assertStatus(422);
                }
            }
        });
    }
}
