# Audit Logging System

The Audit Logging System provides comprehensive logging of all significant actions and events within the application, making it easier to track user activities, system changes, and potential security issues.

## Configuration

The audit logging system uses a dedicated log channel in Laravel's logging system.

1. Add the following to your `.env` file:

```
AUDIT_LOG_LEVEL=info
```

2. The logs are stored in `storage/logs/audit-YYYY-MM-DD.log` with a 90-day retention period.

## Usage

### AuditLogService

The `AuditLogService` class provides methods for logging various types of events:

```php
// Inject the service where needed
use App\Services\AuditLogService;

public function __construct(private AuditLogService $auditLogService)
{
    // ...
}

// Log various events
$this->auditLogService->logTaskCreation($task);
$this->auditLogService->logTaskStatusChange($task, $oldStatus, $newStatus);
$this->auditLogService->logTaskCompleted($task);
$this->auditLogService->logTaskFailed($task, $errorDetails);
$this->auditLogService->logApiRequest($endpoint, $context);
$this->auditLogService->logApiResponse($endpoint, $statusCode, $context);
$this->auditLogService->logError($message, $exception, $context);
```

### Automatic API Request Logging

All API requests are automatically logged using the `AuditLogMiddleware` which is registered for the `api` route group. This provides:

- Request logging (method, endpoint, IP, user agent, etc.)
- Response logging (status code, duration, etc.)
- Skipping of health check and metrics endpoints

### Extending the System

To add new audit log types:

1. Add a new method to `AuditLogService` for the specific event
2. Use meaningful event names (e.g., `user.login`, `data.export`)
3. Include relevant context data with each log

## Log Format

Each audit log entry includes:

- Timestamp (ISO 8601 format)
- Event type (e.g., `task.created`, `api.request`)
- User ID (if authenticated)
- Request ID (if available)
- Event-specific context data

Example:

```json
{
  "event": "task.created",
  "task_id": "550e8400-e29b-41d4-a716-446655440000",
  "reference_script_length": 250,
  "outcome_description_length": 120,
  "status": "pending",
  "timestamp": "2025-05-29T02:45:23.456Z",
  "user_id": null,
  "request_id": "req_12345"
}
```

## Querying Logs

### Command-Line Viewer

The application includes a command-line tool for viewing and analyzing audit logs:

```bash
# View today's audit logs (latest 50 entries)
php artisan audit:logs

# View logs from a specific date
php artisan audit:logs --date=2025-05-28

# Filter by event type
php artisan audit:logs --event=task.created

# Filter by task ID
php artisan audit:logs --task=550e8400-e29b-41d4-a716-446655440000

# Output in JSON format for further processing
php artisan audit:logs --json > audit_logs.json

# Adjust the number of results
php artisan audit:logs --limit=100
```

### Manual File Access

For production environments, it's recommended to use a log aggregation tool like ELK Stack (Elasticsearch, Logstash, Kibana), Graylog, or Splunk to query and analyze audit logs.

For local development, you can also use the `tail` command to watch logs in real-time:

```bash
tail -f storage/logs/audit-$(date +%Y-%m-%d).log
```

## Security Considerations

1. Audit logs should be treated as sensitive data
2. Access to log files should be restricted
3. Consider forwarding logs to a secure log aggregation system
4. Implement log rotation to manage disk space

## Testing

The audit logging system includes comprehensive tests:

- Unit tests for `AuditLogService` methods
- Integration tests for the service with other components
- Feature tests for the middleware
- End-to-end tests for complete workflows

Run the tests with:

```bash
php artisan test --filter=AuditLog
```
