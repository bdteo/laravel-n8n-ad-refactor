<?php

use Monolog\Handler\NullHandler;
use Monolog\Handler\StreamHandler;
use Monolog\Handler\SyslogUdpHandler;
use Monolog\Processor\PsrLogMessageProcessor;

return [

    /*
    |--------------------------------------------------------------------------
    | Default Log Channel
    |--------------------------------------------------------------------------
    |
    | This option defines the default log channel that gets used when writing
    | messages to the logs. The name specified in this option should match
    | one of the channels defined in the "channels" configuration array.
    |
    */

    'default' => env('LOG_CHANNEL', 'stack'),

    /*
    |--------------------------------------------------------------------------
    | Deprecations Log Channel
    |--------------------------------------------------------------------------
    |
    | This option controls the log channel that should be used to log warnings
    | regarding deprecated PHP and library features. This allows you to get
    | your application ready for upcoming major versions of dependencies.
    |
    */

    'deprecations' => [
        'channel' => env('LOG_DEPRECATIONS_CHANNEL', 'null'),
        'trace' => false,
    ],

    /*
    |--------------------------------------------------------------------------
    | Log Channels
    |--------------------------------------------------------------------------
    |
    | Here you may configure the log channels for your application. Out of
    | the box, Laravel uses the Monolog PHP logging library. This gives
    | you a variety of powerful log handlers / formatters to utilize.
    |
    | Available Drivers: "single", "daily", "slack", "syslog",
    |                    "errorlog", "monolog",
    |                    "custom", "stack"
    |
    */

    'channels' => [
        'stack' => [
            'driver' => 'stack',
            'channels' => ['single'],
            'ignore_exceptions' => false,
        ],

        'single' => [
            'driver' => 'single',
            'path' => storage_path('logs/laravel.log'),
            'level' => env('LOG_LEVEL', 'debug'),
            'replace_placeholders' => true,
        ],

        'daily' => [
            'driver' => 'daily',
            'path' => storage_path('logs/laravel.log'),
            'level' => env('LOG_LEVEL', 'debug'),
            'days' => 14,
            'replace_placeholders' => true,
        ],

        'slack' => [
            'driver' => 'slack',
            'url' => env('LOG_SLACK_WEBHOOK_URL'),
            'username' => 'Laravel Log',
            'emoji' => ':boom:',
            'level' => env('LOG_LEVEL', 'critical'),
            'replace_placeholders' => true,
        ],

        'papertrail' => [
            'driver' => 'monolog',
            'level' => env('LOG_LEVEL', 'debug'),
            'handler' => env('LOG_PAPERTRAIL_HANDLER', SyslogUdpHandler::class),
            'handler_with' => [
                'host' => env('PAPERTRAIL_URL'),
                'port' => env('PAPERTRAIL_PORT'),
                'connectionString' => 'tls://'.env('PAPERTRAIL_URL').':'.env('PAPERTRAIL_PORT'),
            ],
            'processors' => [PsrLogMessageProcessor::class],
        ],

        'stderr' => [
            'driver' => 'monolog',
            'level' => env('LOG_LEVEL', 'debug'),
            'handler' => StreamHandler::class,
            'formatter' => env('LOG_STDERR_FORMATTER'),
            'with' => [
                'stream' => 'php://stderr',
            ],
            'processors' => [PsrLogMessageProcessor::class],
        ],

        'syslog' => [
            'driver' => 'syslog',
            'level' => env('LOG_LEVEL', 'debug'),
            'facility' => LOG_USER,
            'replace_placeholders' => true,
        ],

        'errorlog' => [
            'driver' => 'errorlog',
            'level' => env('LOG_LEVEL', 'debug'),
            'replace_placeholders' => true,
        ],

        'null' => [
            'driver' => 'monolog',
            'handler' => NullHandler::class,
        ],

        'emergency' => [
            'path' => storage_path('logs/laravel.log'),
        ],

        /*
        |--------------------------------------------------------------------------
        | Application-Specific Log Channels
        |--------------------------------------------------------------------------
        |
        | These channels are designed for specific application contexts to help
        | with debugging, monitoring, and alerting for different types of events.
        |
        */

        'external_services' => [
            'driver' => 'daily',
            'path' => storage_path('logs/external-services.log'),
            'level' => env('LOG_LEVEL', 'info'),
            'days' => 30,
            'replace_placeholders' => true,
        ],

        'tasks' => [
            'driver' => 'daily',
            'path' => storage_path('logs/tasks.log'),
            'level' => env('LOG_LEVEL', 'info'),
            'days' => 30,
            'replace_placeholders' => true,
        ],

        'validation' => [
            'driver' => 'daily',
            'path' => storage_path('logs/validation.log'),
            'level' => env('LOG_LEVEL', 'info'),
            'days' => 14,
            'replace_placeholders' => true,
        ],

        'security' => [
            'driver' => 'daily',
            'path' => storage_path('logs/security.log'),
            'level' => env('LOG_LEVEL', 'warning'),
            'days' => 90,
            'replace_placeholders' => true,
        ],

        'api' => [
            'driver' => 'daily',
            'path' => storage_path('logs/api.log'),
            'level' => env('LOG_LEVEL', 'info'),
            'days' => 30,
            'replace_placeholders' => true,
        ],

        'jobs' => [
            'driver' => 'daily',
            'path' => storage_path('logs/jobs.log'),
            'level' => env('LOG_LEVEL', 'info'),
            'days' => 30,
            'replace_placeholders' => true,
        ],

        'performance' => [
            'driver' => 'daily',
            'path' => storage_path('logs/performance.log'),
            'level' => env('LOG_LEVEL', 'warning'),
            'days' => 14,
            'replace_placeholders' => true,
        ],

        'audit' => [
            'driver' => 'daily',
            'path' => storage_path('logs/audit.log'),
            'level' => env('AUDIT_LOG_LEVEL', 'info'),
            'days' => 90,
            'replace_placeholders' => true,
        ],

        /*
        |--------------------------------------------------------------------------
        | Error Monitoring Channels
        |--------------------------------------------------------------------------
        |
        | These channels are designed for error monitoring and alerting.
        | Configure external services like Slack, email, or monitoring tools.
        |
        */

        'critical_errors' => [
            'driver' => 'stack',
            'channels' => ['daily', 'slack'],
            'ignore_exceptions' => false,
        ],

        'n8n_errors' => [
            'driver' => 'stack',
            'channels' => ['external_services', 'slack'],
            'ignore_exceptions' => false,
        ],

        'task_failures' => [
            'driver' => 'stack',
            'channels' => ['tasks', 'slack'],
            'ignore_exceptions' => false,
        ],

        /*
        |--------------------------------------------------------------------------
        | Development and Testing Channels
        |--------------------------------------------------------------------------
        |
        | These channels are useful for development and testing environments.
        |
        */

        'debug' => [
            'driver' => 'single',
            'path' => storage_path('logs/debug.log'),
            'level' => 'debug',
            'replace_placeholders' => true,
        ],

        'testing' => [
            'driver' => 'single',
            'path' => storage_path('logs/testing.log'),
            'level' => 'debug',
            'replace_placeholders' => true,
        ],
    ],

];
