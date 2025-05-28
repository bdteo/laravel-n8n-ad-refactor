<?php

declare(strict_types=1);

namespace App\DTOs;

/**
 * Data Transfer Object for payload received from n8n callback.
 */
readonly class N8nResultPayload
{
    public function __construct(
        public ?string $newScript = null,
        public ?array $analysis = null,
        public ?string $error = null,
    ) {
    }

    /**
     * Check if the payload represents a successful result.
     */
    public function isSuccess(): bool
    {
        return $this->error === null && $this->newScript !== null;
    }

    /**
     * Check if the payload represents an error result.
     */
    public function isError(): bool
    {
        return $this->error !== null;
    }

    /**
     * Create a DTO instance from request data.
     */
    public static function fromArray(array $data): self
    {
        return new self(
            newScript: $data['new_script'] ?? null,
            analysis: $data['analysis'] ?? null,
            error: $data['error'] ?? null,
        );
    }

    /**
     * Create a success payload.
     */
    public static function success(string $newScript, array $analysis): self
    {
        return new self(
            newScript: $newScript,
            analysis: $analysis,
        );
    }

    /**
     * Create an error payload.
     */
    public static function error(string $error): self
    {
        return new self(
            error: $error,
        );
    }

    /**
     * Convert the DTO to an array.
     */
    public function toArray(): array
    {
        return array_filter([
            'new_script' => $this->newScript,
            'analysis' => $this->analysis,
            'error' => $this->error,
        ], fn ($value) => $value !== null);
    }
}
