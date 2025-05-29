<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Contracts\AdScriptTaskServiceInterface;
use App\DTOs\N8nWebhookPayload;
use App\Enums\TaskStatus;
use App\Models\AdScriptTask;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

class AdScriptTaskServiceInterfaceTest extends TestCase
{
    use RefreshDatabase;

    public function test_interface_can_be_mocked_and_return_n8n_webhook_payload(): void
    {
        // Create a task model
        $task = AdScriptTask::factory()->create([
            'status' => TaskStatus::PENDING,
            'id' => '123e4567-e89b-12d3-a456-426614174000',
            'reference_script' => 'test script',
            'outcome_description' => 'test outcome',
        ]);

        // Create mock interface
        /** @var AdScriptTaskServiceInterface&\Mockery\MockInterface $serviceMock */
        $serviceMock = Mockery::mock(AdScriptTaskServiceInterface::class);

        // Set up expectation for createWebhookPayload to return a payload
        $expectedPayload = new N8nWebhookPayload(
            $task->id,
            $task->reference_script,
            $task->outcome_description
        );

        $serviceMock->shouldReceive('createWebhookPayload')
            ->once()
            ->with($task)
            ->andReturn($expectedPayload);

        // Call the mocked method
        $payload = $serviceMock->createWebhookPayload($task);

        // Verify we got back the expected payload
        $this->assertInstanceOf(N8nWebhookPayload::class, $payload);
        $this->assertEquals($task->id, $payload->taskId);
        $this->assertEquals($task->reference_script, $payload->referenceScript);
        $this->assertEquals($task->outcome_description, $payload->outcomeDescription);
    }
}
