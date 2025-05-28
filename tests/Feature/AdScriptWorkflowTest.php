<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\TaskStatus;
use App\Jobs\TriggerN8nWorkflow;
use App\Models\AdScriptTask;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Mockery;
use Tests\TestCase;

/**
 * End-to-end feature tests for the complete ad script processing workflow.
 *
 * These tests cover the entire flow from submission through n8n processing
 * to result callbacks, including error scenarios and edge cases.
 */
class AdScriptWorkflowTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Set up test configuration
        config([
            'services.n8n.callback_hmac_secret' => 'test-webhook-secret',
            'services.n8n.webhook_url' => 'https://test-n8n.example.com/webhook/test',
            'services.n8n.timeout' => 30,
            'services.n8n.retry_attempts' => 3,
        ]);

        // Spy on logs to verify proper logging
        Log::spy();
    }

    /**
     * Helper method to create a webhook signature for testing
     */
    private function createWebhookSignature(array $data): string
    {
        $payload = json_encode($data);
        $secret = config('services.n8n.callback_hmac_secret');

        if (! is_string($secret) || $payload === false) {
            throw new \RuntimeException('Invalid webhook configuration for testing');
        }

        return 'sha256=' . hash_hmac('sha256', $payload, $secret);
    }

    /**
     * Helper method to make a POST request with proper webhook signature
     */
    private function postJsonWithSignature(string $uri, array $data = []): \Illuminate\Testing\TestResponse
    {
        return $this->postJson($uri, $data, [
            'X-N8N-Signature' => $this->createWebhookSignature($data),
        ]);
    }

    public function test_complete_successful_workflow_from_submission_to_completion(): void
    {
        Queue::fake();

        // Step 1: Submit ad script task
        $submissionPayload = [
            'reference_script' => 'Original ad script that needs improvement for better engagement.',
            'outcome_description' => 'Make it more persuasive and include a stronger call-to-action.',
        ];

        $submissionResponse = $this->postJson('/api/ad-scripts', $submissionPayload);

        $submissionResponse->assertStatus(202)
            ->assertJsonStructure([
                'message',
                'data' => ['id', 'status', 'created_at'],
            ])
            ->assertJson([
                'message' => 'Ad script task created and queued for processing',
                'data' => ['status' => 'pending'],
            ]);

        $taskId = $submissionResponse->json('data.id');
        $this->assertIsString($taskId);

        // Verify task was created in database
        $this->assertDatabaseHas('ad_script_tasks', [
            'id' => $taskId,
            'reference_script' => $submissionPayload['reference_script'],
            'outcome_description' => $submissionPayload['outcome_description'],
            'status' => TaskStatus::PENDING->value,
        ]);

        // Verify job was dispatched
        Queue::assertPushed(TriggerN8nWorkflow::class, function ($job) use ($taskId) {
            return $job->task->id === $taskId;
        });

        // Step 2: Simulate job execution (would normally trigger n8n)
        $task = AdScriptTask::find($taskId);
        $this->assertNotNull($task);

        // Update task to processing status (simulating job execution)
        $task->update(['status' => TaskStatus::PROCESSING]);

        // Step 3: Simulate n8n callback with successful result
        $resultPayload = [
            'new_script' => 'Improved ad script with compelling call-to-action: "Transform your business today - click now!"',
            'analysis' => [
                'improvements' => 'Added emotional triggers, strengthened call-to-action, improved readability',
                'tone' => 'persuasive',
                'engagement_score' => '8.5',
                'recommendations' => 'Consider A/B testing different CTAs, test with target audience',
            ],
        ];

        $resultResponse = $this->postJsonWithSignature("/api/ad-scripts/{$taskId}/result", $resultPayload);

        $resultResponse->assertStatus(200)
            ->assertJsonStructure([
                'message',
                'data' => [
                    'id',
                    'status',
                    'updated_at',
                    'was_updated',
                    'new_script',
                    'analysis',
                ],
            ])
            ->assertJson([
                'message' => 'Result processed successfully',
                'data' => [
                    'id' => $taskId,
                    'status' => 'completed',
                    'was_updated' => true,
                    'new_script' => $resultPayload['new_script'],
                    'analysis' => $resultPayload['analysis'],
                ],
            ]);

        // Verify final task state
        $task->refresh();
        $this->assertEquals(TaskStatus::COMPLETED, $task->status);
        $this->assertEquals($resultPayload['new_script'], $task->new_script);
        $this->assertEquals($resultPayload['analysis'], $task->analysis);
        $this->assertNull($task->error_details);

        // Verify proper logging occurred
        Log::shouldHaveReceived('info')
            ->with('Processing ad script result', Mockery::type('array'))
            ->once();

        Log::shouldHaveReceived('info')
            ->with('Ad script result processing completed', Mockery::type('array'))
            ->once();
    }

    public function test_complete_workflow_with_processing_failure(): void
    {
        Queue::fake();

        // Step 1: Submit ad script task
        $submissionPayload = [
            'reference_script' => 'Test script for failure scenario.',
            'outcome_description' => 'This will fail during processing.',
        ];

        $submissionResponse = $this->postJson('/api/ad-scripts', $submissionPayload);
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
    }

    public function test_workflow_with_duplicate_submissions(): void
    {
        Queue::fake();

        $submissionPayload = [
            'reference_script' => 'Script for duplicate test.',
            'outcome_description' => 'Testing duplicate handling.',
        ];

        // Submit first task
        $response1 = $this->postJson('/api/ad-scripts', $submissionPayload);
        $response1->assertStatus(202);
        $taskId1 = $response1->json('data.id');

        // Submit identical task (should create new task, not duplicate)
        $response2 = $this->postJson('/api/ad-scripts', $submissionPayload);
        $response2->assertStatus(202);
        $taskId2 = $response2->json('data.id');

        // Verify different tasks were created
        $this->assertNotEquals($taskId1, $taskId2);

        // Verify both jobs were dispatched
        Queue::assertPushed(TriggerN8nWorkflow::class, 2);

        // Verify both tasks exist in database
        $this->assertDatabaseHas('ad_script_tasks', ['id' => $taskId1]);
        $this->assertDatabaseHas('ad_script_tasks', ['id' => $taskId2]);
    }

    public function test_workflow_with_invalid_webhook_signature(): void
    {
        // Create a task
        $task = AdScriptTask::factory()->create(['status' => TaskStatus::PROCESSING]);

        $resultPayload = [
            'new_script' => 'Some script',
            'analysis' => ['test' => 'data'],
        ];

        // Make request without signature
        $response = $this->postJson("/api/ad-scripts/{$task->id}/result", $resultPayload);
        $response->assertStatus(401);

        // Make request with invalid signature
        $response = $this->postJson("/api/ad-scripts/{$task->id}/result", $resultPayload, [
            'X-N8N-Signature' => 'sha256=invalid_signature',
        ]);
        $response->assertStatus(401);

        // Verify task wasn't modified
        $task->refresh();
        $this->assertEquals(TaskStatus::PROCESSING, $task->status);
    }

    public function test_workflow_handles_malformed_json_in_callback(): void
    {
        $task = AdScriptTask::factory()->create(['status' => TaskStatus::PROCESSING]);

        // Create signature for malformed JSON
        $malformedJson = '{"new_script": "test", "analysis":}'; // Invalid JSON
        $signature = 'sha256=' . hash_hmac('sha256', $malformedJson, config('services.n8n.callback_hmac_secret'));

        $response = $this->call(
            'POST',
            "/api/ad-scripts/{$task->id}/result",
            [],
            [],
            [],
            [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_X_N8N_SIGNATURE' => $signature,
            ],
            $malformedJson
        );

        $response->assertStatus(302); // Laravel redirects on malformed JSON
    }

    public function test_workflow_with_extremely_large_payloads(): void
    {
        Queue::fake();

        // Test submission with large but valid payload
        $largeScript = str_repeat('This is a large ad script. ', 300); // ~8KB (within 10KB limit)
        $largeDescription = str_repeat('Large description. ', 50); // ~1KB

        $submissionPayload = [
            'reference_script' => $largeScript,
            'outcome_description' => $largeDescription,
        ];

        $submissionResponse = $this->postJson('/api/ad-scripts', $submissionPayload);
        $submissionResponse->assertStatus(202);
        $taskId = $submissionResponse->json('data.id');

        // Update task to processing
        $task = AdScriptTask::find($taskId);
        $task->update(['status' => TaskStatus::PROCESSING]);

        // Test result callback with large payload
        $largeNewScript = str_repeat('Improved ad script content. ', 1000); // ~28KB (within 50KB limit)
        $largeAnalysis = [
            'improvements' => str_repeat('Improvement point. ', 100),
            'detailed_analysis' => str_repeat('Analysis detail. ', 500),
            'recommendations' => str_repeat('Recommendation. ', 50),
        ];

        $resultPayload = [
            'new_script' => $largeNewScript,
            'analysis' => $largeAnalysis,
        ];

        $resultResponse = $this->postJsonWithSignature("/api/ad-scripts/{$taskId}/result", $resultPayload);
        $resultResponse->assertStatus(200);

        // Verify data was stored correctly
        $task->refresh();
        $this->assertEquals(TaskStatus::COMPLETED, $task->status);
        $this->assertStringContainsString('Improved ad script content.', $task->new_script);
        $this->assertGreaterThan(25000, strlen($task->new_script)); // Should be large

        // Verify analysis structure and content without exact string comparison
        $this->assertIsArray($task->analysis);
        $this->assertArrayHasKey('improvements', $task->analysis);
        $this->assertArrayHasKey('detailed_analysis', $task->analysis);
        $this->assertArrayHasKey('recommendations', $task->analysis);
        $this->assertStringContainsString('Improvement point.', $task->analysis['improvements']);
        $this->assertStringContainsString('Analysis detail.', $task->analysis['detailed_analysis']);
        $this->assertStringContainsString('Recommendation.', $task->analysis['recommendations']);
    }

    public function test_workflow_with_concurrent_result_callbacks(): void
    {
        $task = AdScriptTask::factory()->create(['status' => TaskStatus::PROCESSING]);

        $resultPayload = [
            'new_script' => 'Concurrent test script',
            'analysis' => ['concurrent' => 'test'],
        ];

        // Simulate concurrent callbacks (in real scenario, these would be truly concurrent)
        $response1 = $this->postJsonWithSignature("/api/ad-scripts/{$task->id}/result", $resultPayload);
        $response2 = $this->postJsonWithSignature("/api/ad-scripts/{$task->id}/result", $resultPayload);

        // Both should succeed due to idempotency
        $response1->assertStatus(200);
        $response2->assertStatus(200);

        // Both should indicate successful processing
        $response1->assertJson(['data' => ['was_updated' => true]]);
        $response2->assertJson(['data' => ['was_updated' => true]]);

        // Verify final state is correct
        $task->refresh();
        $this->assertEquals(TaskStatus::COMPLETED, $task->status);
        $this->assertEquals($resultPayload['new_script'], $task->new_script);
        $this->assertEquals($resultPayload['analysis'], $task->analysis);
    }

    public function test_workflow_with_unicode_and_special_characters(): void
    {
        Queue::fake();

        // Test with Unicode characters and special symbols
        $submissionPayload = [
            'reference_script' => '🚀 Boost your sales with émojis! Special chars: @#$%^&*()_+ "quotes" \'apostrophes\' 中文字符',
            'outcome_description' => 'Improve engagement using émojis and international characters: café, naïve, résumé',
        ];

        $submissionResponse = $this->postJson('/api/ad-scripts', $submissionPayload);
        $submissionResponse->assertStatus(202);
        $taskId = $submissionResponse->json('data.id');

        // Update to processing
        $task = AdScriptTask::find($taskId);
        $task->update(['status' => TaskStatus::PROCESSING]);

        // Test result with Unicode
        $resultPayload = [
            'new_script' => '🎯 Transformez votre business! 中文广告文案 with special chars: <>&"\'',
            'analysis' => [
                'improvements' => 'Added émojis 🚀, International appeal 🌍',
                'tone' => 'enthusiastic with émojis',
                'unicode_test' => '测试中文字符',
            ],
        ];

        $resultResponse = $this->postJsonWithSignature("/api/ad-scripts/{$taskId}/result", $resultPayload);
        $resultResponse->assertStatus(200);

        // Verify Unicode data was preserved
        $task->refresh();
        $this->assertEquals($resultPayload['new_script'], $task->new_script);
        $this->assertEquals($resultPayload['analysis'], $task->analysis);
    }

    public function test_workflow_error_recovery_scenarios(): void
    {
        $task = AdScriptTask::factory()->create(['status' => TaskStatus::PROCESSING]);

        // First callback fails with error
        $errorPayload = ['error' => 'Temporary processing failure'];
        $errorResponse = $this->postJsonWithSignature("/api/ad-scripts/{$task->id}/result", $errorPayload);
        $errorResponse->assertStatus(200);

        $task->refresh();
        $this->assertEquals(TaskStatus::FAILED, $task->status);

        // Attempt to send success result to failed task (should be rejected)
        $successPayload = [
            'new_script' => 'Recovery script',
            'analysis' => ['recovered' => 'true'],
        ];

        $recoveryResponse = $this->postJsonWithSignature("/api/ad-scripts/{$task->id}/result", $successPayload);
        $recoveryResponse->assertStatus(422)
            ->assertJson([
                'message' => 'Conflict with final state.',
                'data' => ['was_updated' => false],
            ]);

        // Verify task remains failed
        $task->refresh();
        $this->assertEquals(TaskStatus::FAILED, $task->status);
        $this->assertEquals($errorPayload['error'], $task->error_details);
    }

    public function test_workflow_with_minimal_valid_payloads(): void
    {
        Queue::fake();

        // Test with minimal valid submission
        $minimalSubmission = [
            'reference_script' => 'Minimal ad',
            'outcome_description' => 'Brief',
        ];

        $submissionResponse = $this->postJson('/api/ad-scripts', $minimalSubmission);
        $submissionResponse->assertStatus(202);
        $taskId = $submissionResponse->json('data.id');

        // Update to processing
        $task = AdScriptTask::find($taskId);
        $task->update(['status' => TaskStatus::PROCESSING]);

        // Test with minimal valid result
        $minimalResult = ['new_script' => 'Improved minimal ad', 'analysis' => []];

        $resultResponse = $this->postJsonWithSignature("/api/ad-scripts/{$taskId}/result", $minimalResult);
        $resultResponse->assertStatus(200);

        // Verify minimal data was processed correctly
        $task->refresh();
        $this->assertEquals(TaskStatus::COMPLETED, $task->status);
        $this->assertEquals($minimalResult['new_script'], $task->new_script);
        $this->assertEquals($minimalResult['analysis'], $task->analysis);
    }

    public function test_workflow_logging_and_monitoring(): void
    {
        Queue::fake();

        // Submit task
        $submissionPayload = [
            'reference_script' => 'Script for logging test',
            'outcome_description' => 'Test comprehensive logging',
        ];

        $this->postJson('/api/ad-scripts', $submissionPayload);

        // Process result
        $task = AdScriptTask::factory()->create(['status' => TaskStatus::PROCESSING]);
        $resultPayload = [
            'new_script' => 'Logged script',
            'analysis' => ['logged' => 'true'],
        ];

        $this->postJsonWithSignature("/api/ad-scripts/{$task->id}/result", $resultPayload);

        // Verify comprehensive logging occurred
        Log::shouldHaveReceived('info')
            ->with('Processing ad script result', Mockery::on(function ($context) use ($task) {
                return $context['task_id'] === $task->id &&
                       isset($context['task_status']) &&
                       isset($context['has_new_script']) &&
                       isset($context['has_error']) &&
                       isset($context['request_timestamp']);
            }))
            ->once();

        Log::shouldHaveReceived('info')
            ->with('Ad script result processing completed', Mockery::on(function ($context) use ($task) {
                return $context['task_id'] === $task->id &&
                       isset($context['success']) &&
                       isset($context['was_updated']) &&
                       isset($context['final_status']) &&
                       isset($context['message']);
            }))
            ->once();
    }

    public function test_workflow_with_boundary_value_testing(): void
    {
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
            ]);

            if ($test['should_pass']) {
                $response->assertStatus(202, "Boundary test {$index} should pass");
            } else {
                $response->assertStatus(422, "Boundary test {$index} should fail");
            }
        }
    }

    public function test_workflow_performance_with_multiple_concurrent_submissions(): void
    {
        Queue::fake();

        $startTime = microtime(true);
        $responses = [];

        // Submit multiple tasks concurrently (simulated)
        for ($i = 0; $i < 10; $i++) {
            $responses[] = $this->postJson('/api/ad-scripts', [
                'reference_script' => "Performance test script {$i}",
                'outcome_description' => "Performance test description {$i}",
            ]);
        }

        $endTime = microtime(true);
        $totalTime = $endTime - $startTime;

        // Verify all submissions succeeded
        foreach ($responses as $index => $response) {
            $response->assertStatus(202, "Submission {$index} should succeed");
        }

        // Verify reasonable performance (should complete within 5 seconds)
        $this->assertLessThan(5.0, $totalTime, 'Multiple submissions should complete within 5 seconds');

        // Verify all jobs were dispatched
        Queue::assertPushed(TriggerN8nWorkflow::class, 10);

        // Verify all tasks were created
        $this->assertEquals(10, AdScriptTask::count());
    }
}
