<?php

declare(strict_types=1);

namespace App\Exceptions;

use Exception;

/**
 * Exception thrown when business logic validation fails.
 */
class BusinessValidationException extends Exception
{
    private array $errors;

    public function __construct(string $message, array $errors = [], int $code = 0, ?\Throwable $previous = null)
    {
        parent::__construct($message, $code, $previous);
        $this->errors = $errors;
    }

    /**
     * Get validation errors.
     */
    public function getErrors(): array
    {
        return $this->errors;
    }

    /**
     * Create a new exception for invalid input data.
     */
    public static function invalidInput(string $field, string $reason, mixed $value = null): self
    {
        $errors = [
            $field => [
                'message' => $reason,
                'value' => $value,
            ],
        ];

        return new self("Invalid input for field '{$field}': {$reason}", $errors);
    }

    /**
     * Create a new exception for multiple validation errors.
     */
    public static function multipleErrors(array $errors): self
    {
        $message = 'Multiple validation errors occurred: ' . implode(', ', array_keys($errors));

        return new self($message, $errors);
    }

    /**
     * Create a new exception for business rule violations.
     */
    public static function businessRuleViolation(string $rule, string $reason): self
    {
        return new self("Business rule violation: {$rule} - {$reason}");
    }

    /**
     * Create a new exception for resource conflicts.
     */
    public static function resourceConflict(string $resource, string $identifier, string $reason): self
    {
        return new self("Resource conflict for {$resource} '{$identifier}': {$reason}");
    }

    /**
     * Create a new exception for insufficient permissions.
     */
    public static function insufficientPermissions(string $action, string $resource): self
    {
        return new self("Insufficient permissions to {$action} {$resource}");
    }
}
