<?php

declare(strict_types=1);

namespace App\DTOs;

/**
 * Data Transfer Object for payload sent to n8n webhook.
 */
readonly class N8nWebhookPayload
{
    public function __construct(
        public string $taskId,
        public string $referenceScript,
        public string $outcomeDescription,
    ) {
    }

    /**
     * Convert the DTO to an array for JSON serialization.
     */
    public function toArray(): array
    {
        return [
            'task_id' => $this->taskId,
            'reference_script' => $this->referenceScript,
            'outcome_description' => $this->outcomeDescription,
        ];
    }

    /**
     * Create a DTO instance from an AdScriptTask model.
     */
    public static function fromAdScriptTask(\App\Models\AdScriptTask $task): self
    {
        return new self(
            taskId: (string)$task->id,
            referenceScript: $task->reference_script,
            outcomeDescription: $task->outcome_description,
        );
    }
}
