<?php

declare(strict_types=1);

namespace App\Exceptions;

/**
 * Exception thrown when n8n client configuration is invalid.
 */
class N8nConfigurationException extends N8nClientException
{
    /**
     * Create a new exception for missing required configuration.
     */
    public static function missingRequired(string $parameter): self
    {
        return new self("{$parameter} is required");
    }

    /**
     * Create a new exception for invalid configuration values.
     */
    public static function invalidValue(string $parameter, string $reason = ''): self
    {
        $message = "{$parameter} is invalid";
        if ($reason) {
            $message .= ": {$reason}";
        }

        return new self($message);
    }
}
