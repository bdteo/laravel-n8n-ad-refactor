<?php

declare(strict_types=1);

namespace App\Exceptions;

use Exception;

/**
 * Exception thrown when ad script task operations fail.
 */
class AdScriptTaskException extends Exception
{
    /**
     * Create a new exception for task creation failures.
     */
    public static function creationFailed(string $reason, ?\Throwable $previous = null): self
    {
        return new self("Failed to create ad script task: {$reason}", 0, $previous);
    }

    /**
     * Create a new exception for task processing failures.
     */
    public static function processingFailed(string $taskId, string $reason, ?\Throwable $previous = null): self
    {
        return new self("Failed to process ad script task {$taskId}: {$reason}", 0, $previous);
    }

    /**
     * Create a new exception for task not found errors.
     */
    public static function notFound(string $taskId): self
    {
        return new self("Ad script task with ID {$taskId} not found");
    }

    /**
     * Create a new exception for invalid task state transitions.
     */
    public static function invalidStateTransition(string $taskId, string $currentState, string $targetState): self
    {
        return new self("Cannot transition task {$taskId} from {$currentState} to {$targetState}");
    }

    /**
     * Create a new exception for task dispatch failures.
     */
    public static function dispatchFailed(string $taskId, string $reason, ?\Throwable $previous = null): self
    {
        return new self("Failed to dispatch ad script task {$taskId}: {$reason}", 0, $previous);
    }

    /**
     * Create a new exception for task update failures.
     */
    public static function updateFailed(string $taskId, string $reason, ?\Throwable $previous = null): self
    {
        return new self("Failed to update ad script task {$taskId}: {$reason}", 0, $previous);
    }

    /**
     * Create a new exception for concurrent modification conflicts.
     */
    public static function concurrentModification(string $taskId): self
    {
        return new self("Concurrent modification detected for task {$taskId}");
    }
}
