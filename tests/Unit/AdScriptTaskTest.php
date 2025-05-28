<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Enums\TaskStatus;
use App\Models\AdScriptTask;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class AdScriptTaskTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_creates_task_with_uuid(): void
    {
        $task = AdScriptTask::create([
            'reference_script' => 'Original ad script content',
            'outcome_description' => 'Make it more engaging',
        ]);

        $this->assertNotNull($task->id);
        $this->assertIsString($task->id);
        $this->assertMatchesRegularExpression(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/',
            $task->id
        );
    }

    public function test_it_has_default_pending_status(): void
    {
        $task = AdScriptTask::create([
            'reference_script' => 'Original ad script content',
            'outcome_description' => 'Make it more engaging',
        ]);

        $this->assertEquals(TaskStatus::PENDING, $task->status);
        $this->assertTrue($task->canProcess());
        $this->assertFalse($task->isFinal());
    }

    public function test_it_casts_status_to_enum(): void
    {
        $task = AdScriptTask::create([
            'reference_script' => 'Original ad script content',
            'outcome_description' => 'Make it more engaging',
            'status' => TaskStatus::PROCESSING,
        ]);

        $this->assertInstanceOf(TaskStatus::class, $task->status);
        $this->assertEquals(TaskStatus::PROCESSING, $task->status);
    }

    public function test_it_casts_analysis_to_array(): void
    {
        $analysis = ['tone' => 'professional', 'length' => 'short'];

        $task = AdScriptTask::create([
            'reference_script' => 'Original ad script content',
            'outcome_description' => 'Make it more engaging',
            'analysis' => $analysis,
        ]);

        $this->assertIsArray($task->analysis);
        $this->assertEquals($analysis, $task->analysis);
    }

    public function test_can_process_returns_true_for_pending_status(): void
    {
        $task = new AdScriptTask(['status' => TaskStatus::PENDING]);

        $this->assertTrue($task->canProcess());
    }

    public function test_can_process_returns_false_for_non_pending_status(): void
    {
        $statuses = [TaskStatus::PROCESSING, TaskStatus::COMPLETED, TaskStatus::FAILED];

        foreach ($statuses as $status) {
            $task = new AdScriptTask(['status' => $status]);
            $this->assertFalse($task->canProcess(), "Status {$status->value} should not be processable");
        }
    }

    public function test_is_final_returns_true_for_completed_and_failed(): void
    {
        $finalStatuses = [TaskStatus::COMPLETED, TaskStatus::FAILED];

        foreach ($finalStatuses as $status) {
            $task = new AdScriptTask(['status' => $status]);
            $this->assertTrue($task->isFinal(), "Status {$status->value} should be final");
        }
    }

    public function test_is_final_returns_false_for_pending_and_processing(): void
    {
        $nonFinalStatuses = [TaskStatus::PENDING, TaskStatus::PROCESSING];

        foreach ($nonFinalStatuses as $status) {
            $task = new AdScriptTask(['status' => $status]);
            $this->assertFalse($task->isFinal(), "Status {$status->value} should not be final");
        }
    }

    public function test_mark_as_processing_updates_status_when_pending(): void
    {
        $task = AdScriptTask::create([
            'reference_script' => 'Original ad script content',
            'outcome_description' => 'Make it more engaging',
        ]);

        $result = $task->markAsProcessing();

        $this->assertTrue($result);
        $freshTask = $task->fresh();
        $this->assertNotNull($freshTask);
        $this->assertEquals(TaskStatus::PROCESSING, $freshTask->status);
    }

    public function test_mark_as_processing_fails_when_not_pending(): void
    {
        $task = AdScriptTask::create([
            'reference_script' => 'Original ad script content',
            'outcome_description' => 'Make it more engaging',
            'status' => TaskStatus::PROCESSING,
        ]);

        $result = $task->markAsProcessing();

        // Should return true for idempotency since task is already processing
        $this->assertTrue($result);
        $freshTask = $task->fresh();
        $this->assertNotNull($freshTask);
        $this->assertEquals(TaskStatus::PROCESSING, $freshTask->status);
    }

    public function test_mark_as_completed_updates_task_correctly(): void
    {
        $task = AdScriptTask::create([
            'reference_script' => 'Original ad script content',
            'outcome_description' => 'Make it more engaging',
            'status' => TaskStatus::PROCESSING,
        ]);

        $newScript = 'Improved ad script content';
        $analysis = ['improvements' => ['tone', 'clarity']];

        $result = $task->markAsCompleted($newScript, $analysis);

        $this->assertTrue($result);

        $freshTask = $task->fresh();
        $this->assertNotNull($freshTask);
        $this->assertEquals(TaskStatus::COMPLETED, $freshTask->status);
        $this->assertEquals($newScript, $freshTask->new_script);
        $this->assertEquals($analysis, $freshTask->analysis);
        $this->assertNull($freshTask->error_details);
    }

    public function test_mark_as_failed_updates_task_correctly(): void
    {
        $task = AdScriptTask::create([
            'reference_script' => 'Original ad script content',
            'outcome_description' => 'Make it more engaging',
            'status' => TaskStatus::PROCESSING,
        ]);

        $errorDetails = 'AI service unavailable';

        $result = $task->markAsFailed($errorDetails);

        $this->assertTrue($result);

        $freshTask = $task->fresh();
        $this->assertNotNull($freshTask);
        $this->assertEquals(TaskStatus::FAILED, $freshTask->status);
        $this->assertEquals($errorDetails, $freshTask->error_details);
    }

    public function test_fillable_attributes_are_correctly_set(): void
    {
        $task = new AdScriptTask();

        $expectedFillable = [
            'reference_script',
            'outcome_description',
            'new_script',
            'analysis',
            'status',
            'error_details',
        ];

        $this->assertEquals($expectedFillable, $task->getFillable());
    }

    // Idempotency Tests

    public function test_mark_as_processing_is_idempotent(): void
    {
        $task = AdScriptTask::create([
            'reference_script' => 'Original ad script content',
            'outcome_description' => 'Make it more engaging',
            'status' => TaskStatus::PENDING,
        ]);

        // First call should succeed
        $result1 = $task->markAsProcessing();
        $this->assertTrue($result1);
        $this->assertEquals(TaskStatus::PROCESSING, $task->fresh()->status);

        // Second call should be idempotent and return true
        $result2 = $task->markAsProcessing();
        $this->assertTrue($result2);
        $this->assertEquals(TaskStatus::PROCESSING, $task->fresh()->status);
    }

    public function test_mark_as_processing_fails_for_final_states(): void
    {
        $finalStatuses = [TaskStatus::COMPLETED, TaskStatus::FAILED];

        foreach ($finalStatuses as $status) {
            $task = AdScriptTask::create([
                'reference_script' => 'Original ad script content',
                'outcome_description' => 'Make it more engaging',
                'status' => $status,
            ]);

            $result = $task->markAsProcessing();
            $this->assertFalse($result, "Should not be able to mark {$status->value} task as processing");
            $this->assertEquals($status, $task->fresh()->status);
        }
    }

    public function test_mark_as_completed_is_idempotent_with_same_data(): void
    {
        $task = AdScriptTask::create([
            'reference_script' => 'Original ad script content',
            'outcome_description' => 'Make it more engaging',
            'status' => TaskStatus::PROCESSING,
        ]);

        $newScript = 'Improved ad script content';
        $analysis = ['improvements' => ['tone', 'clarity']];

        // First call should succeed
        $result1 = $task->markAsCompleted($newScript, $analysis);
        $this->assertTrue($result1);

        $freshTask = $task->fresh();
        $this->assertEquals(TaskStatus::COMPLETED, $freshTask->status);
        $this->assertEquals($newScript, $freshTask->new_script);
        $this->assertEquals($analysis, $freshTask->analysis);

        // Second call with same data should be idempotent
        $result2 = $freshTask->markAsCompleted($newScript, $analysis);
        $this->assertTrue($result2);
        $this->assertEquals(TaskStatus::COMPLETED, $freshTask->fresh()->status);
    }

    public function test_mark_as_completed_fails_with_different_data(): void
    {
        $task = AdScriptTask::create([
            'reference_script' => 'Original ad script content',
            'outcome_description' => 'Make it more engaging',
            'status' => TaskStatus::COMPLETED,
            'new_script' => 'Original completed script',
            'analysis' => ['original' => 'analysis'],
        ]);

        $result = $task->markAsCompleted('Different script', ['different' => 'analysis']);
        $this->assertFalse($result);

        $freshTask = $task->fresh();
        $this->assertEquals('Original completed script', $freshTask->new_script);
        $this->assertEquals(['original' => 'analysis'], $freshTask->analysis);
    }

    public function test_mark_as_completed_fails_for_failed_task(): void
    {
        $task = AdScriptTask::create([
            'reference_script' => 'Original ad script content',
            'outcome_description' => 'Make it more engaging',
            'status' => TaskStatus::FAILED,
            'error_details' => 'Previous error',
        ]);

        $result = $task->markAsCompleted('New script', ['new' => 'analysis']);
        $this->assertFalse($result);

        $freshTask = $task->fresh();
        $this->assertEquals(TaskStatus::FAILED, $freshTask->status);
        $this->assertEquals('Previous error', $freshTask->error_details);
    }

    public function test_mark_as_failed_is_idempotent_with_same_error(): void
    {
        $task = AdScriptTask::create([
            'reference_script' => 'Original ad script content',
            'outcome_description' => 'Make it more engaging',
            'status' => TaskStatus::PROCESSING,
        ]);

        $errorDetails = 'AI service unavailable';

        // First call should succeed
        $result1 = $task->markAsFailed($errorDetails);
        $this->assertTrue($result1);

        $freshTask = $task->fresh();
        $this->assertEquals(TaskStatus::FAILED, $freshTask->status);
        $this->assertEquals($errorDetails, $freshTask->error_details);

        // Second call with same error should be idempotent
        $result2 = $freshTask->markAsFailed($errorDetails);
        $this->assertTrue($result2);
        $this->assertEquals(TaskStatus::FAILED, $freshTask->fresh()->status);
    }

    public function test_mark_as_failed_fails_with_different_error(): void
    {
        $task = AdScriptTask::create([
            'reference_script' => 'Original ad script content',
            'outcome_description' => 'Make it more engaging',
            'status' => TaskStatus::FAILED,
            'error_details' => 'Original error',
        ]);

        $result = $task->markAsFailed('Different error');
        $this->assertFalse($result);

        $freshTask = $task->fresh();
        $this->assertEquals('Original error', $freshTask->error_details);
    }

    public function test_mark_as_failed_fails_for_completed_task(): void
    {
        $task = AdScriptTask::create([
            'reference_script' => 'Original ad script content',
            'outcome_description' => 'Make it more engaging',
            'status' => TaskStatus::COMPLETED,
            'new_script' => 'Completed script',
            'analysis' => ['completed' => 'analysis'],
        ]);

        $result = $task->markAsFailed('New error');
        $this->assertFalse($result);

        $freshTask = $task->fresh();
        $this->assertEquals(TaskStatus::COMPLETED, $freshTask->status);
        $this->assertEquals('Completed script', $freshTask->new_script);
    }

    public function test_concurrent_status_updates_use_database_conditions(): void
    {
        $task = AdScriptTask::create([
            'reference_script' => 'Original ad script content',
            'outcome_description' => 'Make it more engaging',
            'status' => TaskStatus::PENDING,
        ]);

        // Simulate concurrent processing attempts by using database transactions
        $results = [];

        DB::transaction(function () use ($task, &$results) {
            $results[] = $task->markAsProcessing();
        });

        DB::transaction(function () use ($task, &$results) {
            // Refresh to get latest state
            $task->refresh();
            $results[] = $task->markAsProcessing();
        });

        // Both should succeed due to idempotency
        $this->assertTrue($results[0]);
        $this->assertTrue($results[1]);
        $this->assertEquals(TaskStatus::PROCESSING, $task->fresh()->status);
    }

    public function test_status_transitions_respect_business_rules(): void
    {
        $task = AdScriptTask::create([
            'reference_script' => 'Original ad script content',
            'outcome_description' => 'Make it more engaging',
            'status' => TaskStatus::PENDING,
        ]);

        // Can transition from PENDING to PROCESSING
        $this->assertTrue($task->markAsProcessing());
        $task->refresh();

        // Can transition from PROCESSING to COMPLETED
        $this->assertTrue($task->markAsCompleted('New script', ['analysis']));
        $task->refresh();

        // Cannot transition from COMPLETED back to PROCESSING
        $this->assertFalse($task->markAsProcessing());
        $this->assertEquals(TaskStatus::COMPLETED, $task->fresh()->status);
    }

    public function test_database_level_conditional_updates(): void
    {
        $task = AdScriptTask::create([
            'reference_script' => 'Original ad script content',
            'outcome_description' => 'Make it more engaging',
            'status' => TaskStatus::PROCESSING,
        ]);

        // Manually change status in database to simulate race condition
        DB::table('ad_script_tasks')
            ->where('id', $task->id)
            ->update(['status' => TaskStatus::COMPLETED->value]);

        // Attempt to mark as failed should fail due to conditional update
        $result = $task->markAsFailed('Error message');
        $this->assertFalse($result);

        // Task should remain completed
        $this->assertEquals(TaskStatus::COMPLETED, $task->fresh()->status);
    }
}
