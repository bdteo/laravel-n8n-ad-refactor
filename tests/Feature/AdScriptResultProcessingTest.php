<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\TaskStatus;
use App\Models\AdScriptTask;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

class AdScriptResultProcessingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Log::spy();

        // Set up webhook secret for testing
        config(['services.n8n.webhook_secret' => 'test-webhook-secret']);
    }

    /**
     * Helper method to make a POST request with proper webhook signature
     */
    private function postJsonWithSignature(string $uri, array $data = []): \Illuminate\Testing\TestResponse
    {
        $payload = json_encode($data);
        $secret = config('services.n8n.webhook_secret');

        if (! is_string($secret)) {
            throw new \RuntimeException('Webhook secret must be configured as a string for testing');
        }

        if ($payload === false) {
            throw new \RuntimeException('Failed to encode JSON payload');
        }

        $signature = 'sha256=' . hash_hmac('sha256', $payload, $secret);

        return $this->postJson($uri, $data, [
            'X-N8N-Signature' => $signature,
        ]);
    }

    public function test_it_processes_successful_result_for_processing_task(): void
    {
        $task = AdScriptTask::factory()->create([
            'status' => TaskStatus::PROCESSING,
        ]);

        $payload = [
            'new_script' => 'console.log("Improved ad script");',
            'analysis' => [
                'improvements' => 'Added better logging',
                'tone' => 'professional',
            ],
        ];

        $response = $this->postJsonWithSignature("/api/ad-scripts/{$task->id}/result", $payload);

        $response->assertStatus(200)
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
                    'id' => $task->id,
                    'status' => 'completed',
                    'was_updated' => true,
                    'new_script' => $payload['new_script'],
                    'analysis' => $payload['analysis'],
                ],
            ]);

        $task->refresh();
        $this->assertEquals(TaskStatus::COMPLETED, $task->status);
        $this->assertEquals($payload['new_script'], $task->new_script);
        $this->assertEquals($payload['analysis'], $task->analysis);
        $this->assertNull($task->error_details);
    }

    public function test_it_processes_error_result_for_processing_task(): void
    {
        $task = AdScriptTask::factory()->create([
            'status' => TaskStatus::PROCESSING,
        ]);

        $payload = [
            'error' => 'AI service temporarily unavailable',
        ];

        $response = $this->postJsonWithSignature("/api/ad-scripts/{$task->id}/result", $payload);

        $response->assertStatus(200)
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
                    'id' => $task->id,
                    'status' => 'failed',
                    'was_updated' => true,
                    'error_details' => $payload['error'],
                ],
            ]);

        $task->refresh();
        $this->assertEquals(TaskStatus::FAILED, $task->status);
        $this->assertEquals($payload['error'], $task->error_details);
        $this->assertNull($task->new_script);
        $this->assertNull($task->analysis);
    }

    public function test_it_handles_idempotent_success_result_with_same_data(): void
    {
        $newScript = 'console.log("Completed script");';
        $analysis = ['improvement' => 'Better performance'];

        $task = AdScriptTask::factory()->create([
            'status' => TaskStatus::COMPLETED,
            'new_script' => $newScript,
            'analysis' => $analysis,
        ]);

        $payload = [
            'new_script' => $newScript,
            'analysis' => $analysis,
        ];

        $response = $this->postJsonWithSignature("/api/ad-scripts/{$task->id}/result", $payload);

        $response->assertStatus(200)
            ->assertJson([
                'message' => 'Result processed successfully',
                'data' => [
                    'id' => $task->id,
                    'status' => 'completed',
                    'was_updated' => true, // Idempotent operation still returns true
                    'new_script' => $newScript,
                    'analysis' => $analysis,
                ],
            ]);

        // Verify task wasn't modified
        $task->refresh();
        $this->assertEquals(TaskStatus::COMPLETED, $task->status);
        $this->assertEquals($newScript, $task->new_script);
        $this->assertEquals($analysis, $task->analysis);
    }

    public function test_it_handles_idempotent_error_result_with_same_error(): void
    {
        $errorMessage = 'Processing failed due to timeout';

        $task = AdScriptTask::factory()->create([
            'status' => TaskStatus::FAILED,
            'error_details' => $errorMessage,
        ]);

        $payload = [
            'error' => $errorMessage,
        ];

        $response = $this->postJsonWithSignature("/api/ad-scripts/{$task->id}/result", $payload);

        $response->assertStatus(200)
            ->assertJson([
                'message' => 'Result processed successfully',
                'data' => [
                    'id' => $task->id,
                    'status' => 'failed',
                    'was_updated' => true, // Idempotent operation still returns true
                    'error_details' => $errorMessage,
                ],
            ]);

        // Verify task wasn't modified
        $task->refresh();
        $this->assertEquals(TaskStatus::FAILED, $task->status);
        $this->assertEquals($errorMessage, $task->error_details);
    }

    public function test_it_rejects_non_idempotent_success_result_with_different_data(): void
    {
        $task = AdScriptTask::factory()->create([
            'status' => TaskStatus::COMPLETED,
            'new_script' => 'Original script',
            'analysis' => ['original' => 'data'],
        ]);

        $payload = [
            'new_script' => 'Different script',
            'analysis' => ['different' => 'data'],
        ];

        $response = $this->postJsonWithSignature("/api/ad-scripts/{$task->id}/result", $payload);

        $response->assertStatus(422)
            ->assertJson([
                'message' => 'Result processing failed or was idempotent',
                'data' => [
                    'id' => $task->id,
                    'status' => 'completed',
                    'was_updated' => false,
                    'new_script' => 'Original script',
                    'analysis' => ['original' => 'data'],
                ],
            ]);

        // Verify task wasn't modified
        $task->refresh();
        $this->assertEquals(TaskStatus::COMPLETED, $task->status);
        $this->assertEquals('Original script', $task->new_script);
        $this->assertEquals(['original' => 'data'], $task->analysis);
    }

    public function test_it_rejects_non_idempotent_error_result_with_different_error(): void
    {
        $task = AdScriptTask::factory()->create([
            'status' => TaskStatus::FAILED,
            'error_details' => 'Original error',
        ]);

        $payload = [
            'error' => 'Different error',
        ];

        $response = $this->postJsonWithSignature("/api/ad-scripts/{$task->id}/result", $payload);

        $response->assertStatus(422)
            ->assertJson([
                'message' => 'Result processing failed or was idempotent',
                'data' => [
                    'id' => $task->id,
                    'status' => 'failed',
                    'was_updated' => false,
                    'error_details' => 'Original error',
                ],
            ]);

        // Verify task wasn't modified
        $task->refresh();
        $this->assertEquals(TaskStatus::FAILED, $task->status);
        $this->assertEquals('Original error', $task->error_details);
    }

    public function test_it_rejects_success_result_for_failed_task(): void
    {
        $task = AdScriptTask::factory()->create([
            'status' => TaskStatus::FAILED,
            'error_details' => 'Previous error',
        ]);

        $payload = [
            'new_script' => 'New script',
            'analysis' => ['new' => 'analysis'],
        ];

        $response = $this->postJsonWithSignature("/api/ad-scripts/{$task->id}/result", $payload);

        $response->assertStatus(422)
            ->assertJson([
                'message' => 'Result processing failed or was idempotent',
                'data' => [
                    'id' => $task->id,
                    'status' => 'failed',
                    'was_updated' => false,
                    'error_details' => 'Previous error',
                ],
            ]);

        // Verify task wasn't modified
        $task->refresh();
        $this->assertEquals(TaskStatus::FAILED, $task->status);
        $this->assertEquals('Previous error', $task->error_details);
    }

    public function test_it_rejects_error_result_for_completed_task(): void
    {
        $task = AdScriptTask::factory()->create([
            'status' => TaskStatus::COMPLETED,
            'new_script' => 'Completed script',
            'analysis' => ['completed' => 'analysis'],
        ]);

        $payload = [
            'error' => 'New error',
        ];

        $response = $this->postJsonWithSignature("/api/ad-scripts/{$task->id}/result", $payload);

        $response->assertStatus(422)
            ->assertJson([
                'message' => 'Result processing failed or was idempotent',
                'data' => [
                    'id' => $task->id,
                    'status' => 'completed',
                    'was_updated' => false,
                    'new_script' => 'Completed script',
                    'analysis' => ['completed' => 'analysis'],
                ],
            ]);

        // Verify task wasn't modified
        $task->refresh();
        $this->assertEquals(TaskStatus::COMPLETED, $task->status);
        $this->assertEquals('Completed script', $task->new_script);
    }

    public function test_duplicate_callbacks_are_handled_gracefully(): void
    {
        $task = AdScriptTask::factory()->create([
            'status' => TaskStatus::PROCESSING,
        ]);

        $payload = [
            'new_script' => 'console.log("Completed");',
            'analysis' => ['status' => 'completed'],
        ];

        // First callback should succeed
        $response1 = $this->postJsonWithSignature("/api/ad-scripts/{$task->id}/result", $payload);
        $response1->assertStatus(200)
            ->assertJson([
                'message' => 'Result processed successfully',
                'data' => [
                    'status' => 'completed',
                    'was_updated' => true,
                ],
            ]);

        // Second identical callback should be idempotent
        $response2 = $this->postJsonWithSignature("/api/ad-scripts/{$task->id}/result", $payload);
        $response2->assertStatus(200)
            ->assertJson([
                'message' => 'Result processed successfully',
                'data' => [
                    'status' => 'completed',
                    'was_updated' => true, // Idempotent success
                ],
            ]);

        // Verify final state is correct
        $task->refresh();
        $this->assertEquals(TaskStatus::COMPLETED, $task->status);
        $this->assertEquals($payload['new_script'], $task->new_script);
        $this->assertEquals($payload['analysis'], $task->analysis);
    }

    public function test_it_handles_idempotency_for_completed_task(): void
    {
        $task = AdScriptTask::factory()->completed()->create();
        $originalScript = $task->new_script;
        $originalAnalysis = $task->analysis;

        $payload = [
            'new_script' => 'Different script content',
            'analysis' => ['different' => 'analysis'],
        ];

        $response = $this->postJsonWithSignature("/api/ad-scripts/{$task->id}/result", $payload);

        $response->assertStatus(422)
            ->assertJson([
                'message' => 'Result processing failed or was idempotent',
                'data' => [
                    'id' => $task->id,
                    'status' => 'completed',
                    'was_updated' => false,
                    'new_script' => $originalScript,
                    'analysis' => $originalAnalysis,
                ],
            ]);

        // Verify task wasn't modified
        $task->refresh();
        $this->assertEquals(TaskStatus::COMPLETED, $task->status);
        $this->assertEquals($originalScript, $task->new_script);
        $this->assertEquals($originalAnalysis, $task->analysis);
    }

    public function test_it_handles_idempotency_for_failed_task(): void
    {
        $task = AdScriptTask::factory()->failed()->create();
        $originalError = $task->error_details;

        $payload = [
            'error' => 'Different error message',
        ];

        $response = $this->postJsonWithSignature("/api/ad-scripts/{$task->id}/result", $payload);

        $response->assertStatus(422)
            ->assertJson([
                'message' => 'Result processing failed or was idempotent',
                'data' => [
                    'id' => $task->id,
                    'status' => 'failed',
                    'was_updated' => false,
                    'error_details' => $originalError,
                ],
            ]);

        // Verify task wasn't modified
        $task->refresh();
        $this->assertEquals(TaskStatus::FAILED, $task->status);
        $this->assertEquals($originalError, $task->error_details);
    }

    public function test_it_returns_404_for_non_existent_task(): void
    {
        $nonExistentId = '550e8400-e29b-41d4-a716-446655440000';

        $payload = [
            'new_script' => 'Some script',
            'analysis' => ['test' => 'data'],
        ];

        $response = $this->postJsonWithSignature("/api/ad-scripts/{$nonExistentId}/result", $payload);

        $response->assertStatus(404);
    }

    public function test_it_validates_required_payload_structure(): void
    {
        $task = AdScriptTask::factory()->create([
            'status' => TaskStatus::PROCESSING,
        ]);

        // Empty payload
        $response = $this->postJsonWithSignature("/api/ad-scripts/{$task->id}/result", []);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['payload'])
            ->assertJsonFragment([
                'payload' => ['Either new_script or error must be provided.'],
            ]);
    }

    public function test_it_validates_mutually_exclusive_fields(): void
    {
        $task = AdScriptTask::factory()->create([
            'status' => TaskStatus::PROCESSING,
        ]);

        $payload = [
            'new_script' => 'Some script',
            'error' => 'Some error',
        ];

        $response = $this->postJsonWithSignature("/api/ad-scripts/{$task->id}/result", $payload);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['payload'])
            ->assertJsonFragment([
                'payload' => ['Cannot provide both new_script and error in the same request.'],
            ]);
    }

    public function test_it_validates_new_script_field(): void
    {
        $task = AdScriptTask::factory()->create([
            'status' => TaskStatus::PROCESSING,
        ]);

        // Test non-string new_script
        $response = $this->postJsonWithSignature("/api/ad-scripts/{$task->id}/result", [
            'new_script' => 123,
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['new_script']);

        // Test too long new_script
        $response = $this->postJsonWithSignature("/api/ad-scripts/{$task->id}/result", [
            'new_script' => str_repeat('a', 50001),
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['new_script']);
    }

    public function test_it_validates_analysis_field(): void
    {
        $task = AdScriptTask::factory()->create([
            'status' => TaskStatus::PROCESSING,
        ]);

        // Test non-array analysis
        $response = $this->postJsonWithSignature("/api/ad-scripts/{$task->id}/result", [
            'new_script' => 'Valid script',
            'analysis' => 'not an array',
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['analysis']);

        // Test array with non-string values
        $response = $this->postJsonWithSignature("/api/ad-scripts/{$task->id}/result", [
            'new_script' => 'Valid script',
            'analysis' => ['valid' => 'string', 'invalid' => 123],
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['analysis.invalid']);
    }

    public function test_it_validates_error_field(): void
    {
        $task = AdScriptTask::factory()->create([
            'status' => TaskStatus::PROCESSING,
        ]);

        // Test non-string error
        $response = $this->postJsonWithSignature("/api/ad-scripts/{$task->id}/result", [
            'error' => 123,
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['error']);

        // Test too long error
        $response = $this->postJsonWithSignature("/api/ad-scripts/{$task->id}/result", [
            'error' => str_repeat('a', 5001),
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['error']);
    }

    public function test_it_handles_service_processing_failure(): void
    {
        $task = AdScriptTask::factory()->create([
            'status' => TaskStatus::PROCESSING,
        ]);

        // Create a payload with empty new_script which will fail validation
        $payload = [
            'new_script' => '',
        ];

        $response = $this->postJsonWithSignature("/api/ad-scripts/{$task->id}/result", $payload);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['payload'])
            ->assertJsonFragment([
                'payload' => ['Either new_script or error must be provided.'],
            ]);
    }

    public function test_it_handles_invalid_result_payload(): void
    {
        $task = AdScriptTask::factory()->create([
            'status' => TaskStatus::PROCESSING,
        ]);

        // Create a payload that will be considered invalid by the service
        // (neither success nor error - empty payload after validation)
        $payload = [
            'new_script' => null,
            'analysis' => null,
            'error' => null,
        ];

        $response = $this->postJsonWithSignature("/api/ad-scripts/{$task->id}/result", $payload);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['payload']);
    }

    public function test_it_processes_result_for_pending_task(): void
    {
        // This tests that the endpoint can process results even for pending tasks
        // This is correct behavior as n8n might send results for tasks that haven't been marked as processing yet
        $task = AdScriptTask::factory()->create([
            'status' => TaskStatus::PENDING,
        ]);

        $payload = [
            'new_script' => 'console.log("test");',
            'analysis' => ['test' => 'data'],
        ];

        $response = $this->postJsonWithSignature("/api/ad-scripts/{$task->id}/result", $payload);

        $response->assertStatus(200)
            ->assertJson([
                'message' => 'Result processed successfully',
                'data' => [
                    'id' => $task->id,
                    'status' => 'completed',
                    'was_updated' => true,
                ],
            ]);

        // Task should be updated to completed
        $task->refresh();
        $this->assertEquals(TaskStatus::COMPLETED, $task->status);
    }

    public function test_it_includes_updated_timestamp_in_response(): void
    {
        $task = AdScriptTask::factory()->create([
            'status' => TaskStatus::PROCESSING,
        ]);

        $payload = [
            'new_script' => 'console.log("test");',
            'analysis' => ['test' => 'data'],
        ];

        $response = $this->postJsonWithSignature("/api/ad-scripts/{$task->id}/result", $payload);

        $response->assertStatus(200);

        $responseData = $response->json('data');
        $this->assertIsArray($responseData);
        $this->assertArrayHasKey('updated_at', $responseData);
        $this->assertNotNull($responseData['updated_at']);
        $this->assertIsString($responseData['updated_at']);
        // Laravel's toISOString() returns microseconds, not milliseconds
        $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\.\d{6}Z$/', $responseData['updated_at']);
    }

    public function test_it_processes_minimal_success_payload(): void
    {
        $task = AdScriptTask::factory()->create([
            'status' => TaskStatus::PROCESSING,
        ]);

        $payload = [
            'new_script' => 'console.log("minimal");',
        ];

        $response = $this->postJsonWithSignature("/api/ad-scripts/{$task->id}/result", $payload);

        $response->assertStatus(200)
            ->assertJson([
                'message' => 'Result processed successfully',
                'data' => [
                    'id' => $task->id,
                    'status' => 'completed',
                    'was_updated' => true,
                    'new_script' => $payload['new_script'],
                    'analysis' => [],
                ],
            ]);

        $task->refresh();
        $this->assertEquals(TaskStatus::COMPLETED, $task->status);
        $this->assertEquals($payload['new_script'], $task->new_script);
        $this->assertEquals([], $task->analysis);
    }

    public function test_it_rejects_requests_without_webhook_signature(): void
    {
        $task = AdScriptTask::factory()->create([
            'status' => TaskStatus::PROCESSING,
        ]);

        $payload = [
            'new_script' => 'console.log("test");',
        ];

        // Make request without signature
        $response = $this->postJson("/api/ad-scripts/{$task->id}/result", $payload);

        $response->assertStatus(401)
            ->assertJson([
                'error' => 'Missing webhook signature',
            ]);
    }

    public function test_it_rejects_requests_with_invalid_webhook_signature(): void
    {
        $task = AdScriptTask::factory()->create([
            'status' => TaskStatus::PROCESSING,
        ]);

        $payload = [
            'new_script' => 'console.log("test");',
        ];

        // Make request with invalid signature
        $response = $this->postJson("/api/ad-scripts/{$task->id}/result", $payload, [
            'X-N8N-Signature' => 'sha256=invalid-signature',
        ]);

        $response->assertStatus(401)
            ->assertJson([
                'error' => 'Invalid webhook signature',
            ]);
    }
}
