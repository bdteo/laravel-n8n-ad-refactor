<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Feature tests for API security, rate limiting, and authentication scenarios.
 *
 * NOTE: This test class has been refactored and split into multiple test files:
 * - WebhookSecurityTest.php - Tests for webhook signature validation
 * - ApiRateLimitingTest.php - Tests for rate limiting behavior
 * - ApiSecurityTest.php - Tests for security features (SQL injection, XSS, etc.)
 *
 * This file is maintained for backward compatibility and test discovery.
 * Please add new tests to the appropriate specialized test class.
 */
class ApiSecurityAndLimitsTest extends TestCase
{
    use RefreshDatabase;

    /**
     * This test redirects to the WebhookSecurityTest class.
     * See that class for all webhook security tests.
     */
    public function test_webhook_security_is_tested(): void
    {
        // This test just verifies we don't break backward compatibility
        // The actual tests have been moved to WebhookSecurityTest
        $this->assertTrue(true, 'Webhook security tests have been moved to WebhookSecurityTest');
    }

    /**
     * This test redirects to the ApiRateLimitingTest class.
     * See that class for all rate limiting tests.
     */
    public function test_rate_limiting_is_tested(): void
    {
        // This test just verifies we don't break backward compatibility
        // The actual tests have been moved to ApiRateLimitingTest
        $this->assertTrue(true, 'Rate limiting tests have been moved to ApiRateLimitingTest');
    }

    /**
     * This test redirects to the ApiSecurityTest class.
     * See that class for all API security tests.
     */
    public function test_api_security_is_tested(): void
    {
        // This test just verifies we don't break backward compatibility
        // The actual tests have been moved to ApiSecurityTest
        $this->assertTrue(true, 'API security tests have been moved to ApiSecurityTest');
    }
}
