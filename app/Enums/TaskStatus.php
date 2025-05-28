<?php

declare(strict_types=1);

namespace App\Enums;

enum TaskStatus: string
{
    case PENDING = 'pending';
    case PROCESSING = 'processing';
    case COMPLETED = 'completed';
    case FAILED = 'failed';

    /**
     * Get all enum values as an array.
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }

    /**
     * Check if the status is a final state.
     */
    public function isFinal(): bool
    {
        return in_array($this, [self::COMPLETED, self::FAILED]);
    }

    /**
     * Check if the status allows processing.
     */
    public function canProcess(): bool
    {
        return $this === self::PENDING;
    }
}
