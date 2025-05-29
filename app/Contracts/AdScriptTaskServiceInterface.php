<?php

declare(strict_types=1);

namespace App\Contracts;

use App\DTOs\N8nResultPayload;
use App\DTOs\N8nWebhookPayload;
use App\Enums\TaskStatus;
use App\Models\AdScriptTask;

interface AdScriptTaskServiceInterface
{
    public function createTask(array $data): AdScriptTask;

    public function createAndDispatchTask(array $data): AdScriptTask;

    public function dispatchTask(AdScriptTask $task): void;

    public function findTask(string $id): AdScriptTask;

    public function markAsProcessing(AdScriptTask $task): bool;

    public function processSuccessResult(AdScriptTask $task, N8nResultPayload $payload): bool;

    public function processErrorResult(AdScriptTask $task, N8nResultPayload $payload): bool;

    public function processResult(AdScriptTask $task, N8nResultPayload $payload): bool;

    public function processResultIdempotent(AdScriptTask $task, N8nResultPayload $payload): array;

    public function createWebhookPayload(AdScriptTask $task): N8nWebhookPayload;

    public function canProcess(AdScriptTask $task): bool;

    public function isFinal(AdScriptTask $task): bool;

    public function getStatus(AdScriptTask $task): TaskStatus;

    public function markAsFailed(AdScriptTask $task, string $errorDetails): bool;
}
