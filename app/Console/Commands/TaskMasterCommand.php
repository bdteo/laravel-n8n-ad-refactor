<?php

namespace App\Console\Commands;

use Exception;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Throwable;

class TaskMasterCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'app:task-master {action=list : Action to perform (list, create, update, view, delete)}
                            {id? : Task ID for view, update, or delete actions}
                            {--title= : Task title for create or update}
                            {--description= : Task description for create or update}
                            {--details= : Task details for create or update}
                            {--status=pending : Task status (pending, in-progress, done)}
                            {--priority=medium : Task priority (low, medium, high, critical)}
                            {--dependencies=* : Task dependencies (comma-separated IDs)}
                            {--test-strategy= : Testing approach for this task}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Manage development tasks for the project';

    /**
     * The tasks directory path.
     *
     * @var string
     */
    protected string $tasksDir;

    /**
     * The tasks JSON file path.
     *
     * @var string
     */
    protected string $tasksJsonPath;

    /**
     * Constructor.
     */
    public function __construct()
    {
        parent::__construct();
        $this->tasksDir = base_path('tasks');
        $this->tasksJsonPath = $this->tasksDir . '/tasks.json';
    }

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        try {
            $action = $this->argument('action');

            if (! File::exists($this->tasksDir)) {
                File::makeDirectory($this->tasksDir, 0755, true);
            }

            if (! File::exists($this->tasksJsonPath)) {
                File::put($this->tasksJsonPath, json_encode(['tasks' => []], JSON_PRETTY_PRINT));
            }

            return match ($action) {
                'list' => $this->listTasks(),
                'create' => $this->createTask(),
                'update' => $this->updateTask(),
                'view' => $this->viewTask(),
                'delete' => $this->deleteTask(),
                default => $this->error("Unknown action: $action"),
            };
        } catch (Throwable $e) {
            $this->error("Error: {$e->getMessage()}");

            return 1;
        }
    }

    /**
     * List all tasks.
     */
    protected function listTasks(): int
    {
        $tasks = $this->loadTasks();

        $headers = ['ID', 'Title', 'Status', 'Priority', 'Dependencies'];
        $rows = [];

        foreach ($tasks as $task) {
            $rows[] = [
                $task['id'],
                Str::limit($task['title'], 40),
                $task['status'] ?? 'pending',
                $task['priority'] ?? 'medium',
                implode(', ', $task['dependencies'] ?? []),
            ];
        }

        $this->table($headers, $rows);

        return 0;
    }

    /**
     * Create a new task.
     */
    protected function createTask(): int
    {
        $tasks = $this->loadTasks();

        // Find the next available ID
        $nextId = 1;
        if (! empty($tasks)) {
            $ids = array_column($tasks, 'id');
            $nextId = max($ids) + 1;
        }

        $title = $this->option('title') ?? $this->ask('Enter task title');
        $description = $this->option('description') ?? $this->ask('Enter task description');
        $details = $this->option('details') ?? $this->ask('Enter task details (optional)');
        $status = $this->option('status');
        $priority = $this->option('priority');

        // Handle dependencies
        $dependencies = $this->option('dependencies');
        if (is_array($dependencies) && count($dependencies) === 1 && str_contains($dependencies[0], ',')) {
            $dependencies = explode(',', $dependencies[0]);
        }
        $dependencies = array_map('intval', $dependencies);

        $testStrategy = $this->option('test-strategy') ?? $this->ask('Enter test strategy (optional)');

        $task = [
            'id' => $nextId,
            'title' => $title,
            'description' => $description,
            'details' => $details,
            'testStrategy' => $testStrategy,
            'status' => $status,
            'priority' => $priority,
            'dependencies' => $dependencies,
            'subtasks' => [],
        ];

        $tasks[] = $task;
        $this->saveTasks($tasks);

        // Also save as individual task file
        $this->saveTaskFile($task);

        $this->info("Task $nextId created successfully!");

        return 0;
    }

    /**
     * Update an existing task.
     */
    protected function updateTask(): int
    {
        $id = (int) $this->argument('id');
        if (! $id) {
            $this->error('Task ID is required for update action');

            return 1;
        }

        $tasks = $this->loadTasks();
        $taskIndex = array_search($id, array_column($tasks, 'id'));

        if ($taskIndex === false) {
            $this->error("Task with ID $id not found");

            return 1;
        }

        $task = $tasks[$taskIndex];

        if ($this->option('title')) {
            $task['title'] = $this->option('title');
        }

        if ($this->option('description')) {
            $task['description'] = $this->option('description');
        }

        if ($this->option('details')) {
            $task['details'] = $this->option('details');
        }

        if ($this->option('status')) {
            $task['status'] = $this->option('status');
        }

        if ($this->option('priority')) {
            $task['priority'] = $this->option('priority');
        }

        if ($this->option('test-strategy')) {
            $task['testStrategy'] = $this->option('test-strategy');
        }

        // Handle dependencies
        $dependencies = $this->option('dependencies');
        if (! empty($dependencies)) {
            if (is_array($dependencies) && count($dependencies) === 1 && str_contains($dependencies[0], ',')) {
                $dependencies = explode(',', $dependencies[0]);
            }
            $task['dependencies'] = array_map('intval', $dependencies);
        }

        $tasks[$taskIndex] = $task;
        $this->saveTasks($tasks);

        // Also update individual task file
        $this->saveTaskFile($task);

        $this->info("Task $id updated successfully!");

        return 0;
    }

    /**
     * View a specific task.
     */
    protected function viewTask(): int
    {
        $id = (int) $this->argument('id');
        if (! $id) {
            $this->error('Task ID is required for view action');

            return 1;
        }

        $tasks = $this->loadTasks();
        $taskIndex = array_search($id, array_column($tasks, 'id'));

        if ($taskIndex === false) {
            $this->error("Task with ID $id not found");

            return 1;
        }

        $task = $tasks[$taskIndex];

        $this->line("\n<fg=blue;options=bold>Task ID:</> {$task['id']}");
        $this->line("<fg=blue;options=bold>Title:</> {$task['title']}");
        $this->line("<fg=blue;options=bold>Description:</> {$task['description']}");
        $this->line("<fg=blue;options=bold>Status:</> {$task['status']}");
        $this->line("<fg=blue;options=bold>Priority:</> {$task['priority']}");
        if (! empty($task['dependencies'])) {
            $this->line("<fg=blue;options=bold>Dependencies:</> " . implode(', ', $task['dependencies']));
        }

        if (! empty($task['details'])) {
            $this->line("\n<fg=blue;options=bold>Details:</>\n{$task['details']}");
        }

        if (! empty($task['testStrategy'])) {
            $this->line("\n<fg=blue;options=bold>Test Strategy:</>\n{$task['testStrategy']}");
        }

        if (! empty($task['subtasks'])) {
            $this->line("\n<fg=blue;options=bold>Subtasks:</>");
            foreach ($task['subtasks'] as $subtask) {
                $this->line("  - {$subtask['title']} ({$subtask['status']})");
            }
        }

        return 0;
    }

    /**
     * Delete a task.
     */
    protected function deleteTask(): int
    {
        $id = (int) $this->argument('id');
        if (! $id) {
            $this->error('Task ID is required for delete action');

            return 1;
        }

        $tasks = $this->loadTasks();
        $taskIndex = array_search($id, array_column($tasks, 'id'));

        if ($taskIndex === false) {
            $this->error("Task with ID $id not found");

            return 1;
        }

        if (! $this->confirm("Are you sure you want to delete task $id?")) {
            $this->info('Deletion cancelled');

            return 0;
        }

        // Remove from array
        array_splice($tasks, $taskIndex, 1);
        $this->saveTasks($tasks);

        // Also delete individual task file
        $taskFilePath = $this->tasksDir . "/task_" . str_pad((string) $id, 3, '0', STR_PAD_LEFT) . ".txt";
        if (File::exists($taskFilePath)) {
            File::delete($taskFilePath);
        }

        $this->info("Task $id deleted successfully!");

        return 0;
    }

    /**
     * Load tasks from the tasks.json file.
     */
    protected function loadTasks(): array
    {
        $content = File::get($this->tasksJsonPath);
        $data = json_decode($content, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new Exception("Error parsing tasks.json: " . json_last_error_msg());
        }

        return $data['tasks'] ?? [];
    }

    /**
     * Save tasks to the tasks.json file.
     */
    protected function saveTasks(array $tasks): void
    {
        // Create backup of existing file
        if (File::exists($this->tasksJsonPath)) {
            File::copy(
                $this->tasksJsonPath,
                $this->tasksDir . '/tasks.json.bak'
            );
        }

        // Save sorted by ID
        usort($tasks, fn ($a, $b) => $a['id'] <=> $b['id']);

        File::put(
            $this->tasksJsonPath,
            json_encode(['tasks' => $tasks], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
        );
    }

    /**
     * Save task to individual file.
     */
    protected function saveTaskFile(array $task): void
    {
        $filename = $this->tasksDir . "/task_" . str_pad($task['id'], 3, '0', STR_PAD_LEFT) . ".txt";

        $content = "# Task ID: {$task['id']}\n";
        $content .= "# Title: {$task['title']}\n";
        $content .= "# Status: {$task['status']}\n";

        if (! empty($task['dependencies'])) {
            $content .= "# Dependencies: " . implode(', ', $task['dependencies']) . "\n";
        } else {
            $content .= "# Dependencies: None\n";
        }

        $content .= "# Priority: {$task['priority']}\n";
        $content .= "# Description: {$task['description']}\n";

        if (! empty($task['details'])) {
            $content .= "# Details:\n{$task['details']}\n";
        }

        if (! empty($task['testStrategy'])) {
            $content .= "\n# Test Strategy:\n{$task['testStrategy']}\n";
        }

        File::put($filename, $content);
    }
}
