<?php

declare(strict_types=1);

namespace Tests\Feature;

use Tests\Feature\AdScript\BaseAdScriptWorkflowTest;

/**
 * End-to-end feature tests for the complete ad script processing workflow.
 *
 * This class serves as a wrapper for the split test files in the AdScript directory:
 * - BasicWorkflowTest: Core workflow tests from submission to completion
 * - SecurityValidationTest: Security and validation tests
 * - AdvancedWorkflowTest: Advanced scenarios like concurrency and Unicode
 *
 * The actual test implementations have been moved to these separate files
 * for better organization and maintainability.
 *
 * @see \Tests\Feature\AdScript\BasicWorkflowTest
 * @see \Tests\Feature\AdScript\SecurityValidationTest
 * @see \Tests\Feature\AdScript\AdvancedWorkflowTest
 */
class AdScriptWorkflowTest extends BaseAdScriptWorkflowTest
{
    // This class now serves as a wrapper for the tests that have been
    // moved to separate files in the AdScript directory.

    // The tests have been split into three categories:
    // 1. BasicWorkflowTest - Core workflow tests
    // 2. SecurityValidationTest - Security and validation tests
    // 3. AdvancedWorkflowTest - Advanced scenarios

    // Run the tests from those files using:
    // php artisan test --filter=AdScript
}
