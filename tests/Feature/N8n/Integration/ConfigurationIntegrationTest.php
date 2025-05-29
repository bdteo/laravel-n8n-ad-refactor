<?php

declare(strict_types=1);

namespace Tests\Feature\N8n\Integration;

use App\Exceptions\N8nConfigurationException;
use App\Services\HttpN8nClient;
use GuzzleHttp\Client;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

/**
 * Tests for N8n configuration integration.
 *
 * This test file focuses on the configuration aspects of N8n integration,
 * including environment settings, invalid settings, and missing settings.
 */
class ConfigurationIntegrationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Set up test configuration
        config([
            // Old config for backward compatibility in some tests
            'services.n8n.webhook_secret' => 'integration-test-secret',

            // New configs that match our updated implementation
            'services.n8n.trigger_webhook_url' => 'https://test-n8n.example.com/webhook/test',
            'services.n8n.auth_header_key' => 'X-Test-Auth',
            'services.n8n.auth_header_value' => 'test-auth-value',
            'services.n8n.callback_hmac_secret' => 'integration-test-secret',
            'services.n8n.timeout' => 30,
            'services.n8n.retry_attempts' => 3,

            // Enable integration test mode for this test class
            'services.n8n.integration_test_mode' => true,
        ]);

        // Spy on logs
        Log::spy();
    }

    /**
     * Test configuration integration with different environment settings.
     */
    public function test_configuration_integration_with_environment_settings(): void
    {
        $originalConfig = config('services.n8n');

        // Test with different configuration values
        $testConfigs = [
            // Test with minimum required values
            [
                'trigger_webhook_url' => 'https://test-min.example.com/webhook',
                'auth_header_key' => 'X-Min-Auth',
                'auth_header_value' => 'min-secret',
            ],
            // Test with all values
            [
                'trigger_webhook_url' => 'https://test-full.example.com/webhook',
                'auth_header_key' => 'X-Full-Auth',
                'auth_header_value' => 'full-secret',
                'timeout' => 60,
                'retry_attempts' => 5,
                'callback_hmac_secret' => 'full-hmac-secret',
            ],
            // Test with custom retry values
            [
                'trigger_webhook_url' => 'https://test-retry.example.com/webhook',
                'auth_header_key' => 'X-Retry-Auth',
                'auth_header_value' => 'retry-secret',
                'timeout' => 10,
                'retry_attempts' => 1,
            ],
        ];

        foreach ($testConfigs as $index => $testConfig) {
            config(['services.n8n' => array_merge($originalConfig, $testConfig)]);

            // Create a client with the current config but pass the webhook URL directly to make sure it's used
            $n8nClient = new HttpN8nClient(
                webhookUrl: $testConfig['trigger_webhook_url'],
                authHeaderKey: $testConfig['auth_header_key'],
                authHeaderValue: $testConfig['auth_header_value'],
                timeout: $testConfig['timeout'] ?? 30,
                retryAttempts: $testConfig['retry_attempts'] ?? 3
            );

            // Create a reflection to check private properties
            $reflection = new \ReflectionClass($n8nClient);

            $webhookUrlProp = $reflection->getProperty('webhookUrl');
            $webhookUrlProp->setAccessible(true);
            $this->assertEquals($testConfig['trigger_webhook_url'], $webhookUrlProp->getValue($n8nClient));

            $authHeaderKeyProp = $reflection->getProperty('authHeaderKey');
            $authHeaderKeyProp->setAccessible(true);
            $this->assertEquals($testConfig['auth_header_key'], $authHeaderKeyProp->getValue($n8nClient));

            $authHeaderValueProp = $reflection->getProperty('authHeaderValue');
            $authHeaderValueProp->setAccessible(true);
            $this->assertEquals($testConfig['auth_header_value'], $authHeaderValueProp->getValue($n8nClient));

            if (isset($testConfig['timeout'])) {
                $timeoutProp = $reflection->getProperty('timeout');
                $timeoutProp->setAccessible(true);
                $this->assertEquals($testConfig['timeout'], $timeoutProp->getValue($n8nClient));
            }

            if (isset($testConfig['retry_attempts'])) {
                $retryAttemptsProp = $reflection->getProperty('retryAttempts');
                $retryAttemptsProp->setAccessible(true);
                $this->assertEquals($testConfig['retry_attempts'], $retryAttemptsProp->getValue($n8nClient));
            }
        }

        // Restore original config
        config(['services.n8n' => $originalConfig]);
    }

    /**
     * Test configuration integration with invalid settings.
     */
    public function test_configuration_integration_with_invalid_settings(): void
    {
        $originalConfig = config('services.n8n');

        try {
            // Test with invalid webhook URL
            config(['services.n8n.trigger_webhook_url' => 'not-a-url']);
            $n8nClient = new HttpN8nClient();
            $this->fail('Expected N8nConfigurationException was not thrown');
        } catch (N8nConfigurationException $e) {
            $this->assertStringContainsString('webhook url', strtolower($e->getMessage()));
        } finally {
            // Clean up
            config(['services.n8n' => $originalConfig]);
        }
    }

    /**
     * Test configuration integration with missing required settings.
     */
    public function test_configuration_integration_with_missing_settings(): void
    {
        $originalConfig = config('services.n8n');

        try {
            // Test with missing webhook URL
            config(['services.n8n.trigger_webhook_url' => null]);
            $n8nClient = new HttpN8nClient();
            $this->fail('Expected N8nConfigurationException was not thrown');
        } catch (N8nConfigurationException $e) {
            $this->assertStringContainsString('webhook url', strtolower($e->getMessage()));
        } finally {
            // Clean up
            config(['services.n8n' => $originalConfig]);
        }
    }
}
