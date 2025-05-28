<?php

declare(strict_types=1);

namespace Tests\Unit\Exceptions;

use App\Exceptions\BusinessValidationException;
use PHPUnit\Framework\TestCase;

class BusinessValidationExceptionTest extends TestCase
{
    public function test_constructor_sets_message_and_errors(): void
    {
        $message = 'Validation failed';
        $errors = ['field' => 'error message'];
        $exception = new BusinessValidationException($message, $errors);

        $this->assertEquals($message, $exception->getMessage());
        $this->assertEquals($errors, $exception->getErrors());
    }

    public function test_get_errors_returns_empty_array_by_default(): void
    {
        $exception = new BusinessValidationException('Test message');

        $this->assertEquals([], $exception->getErrors());
    }

    public function test_invalid_input_creates_exception_with_field_error(): void
    {
        $field = 'email';
        $reason = 'Invalid email format';
        $value = 'invalid-email';
        $exception = BusinessValidationException::invalidInput($field, $reason, $value);

        $this->assertInstanceOf(BusinessValidationException::class, $exception);
        $this->assertEquals("Invalid input for field '{$field}': {$reason}", $exception->getMessage());

        $expectedErrors = [
            $field => [
                'message' => $reason,
                'value' => $value,
            ],
        ];
        $this->assertEquals($expectedErrors, $exception->getErrors());
    }

    public function test_invalid_input_without_value(): void
    {
        $field = 'password';
        $reason = 'Password too short';
        $exception = BusinessValidationException::invalidInput($field, $reason);

        $expectedErrors = [
            $field => [
                'message' => $reason,
                'value' => null,
            ],
        ];
        $this->assertEquals($expectedErrors, $exception->getErrors());
    }

    public function test_multiple_errors_creates_exception_with_all_errors(): void
    {
        $errors = [
            'email' => 'Invalid email',
            'password' => 'Password too short',
            'name' => 'Name is required',
        ];
        $exception = BusinessValidationException::multipleErrors($errors);

        $this->assertInstanceOf(BusinessValidationException::class, $exception);
        $this->assertEquals('Multiple validation errors occurred: email, password, name', $exception->getMessage());
        $this->assertEquals($errors, $exception->getErrors());
    }

    public function test_business_rule_violation_creates_exception(): void
    {
        $rule = 'unique_email';
        $reason = 'Email already exists in the system';
        $exception = BusinessValidationException::businessRuleViolation($rule, $reason);

        $this->assertInstanceOf(BusinessValidationException::class, $exception);
        $this->assertEquals("Business rule violation: {$rule} - {$reason}", $exception->getMessage());
    }

    public function test_resource_conflict_creates_exception(): void
    {
        $resource = 'user';
        $identifier = 'john@example.com';
        $reason = 'User is currently being processed';
        $exception = BusinessValidationException::resourceConflict($resource, $identifier, $reason);

        $this->assertInstanceOf(BusinessValidationException::class, $exception);
        $this->assertEquals("Resource conflict for {$resource} '{$identifier}': {$reason}", $exception->getMessage());
    }

    public function test_insufficient_permissions_creates_exception(): void
    {
        $action = 'delete';
        $resource = 'admin user';
        $exception = BusinessValidationException::insufficientPermissions($action, $resource);

        $this->assertInstanceOf(BusinessValidationException::class, $exception);
        $this->assertEquals("Insufficient permissions to {$action} {$resource}", $exception->getMessage());
    }
}
