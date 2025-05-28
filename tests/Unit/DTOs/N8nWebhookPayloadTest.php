<?php

declare(strict_types=1);

use App\DTOs\N8nWebhookPayload;
use App\Enums\TaskStatus;
use App\Models\AdScriptTask;

describe('N8nWebhookPayload', function () {
    it('can be instantiated with required properties', function () {
        $payload = new N8nWebhookPayload(
            taskId: 'test-task-id',
            referenceScript: 'Original ad script content',
            outcomeDescription: 'Make it more engaging'
        );

        expect($payload->taskId)->toBe('test-task-id');
        expect($payload->referenceScript)->toBe('Original ad script content');
        expect($payload->outcomeDescription)->toBe('Make it more engaging');
    });

    it('can be converted to array', function () {
        $payload = new N8nWebhookPayload(
            taskId: 'test-task-id',
            referenceScript: 'Original ad script content',
            outcomeDescription: 'Make it more engaging'
        );

        $array = $payload->toArray();

        expect($array)->toBe([
            'task_id' => 'test-task-id',
            'reference_script' => 'Original ad script content',
            'outcome_description' => 'Make it more engaging',
        ]);
    });

    it('can be created from AdScriptTask model', function () {
        $task = new AdScriptTask([
            'reference_script' => 'Model script content',
            'outcome_description' => 'Model outcome description',
            'status' => TaskStatus::PENDING,
        ]);

        // Manually set the ID since it's normally generated on save
        $task->id = 'model-task-id';

        $payload = N8nWebhookPayload::fromAdScriptTask($task);

        expect($payload->taskId)->toBe('model-task-id');
        expect($payload->referenceScript)->toBe('Model script content');
        expect($payload->outcomeDescription)->toBe('Model outcome description');
    });

    it('maintains immutability as readonly class', function () {
        $payload = new N8nWebhookPayload(
            taskId: 'test-task-id',
            referenceScript: 'Original content',
            outcomeDescription: 'Original description'
        );

        // Attempting to modify properties should result in a fatal error
        // This test verifies the readonly nature by checking reflection
        $reflection = new ReflectionClass($payload);
        expect($reflection->isReadOnly())->toBeTrue();
    });

    it('handles empty strings correctly', function () {
        $payload = new N8nWebhookPayload(
            taskId: '',
            referenceScript: '',
            outcomeDescription: ''
        );

        expect($payload->taskId)->toBe('');
        expect($payload->referenceScript)->toBe('');
        expect($payload->outcomeDescription)->toBe('');

        $array = $payload->toArray();
        expect($array)->toBe([
            'task_id' => '',
            'reference_script' => '',
            'outcome_description' => '',
        ]);
    });

    it('handles special characters in content', function () {
        $specialContent = "Script with\nnewlines and \"quotes\" and 'apostrophes'";
        $specialDescription = "Description with émojis 🚀 and unicode ñ";

        $payload = new N8nWebhookPayload(
            taskId: 'special-task-id',
            referenceScript: $specialContent,
            outcomeDescription: $specialDescription
        );

        expect($payload->referenceScript)->toBe($specialContent);
        expect($payload->outcomeDescription)->toBe($specialDescription);

        $array = $payload->toArray();
        expect($array['reference_script'])->toBe($specialContent);
        expect($array['outcome_description'])->toBe($specialDescription);
    });
});
