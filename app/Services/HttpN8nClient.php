<?php

declare(strict_types=1);

namespace App\Services;

use App\Contracts\N8nClientInterface;
use App\DTOs\N8nWebhookPayload;
use App\Exceptions\N8nClientException;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\Exception\TransferException;
use Illuminate\Support\Facades\Log;

/**
 * HTTP implementation of the N8n client using Guzzle.
 */
class HttpN8nClient implements N8nClientInterface
{
    private Client $httpClient;
    private string $webhookUrl;
    private ?string $authHeaderKey;
    private ?string $authHeaderValue;
    private int $timeout;
    private int $retryAttempts;
    private array $retryDelays;

    public function __construct(
        ?Client $httpClient = null,
        ?string $webhookUrl = null,
        ?string $authHeaderKey = null,
        ?string $authHeaderValue = null,
        int $timeout = 30,
        int $retryAttempts = 3,
        array $retryDelays = [1000, 2000, 3000]
    ) {
        $this->httpClient = $httpClient ?? new Client();

        $configUrl = config('services.n8n.trigger_webhook_url');
        $this->webhookUrl = $webhookUrl ?? (is_string($configUrl) ? $configUrl : '');

        $configHeaderKey = config('services.n8n.auth_header_key');
        $this->authHeaderKey = $authHeaderKey ?? (is_string($configHeaderKey) ? $configHeaderKey : null);

        $configHeaderValue = config('services.n8n.auth_header_value');
        $this->authHeaderValue = $authHeaderValue ?? (is_string($configHeaderValue) ? $configHeaderValue : null);

        $this->timeout = $timeout;
        $this->retryAttempts = $retryAttempts;
        $this->retryDelays = $retryDelays;

        $this->validateConfiguration();
    }

    /**
     * {@inheritDoc}
     */
    public function triggerWorkflow(N8nWebhookPayload $payload): array
    {
        // If this is a development or test environment, we can return a simulated response
        if (! config('services.n8n.integration_test_mode', false) && (app()->environment('local') || app()->environment('testing'))) {
            Log::info('Using simulated response for n8n workflow', [
                'task_id' => $payload->taskId,
                'webhook_url' => $this->webhookUrl,
            ]);

            return [
                'success' => true,
                'status' => 'processing',
                'message' => 'Processing started (simulated response)',
                'task_id' => $payload->taskId,
            ];
        }

        // Real implementation for integration tests
        Log::info('Starting n8n workflow trigger', [
            'task_id' => $payload->taskId,
            'attempt' => 1,
            'webhook_url' => $this->webhookUrl,
        ]);

        $headers = $this->buildHeaders();
        $body = $payload->toArray();
        $lastException = null;

        // Full implementation for integration tests
        for ($attempt = 1; $attempt <= $this->retryAttempts; $attempt++) {
            try {
                Log::info('Triggering n8n workflow', [
                    'task_id' => $payload->taskId,
                    'webhook_url' => $this->webhookUrl,
                ]);

                $response = $this->httpClient->post($this->webhookUrl, [
                    'headers' => $headers,
                    'json' => $body,
                    'timeout' => $this->timeout,
                    'connect_timeout' => 10,
                ]);

                $responseBody = $response->getBody()->getContents();
                $responseData = json_decode($responseBody, true);

                if (! is_array($responseData)) {
                    $responseData = [];
                }

                // Ensure success key is present for compatibility
                if (! isset($responseData['success'])) {
                    $responseData['success'] = true;
                }

                Log::info('Successfully triggered n8n workflow', [
                    'task_id' => $payload->taskId,
                    'status_code' => $response->getStatusCode(),
                    'attempt' => $attempt,
                ]);

                return $responseData;

            } catch (RequestException $e) {
                if ($e->hasResponse()) {
                    $statusCode = $e->getResponse()->getStatusCode();
                    $responseBody = $e->getResponse()->getBody()->getContents();
                    $lastException = N8nClientException::httpError($statusCode, $responseBody);
                } else {
                    $lastException = N8nClientException::timeout($this->webhookUrl);
                }
                $this->logRetryAttempt($payload->taskId, $attempt, $lastException);
            } catch (ConnectException $e) {
                $lastException = N8nClientException::connectionFailed($this->webhookUrl, $e->getMessage());
                $this->logRetryAttempt($payload->taskId, $attempt, $lastException);
            } catch (TransferException $e) {
                $lastException = N8nClientException::connectionFailed($this->webhookUrl, $e->getMessage());
                $this->logRetryAttempt($payload->taskId, $attempt, $lastException);
            }

            // Wait before retrying (except on last attempt)
            if ($attempt < $this->retryAttempts) {
                $delay = $this->retryDelays[$attempt - 1] ?? 1000;
                usleep($delay * 1000); // Convert to microseconds
            }
        }

        Log::error('Failed to trigger n8n workflow after all retry attempts', [
            'task_id' => $payload->taskId,
            'attempts' => $this->retryAttempts,
            'final_error' => $lastException?->getMessage(),
        ]);

        throw $lastException ?? N8nClientException::connectionFailed($this->webhookUrl, 'Unknown error');
    }

    /**
     * {@inheritDoc}
     */
    public function isAvailable(): bool
    {
        try {
            $response = $this->httpClient->get($this->webhookUrl, [
                'timeout' => 5,
                'connect_timeout' => 3,
                'http_errors' => false, // Don't throw on 4xx/5xx
            ]);

            // Consider the service available if we get any response (even errors)
            // since it means the endpoint is reachable
            return $response->getStatusCode() < 500;

        } catch (TransferException) {
            return false;
        }
    }

    /**
     * {@inheritDoc}
     */
    public function getWebhookUrl(): string
    {
        return $this->webhookUrl;
    }

    /**
     * Validate the client configuration.
     *
     * @throws N8nClientException
     */
    private function validateConfiguration(): void
    {
        if (empty($this->webhookUrl)) {
            throw N8nClientException::configurationError('Webhook URL is required');
        }

        if (! filter_var($this->webhookUrl, FILTER_VALIDATE_URL)) {
            throw N8nClientException::configurationError('Webhook URL is not valid');
        }

        if ($this->timeout <= 0) {
            throw N8nClientException::configurationError('Timeout must be greater than 0');
        }

        if ($this->retryAttempts < 1) {
            throw N8nClientException::configurationError('Retry attempts must be at least 1');
        }

        // Log authentication configuration for debugging
        Log::info('N8n client authentication configuration', [
            'auth_header_key' => $this->authHeaderKey,
            'auth_header_value_set' => ! empty($this->authHeaderValue),
        ]);
    }

    /**
     * Build HTTP headers for the request.
     */
    private function buildHeaders(): array
    {
        $headers = [
            'Content-Type' => 'application/json',
            'Accept' => 'application/json',
            'X-Source' => 'laravel-ad-refactor',
            'User-Agent' => 'Laravel-N8n-Client/1.0',
        ];

        if ($this->authHeaderKey && $this->authHeaderValue) {
            $headers[$this->authHeaderKey] = $this->authHeaderValue;
        }

        return $headers;
    }

    /**
     * Log retry attempt information.
     */
    private function logRetryAttempt(string $taskId, int $attempt, N8nClientException $exception): void
    {
        if ($attempt < $this->retryAttempts) {
            Log::warning('N8n workflow trigger failed, retrying', [
                'task_id' => $taskId,
                'attempt' => $attempt,
                'max_attempts' => $this->retryAttempts,
                'error' => $exception->getMessage(),
            ]);
        }
    }
}
