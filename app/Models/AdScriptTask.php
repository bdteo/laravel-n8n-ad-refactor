<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\TaskStatus;
use App\Traits\HasUuid;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

class AdScriptTask extends Model
{
    use HasFactory;
    use HasUuid;

    /**
     * The attributes that are mass assignable.
     */
    protected $fillable = [
        'reference_script',
        'outcome_description',
        'new_script',
        'analysis',
        'status',
        'error_details',
    ];

    /**
     * The attributes that should be cast.
     */
    protected $casts = [
        'analysis' => 'array',
        'status' => TaskStatus::class,
    ];

    /**
     * The model's default values for attributes.
     */
    protected $attributes = [
        'status' => 'pending',
    ];

    /**
     * Check if the task is in a final state.
     */
    public function isFinal(): bool
    {
        return $this->status->isFinal();
    }

    /**
     * Check if the task can be processed.
     */
    public function canProcess(): bool
    {
        return $this->status->canProcess();
    }

    /**
     * Mark the task as processing (idempotent).
     * Only updates if current status is PENDING.
     */
    public function markAsProcessing(): bool
    {
        if ($this->status === TaskStatus::PROCESSING) {
            return true; // Already processing, idempotent success
        }

        if (! $this->canProcess()) {
            return false; // Cannot transition to processing
        }

        return DB::transaction(function () {
            $updated = $this->newQuery()
                ->where('id', $this->id)
                ->where('status', TaskStatus::PENDING->value)
                ->update(['status' => TaskStatus::PROCESSING->value]);

            if ($updated > 0) {
                $this->status = TaskStatus::PROCESSING;

                return true;
            }

            // Refresh to get current state
            $this->refresh();

            return $this->status === TaskStatus::PROCESSING;
        });
    }

    /**
     * Mark the task as completed (idempotent).
     * Only updates if current status is PENDING, PROCESSING or already COMPLETED with same data.
     */
    public function markAsCompleted(string $newScript, array $analysis): bool
    {
        // If already completed with same data, return true (idempotent)
        if ($this->status === TaskStatus::COMPLETED &&
            $this->new_script === $newScript &&
            $this->analysis === $analysis) {
            return true;
        }

        // If already completed with different data, cannot update
        if ($this->status === TaskStatus::COMPLETED &&
            ($this->new_script !== $newScript || $this->analysis !== $analysis)) {
            return false;
        }

        // If in other final state (failed), cannot update
        if ($this->isFinal() && $this->status !== TaskStatus::COMPLETED) {
            return false;
        }

        return DB::transaction(function () use ($newScript, $analysis) {
            $updated = $this->newQuery()
                ->where('id', $this->id)
                ->whereIn('status', [TaskStatus::PENDING->value, TaskStatus::PROCESSING->value, TaskStatus::COMPLETED->value])
                ->update([
                    'status' => TaskStatus::COMPLETED->value,
                    'new_script' => $newScript,
                    'analysis' => json_encode($analysis),
                    'error_details' => null,
                ]);

            if ($updated > 0) {
                $this->status = TaskStatus::COMPLETED;
                $this->new_script = $newScript;
                $this->analysis = $analysis;
                $this->error_details = null;

                return true;
            }

            // Refresh to get current state and check if it matches desired state
            $this->refresh();

            return $this->status === TaskStatus::COMPLETED &&
                   $this->new_script === $newScript &&
                   $this->analysis === $analysis;
        });
    }

    /**
     * Mark the task as failed (idempotent).
     * Only updates if current status is PENDING, PROCESSING or already FAILED with same error.
     */
    public function markAsFailed(string $errorDetails): bool
    {
        // If already failed with same error, return true (idempotent)
        if ($this->status === TaskStatus::FAILED && $this->error_details === $errorDetails) {
            return true;
        }

        // If already failed with different error, cannot update
        if ($this->status === TaskStatus::FAILED && $this->error_details !== $errorDetails) {
            return false;
        }

        // If in other final state (completed), cannot update
        if ($this->isFinal() && $this->status !== TaskStatus::FAILED) {
            return false;
        }

        return DB::transaction(function () use ($errorDetails) {
            $updated = $this->newQuery()
                ->where('id', $this->id)
                ->whereIn('status', [TaskStatus::PENDING->value, TaskStatus::PROCESSING->value, TaskStatus::FAILED->value])
                ->update([
                    'status' => TaskStatus::FAILED->value,
                    'error_details' => $errorDetails,
                ]);

            if ($updated > 0) {
                $this->status = TaskStatus::FAILED;
                $this->error_details = $errorDetails;

                return true;
            }

            // Refresh to get current state and check if it matches desired state
            $this->refresh();

            return $this->status === TaskStatus::FAILED && $this->error_details === $errorDetails;
        });
    }
}
