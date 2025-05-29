<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

class AuditLogViewerCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'audit:logs
                            {--date= : The date of logs to view (YYYY-MM-DD), defaults to today}
                            {--event= : Filter by event type (e.g. task.created, api.request)}
                            {--task= : Filter by task ID}
                            {--limit=50 : Limit the number of results}
                            {--json : Output in JSON format}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'View and analyze audit logs';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $date = $this->option('date') ?: now()->format('Y-m-d');
        $logPath = storage_path("logs/audit-{$date}.log");

        if (! File::exists($logPath)) {
            $this->error("No audit logs found for {$date}");

            // Try to show available dates
            $pattern = storage_path('logs/audit-*.log');
            $files = glob($pattern);

            if (count($files) > 0) {
                $dates = collect($files)->map(function ($file) {
                    return Str::of(basename($file))->match('/audit-(\d{4}-\d{2}-\d{2})\.log/');
                })->filter()->values()->toArray();

                if (count($dates) > 0) {
                    $this->info("Available audit log dates:");
                    foreach ($dates as $availableDate) {
                        $this->line("  - {$availableDate}");
                    }
                }
            }

            return 1;
        }

        // Read and parse log file
        $content = File::get($logPath);
        $lines = explode("\n", $content);
        $logs = [];

        foreach ($lines as $line) {
            if (empty(trim($line))) {
                continue;
            }

            try {
                // Parse JSON data from log line
                preg_match('/\{.*\}/', $line, $matches);
                if (empty($matches)) {
                    continue;
                }

                $logData = json_decode($matches[0], true);
                if (json_last_error() !== JSON_ERROR_NONE) {
                    continue;
                }

                // Apply filters
                if ($this->shouldSkip($logData)) {
                    continue;
                }

                $logs[] = $logData;

                // Break if we've reached the limit
                if (count($logs) >= (int)$this->option('limit')) {
                    break;
                }
            } catch (\Exception $e) {
                // Skip invalid log lines
                continue;
            }
        }

        // Output results
        if (count($logs) === 0) {
            $this->info("No matching audit logs found for your filters");

            return 0;
        }

        if ($this->option('json')) {
            $this->line(json_encode($logs, JSON_PRETTY_PRINT));

            return 0;
        }

        // Display as table
        $this->displayLogsAsTable($logs);

        return 0;
    }

    /**
     * Check if a log entry should be skipped based on filters.
     */
    private function shouldSkip(array $logData): bool
    {
        $eventFilter = $this->option('event');
        $taskIdFilter = $this->option('task');

        if ($eventFilter && (! isset($logData['event']) || $logData['event'] !== $eventFilter)) {
            return true;
        }

        if ($taskIdFilter && (! isset($logData['task_id']) || $logData['task_id'] !== $taskIdFilter)) {
            return true;
        }

        return false;
    }

    /**
     * Display logs in a formatted table.
     */
    private function displayLogsAsTable(array $logs): void
    {
        $headers = ['Timestamp', 'Event', 'Details'];
        $rows = [];

        foreach ($logs as $log) {
            $timestamp = $log['timestamp'] ?? 'N/A';
            $event = $log['event'] ?? 'unknown';

            // Format details based on event type
            $details = $this->formatLogDetails($log);

            $rows[] = [$timestamp, $event, $details];
        }

        $this->table($headers, $rows);
        $this->info("Showing " . count($rows) . " results. Use --limit to adjust.");
    }

    /**
     * Format log details based on event type.
     */
    private function formatLogDetails(array $log): string
    {
        $event = $log['event'] ?? 'unknown';

        // Remove common fields that are already displayed
        unset($log['event'], $log['timestamp']);

        // Format task events
        if (Str::startsWith($event, 'task.')) {
            return $this->formatTaskLogDetails($log, $event);
        }

        // Format API events
        if (Str::startsWith($event, 'api.')) {
            return $this->formatApiLogDetails($log, $event);
        }

        // Default formatting
        return collect($log)->map(function ($value, $key) {
            if (is_array($value)) {
                $value = '[Array]';
            }

            return "{$key}: {$value}";
        })->implode(', ');
    }

    /**
     * Format task-related log details.
     */
    private function formatTaskLogDetails(array $log, string $event): string
    {
        $taskId = $log['task_id'] ?? 'N/A';

        switch ($event) {
            case 'task.created':
                return "Task ID: {$taskId}, Status: {$log['status']}";

            case 'task.status_changed':
                return "Task ID: {$taskId}, Status changed from {$log['old_status']} to {$log['new_status']}";

            case 'task.completed':
                return "Task ID: {$taskId}, Script Length: {$log['new_script_length']}, Analysis Count: {$log['analysis_count']}";

            case 'task.failed':
                $error = isset($log['error_details']) ? Str::limit($log['error_details'], 50) : 'N/A';

                return "Task ID: {$taskId}, Error: {$error}";

            default:
                return "Task ID: {$taskId}, " . collect($log)->except(['task_id'])->map(function ($v, $k) {
                    if (is_array($v)) {
                        return "{$k}: [Array]";
                    }

                    return "{$k}: {$v}";
                })->implode(', ');
        }
    }

    /**
     * Format API-related log details.
     */
    private function formatApiLogDetails(array $log, string $event): string
    {
        switch ($event) {
            case 'api.request':
                $endpoint = $log['endpoint'] ?? 'N/A';
                $method = $log['method'] ?? 'N/A';
                $ip = $log['ip'] ?? 'N/A';

                return "Endpoint: {$method} {$endpoint}, IP: {$ip}";

            case 'api.response':
                $endpoint = $log['endpoint'] ?? 'N/A';
                $statusCode = $log['status_code'] ?? 'N/A';
                $duration = isset($log['duration_ms']) ? round($log['duration_ms'], 2) . 'ms' : 'N/A';

                return "Endpoint: {$endpoint}, Status: {$statusCode}, Duration: {$duration}";

            default:
                return collect($log)->map(function ($v, $k) {
                    if (is_array($v)) {
                        return "{$k}: [Array]";
                    }

                    return "{$k}: {$v}";
                })->implode(', ');
        }
    }
}
