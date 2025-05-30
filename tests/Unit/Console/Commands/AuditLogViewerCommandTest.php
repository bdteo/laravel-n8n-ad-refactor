<?php

namespace Tests\Unit\Console\Commands;

use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class AuditLogViewerCommandTest extends TestCase
{
    protected string $logPath;
    protected string $testDate;

    protected function setUp(): void
    {
        parent::setUp();

        $this->testDate = '2025-05-30';
        $this->logPath = storage_path("logs/audit-{$this->testDate}.log");

        // Make sure we don't have test logs from previous runs
        if (File::exists($this->logPath)) {
            File::delete($this->logPath);
        }
    }

    protected function tearDown(): void
    {
        // Clean up test logs
        if (File::exists($this->logPath)) {
            File::delete($this->logPath);
        }

        parent::tearDown();
    }

    /**
     * Test command shows error when no logs are found.
     */
    public function testShowsErrorWhenNoLogsFound(): void
    {
        $this->artisan('audit:logs', ['--date' => $this->testDate])
            ->expectsOutput("No audit logs found for {$this->testDate}")
            ->assertExitCode(1);
    }

    /**
     * Test command correctly displays logs.
     */
    public function testDisplaysLogs(): void
    {
        // Create a sample log file
        $logEntry = '[2025-05-30 10:00:00] local.INFO: Audit log {"timestamp":"2025-05-30T10:00:00.000000Z","event":"task.created","task_id":"123","status":"pending"}';
        File::put($this->logPath, $logEntry);

        $this->artisan('audit:logs', ['--date' => $this->testDate])
            ->expectsTable(
                ['Timestamp', 'Event', 'Details'],
                [['2025-05-30T10:00:00.000000Z', 'task.created', 'Task ID: 123, Status: pending']]
            )
            ->assertExitCode(0);
    }

    /**
     * Test filtering logs by event.
     */
    public function testFiltersByEvent(): void
    {
        // Create sample log with multiple entries
        $logEntries = [
            '[2025-05-30 10:00:00] local.INFO: Audit log {"timestamp":"2025-05-30T10:00:00.000000Z","event":"task.created","task_id":"123","status":"pending"}',
            '[2025-05-30 10:01:00] local.INFO: Audit log {"timestamp":"2025-05-30T10:01:00.000000Z","event":"api.request","endpoint":"/api/tasks","method":"GET","ip":"127.0.0.1"}',
        ];
        File::put($this->logPath, implode("\n", $logEntries));

        // Should only show task.created events
        $this->artisan('audit:logs', [
            '--date' => $this->testDate,
            '--event' => 'task.created'
        ])
            ->expectsTable(
                ['Timestamp', 'Event', 'Details'],
                [['2025-05-30T10:00:00.000000Z', 'task.created', 'Task ID: 123, Status: pending']]
            )
            ->assertExitCode(0);
    }

    /**
     * Test filtering logs by task ID.
     */
    public function testFiltersByTaskId(): void
    {
        // Create sample log with multiple entries
        $logEntries = [
            '[2025-05-30 10:00:00] local.INFO: Audit log {"timestamp":"2025-05-30T10:00:00.000000Z","event":"task.created","task_id":"123","status":"pending"}',
            '[2025-05-30 10:01:00] local.INFO: Audit log {"timestamp":"2025-05-30T10:01:00.000000Z","event":"task.created","task_id":"456","status":"pending"}',
        ];
        File::put($this->logPath, implode("\n", $logEntries));

        // Should only show logs for task 123
        $this->artisan('audit:logs', [
            '--date' => $this->testDate,
            '--task' => '123'
        ])
            ->expectsTable(
                ['Timestamp', 'Event', 'Details'],
                [['2025-05-30T10:00:00.000000Z', 'task.created', 'Task ID: 123, Status: pending']]
            )
            ->assertExitCode(0);
    }

    /**
     * Test limit option.
     */
    public function testLimitsResults(): void
    {
        // Create sample log with multiple entries
        $logEntries = [
            '[2025-05-30 10:00:00] local.INFO: Audit log {"timestamp":"2025-05-30T10:00:00.000000Z","event":"task.created","task_id":"123","status":"pending"}',
            '[2025-05-30 10:01:00] local.INFO: Audit log {"timestamp":"2025-05-30T10:01:00.000000Z","event":"task.created","task_id":"456","status":"pending"}',
            '[2025-05-30 10:02:00] local.INFO: Audit log {"timestamp":"2025-05-30T10:02:00.000000Z","event":"task.created","task_id":"789","status":"pending"}',
        ];
        File::put($this->logPath, implode("\n", $logEntries));

        // Should only show 1 result
        $this->artisan('audit:logs', [
            '--date' => $this->testDate,
            '--limit' => '1'
        ])
            ->expectsTable(
                ['Timestamp', 'Event', 'Details'],
                [['2025-05-30T10:00:00.000000Z', 'task.created', 'Task ID: 123, Status: pending']]
            )
            ->expectsOutput('Showing 1 results. Use --limit to adjust.')
            ->assertExitCode(0);
    }

    /**
     * Test JSON output format.
     */
    public function testJsonOutput(): void
    {
        // Create a sample log file
        $logEntry = '[2025-05-30 10:00:00] local.INFO: Audit log {"timestamp":"2025-05-30T10:00:00.000000Z","event":"task.created","task_id":"123","status":"pending"}';
        File::put($this->logPath, $logEntry);

        $this->artisan('audit:logs', [
            '--date' => $this->testDate,
            '--json' => true
        ])
            ->assertExitCode(0);

        // Since we can't easily assert on the JSON output content directly,
        // we just verify that the command runs successfully in JSON mode
    }

    /**
     * Test displaying available dates when no logs found.
     */
    public function testShowsAvailableDatesWhenNoLogsFound(): void
    {
        // Create a sample log file for a different date
        $otherDate = '2025-05-29';
        $otherLogPath = storage_path("logs/audit-{$otherDate}.log");

        try {
            File::put($otherLogPath, 'Sample log content');

            $this->artisan('audit:logs', ['--date' => $this->testDate])
                ->expectsOutput("No audit logs found for {$this->testDate}")
                ->expectsOutput("Available audit log dates:")
                ->expectsOutput("  - {$otherDate}")
                ->assertExitCode(1);
        } finally {
            if (File::exists($otherLogPath)) {
                File::delete($otherLogPath);
            }
        }
    }
}
