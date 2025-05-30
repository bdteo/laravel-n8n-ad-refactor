<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Jobs\TriggerN8nWorkflow;
use App\Models\AdScriptTask;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;
use Tests\Traits\TestsRateLimiting;

class AdScriptSubmissionTest extends TestCase
{
    use RefreshDatabase;
    use TestsRateLimiting;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
    }

    public function test_it_creates_ad_script_task_successfully(): void
    {
        $payload = [
            'reference_script' => 'This is a sample ad script that needs to be refactored.',
            'outcome_description' => 'Make it more engaging and persuasive.',
        ];

        $response = $this->postJson('/api/ad-scripts', $payload, $this->getNoRateLimitHeaders());

        $response->assertStatus(202)
            ->assertJsonStructure([
                'message',
                'data' => [
                    'id',
                    'status',
                    'created_at',
                ],
            ])
            ->assertJson([
                'message' => 'Ad script task created and queued for processing',
                'data' => [
                    'status' => 'pending',
                ],
            ]);

        $this->assertDatabaseHas('ad_script_tasks', [
            'reference_script' => $payload['reference_script'],
            'outcome_description' => $payload['outcome_description'],
            'status' => 'pending',
        ]);

        // Verify that the job was dispatched with the correct task
        Queue::assertPushed(TriggerN8nWorkflow::class, function ($job) use ($payload) {
            return $job->task instanceof AdScriptTask
                && $job->task->reference_script === $payload['reference_script']
                && $job->task->outcome_description === $payload['outcome_description'];
        });
    }

    public function test_it_validates_required_fields(): void
    {
        $response = $this->postJson('/api/ad-scripts', [], $this->getNoRateLimitHeaders());

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['reference_script', 'outcome_description']);
    }

    public function test_it_validates_reference_script_minimum_length(): void
    {
        $payload = [
            'reference_script' => 'short',
            'outcome_description' => 'Valid description',
        ];

        $response = $this->postJson('/api/ad-scripts', $payload, $this->getNoRateLimitHeaders());

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['reference_script'])
            ->assertJsonFragment([
                'reference_script' => ['The reference script must be at least 10 characters.'],
            ]);
    }

    public function test_it_validates_reference_script_maximum_length(): void
    {
        $payload = [
            'reference_script' => str_repeat('a', 10001),
            'outcome_description' => 'Valid description',
        ];

        $response = $this->postJson('/api/ad-scripts', $payload, $this->getNoRateLimitHeaders());

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['reference_script'])
            ->assertJsonFragment([
                'reference_script' => ['The reference script may not be greater than 10,000 characters.'],
            ]);
    }

    public function test_it_validates_outcome_description_minimum_length(): void
    {
        $payload = [
            'reference_script' => 'This is a valid reference script.',
            'outcome_description' => 'abc',
        ];

        $response = $this->postJson('/api/ad-scripts', $payload, $this->getNoRateLimitHeaders());

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['outcome_description'])
            ->assertJsonFragment([
                'outcome_description' => ['The outcome description must be at least 5 characters.'],
            ]);
    }

    public function test_it_validates_outcome_description_maximum_length(): void
    {
        $payload = [
            'reference_script' => 'This is a valid reference script.',
            'outcome_description' => str_repeat('a', 1001),
        ];

        $response = $this->postJson('/api/ad-scripts', $payload, $this->getNoRateLimitHeaders());

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['outcome_description'])
            ->assertJsonFragment([
                'outcome_description' => ['The outcome description may not be greater than 1,000 characters.'],
            ]);
    }

    public function test_it_validates_string_types(): void
    {
        $payload = [
            'reference_script' => 123,
            'outcome_description' => ['not', 'a', 'string'],
        ];

        $response = $this->postJson('/api/ad-scripts', $payload, $this->getNoRateLimitHeaders());

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['reference_script', 'outcome_description']);
    }

    public function test_it_returns_task_with_uuid(): void
    {
        $payload = [
            'reference_script' => 'This is a sample ad script that needs to be refactored.',
            'outcome_description' => 'Make it more engaging and persuasive.',
        ];

        $response = $this->postJson('/api/ad-scripts', $payload, $this->getNoRateLimitHeaders());

        $response->assertStatus(202);

        $taskId = $response->json('data.id');
        $this->assertIsString($taskId);
        $this->assertMatchesRegularExpression('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/', $taskId);

        $task = AdScriptTask::find($taskId);
        $this->assertNotNull($task);
        $this->assertEquals($payload['reference_script'], $task->reference_script);
        $this->assertEquals($payload['outcome_description'], $task->outcome_description);
    }

    public function test_it_returns_created_at_timestamp(): void
    {
        $payload = [
            'reference_script' => 'This is a sample ad script that needs to be refactored.',
            'outcome_description' => 'Make it more engaging and persuasive.',
        ];

        $response = $this->postJson('/api/ad-scripts', $payload, $this->getNoRateLimitHeaders());

        $response->assertStatus(202);

        $createdAt = $response->json('data.created_at');
        $this->assertIsString($createdAt);
        $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\.\d{6}Z$/', $createdAt);
    }
}
