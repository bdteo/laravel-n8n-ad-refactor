<?php

declare(strict_types=1);

namespace App\Contracts;

use App\DTOs\N8nWebhookPayload;
use App\Models\AdScriptTask;

/**
 * Interface for communicating with n8n workflows.
 */
interface N8nClientInterface
{
    /**
     * Trigger a workflow by sending a webhook payload.
     *
     * @param N8nWebhookPayload|AdScriptTask $payload The payload or task to send to the workflow
     * @return array The response data from n8n
     * @throws \App\Exceptions\N8nClientException When the request fails
     */
    public function triggerWorkflow(N8nWebhookPayload|AdScriptTask $payload): array;

    /**
     * Check if the n8n service is available.
     *
     * @return bool True if the service is available, false otherwise
     */
    public function isAvailable(): bool;

    /**
     * Get the webhook URL being used.
     *
     * @return string The webhook URL
     */
    public function getWebhookUrl(): string;
}
