<?php

namespace Tests\Traits;

use Illuminate\Log\LogManager;
use Illuminate\Support\Facades\Log;
use Mockery;

trait DisableLogs
{
    protected function disableLogging(): void
    {
        // Create a null logger that does nothing
        $mockLogger = Mockery::mock(LogManager::class);

        // Define expectations for all log methods
        // Using basic Mockery syntax that works with PHPStan
        $mockLogger->shouldReceive('channel')->andReturn($mockLogger);
        $mockLogger->shouldReceive('info')->andReturn(null);
        $mockLogger->shouldReceive('error')->andReturn(null);
        $mockLogger->shouldReceive('debug')->andReturn(null);
        $mockLogger->shouldReceive('warning')->andReturn(null);
        $mockLogger->shouldReceive('critical')->andReturn(null);

        // Replace the Log facade with our null logger
        Log::swap($mockLogger);
    }
}
