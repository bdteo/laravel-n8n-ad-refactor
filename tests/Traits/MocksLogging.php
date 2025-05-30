<?php

namespace Tests\Traits;

use Illuminate\Support\Facades\Log;
use Mockery;

trait MocksLogging
{
    /**
     * Set up basic log spying without specific expectations.
     * This allows individual test methods to set their own expectations.
     */
    protected function spyLogs(): void
    {
        Log::spy();
    }

    /**
     * Set up the Log facade to ignore all calls to it.
     * This is useful when you don't care about logs in a specific test.
     */
    protected function ignoreLogs(): void
    {
        Log::shouldReceive('emergency')->byDefault()->andReturnNull();
        Log::shouldReceive('alert')->byDefault()->andReturnNull();
        Log::shouldReceive('critical')->byDefault()->andReturnNull();
        Log::shouldReceive('error')->byDefault()->andReturnNull();
        Log::shouldReceive('warning')->byDefault()->andReturnNull();
        Log::shouldReceive('notice')->byDefault()->andReturnNull();
        Log::shouldReceive('info')->byDefault()->andReturnNull();
        Log::shouldReceive('debug')->byDefault()->andReturnNull();
        Log::shouldReceive('log')->byDefault()->andReturnNull();
    }

    /**
     * Expect a specific debug log message.
     *
     * @param string $message The message to expect
     * @param array|string|null $contextKeys Keys to expect in the context array, or single key as string
     * @param int $times Number of times the message is expected (default: 1)
     */
    protected function expectDebugLog(string $message, $contextKeys = null, int $times = 1): void
    {
        $this->expectLog('debug', $message, $contextKeys, $times);
    }

    /**
     * Expect a specific info log message.
     *
     * @param string $message The message to expect
     * @param array|string|null $contextKeys Keys to expect in the context array, or single key as string
     * @param int $times Number of times the message is expected (default: 1)
     */
    protected function expectInfoLog(string $message, $contextKeys = null, int $times = 1): void
    {
        $this->expectLog('info', $message, $contextKeys, $times);
    }

    /**
     * Expect a specific warning log message.
     *
     * @param string $message The message to expect
     * @param array|string|null $contextKeys Keys to expect in the context array, or single key as string
     * @param int $times Number of times the message is expected (default: 1)
     */
    protected function expectWarningLog(string $message, $contextKeys = null, int $times = 1): void
    {
        $this->expectLog('warning', $message, $contextKeys, $times);
    }

    /**
     * Expect a specific error log message.
     *
     * @param string $message The message to expect
     * @param array|string|null $contextKeys Keys to expect in the context array, or single key as string
     * @param int $times Number of times the message is expected (default: 1)
     */
    protected function expectErrorLog(string $message, $contextKeys = null, int $times = 1): void
    {
        $this->expectLog('error', $message, $contextKeys, $times);
    }

    /**
     * Expect a specific log message of any level.
     *
     * @param string $level The log level (debug, info, warning, error, etc.)
     * @param string $message The message to expect
     * @param array|string|null $contextKeys Keys to expect in the context array, or single key as string
     * @param int $times Number of times the message is expected (default: 1)
     */
    protected function expectLog(string $level, string $message, $contextKeys = null, int $times = 1): void
    {
        // If contextKeys is null, just check for the message
        if ($contextKeys === null) {
            Log::shouldReceive($level)
                ->with($message, Mockery::any())
                ->times($times)
                ->andReturnNull();

            return;
        }

        // For a single key as string
        if (is_string($contextKeys)) {
            Log::shouldReceive($level)
                ->withArgs(function ($actualMessage, $context) use ($message, $contextKeys) {
                    return $actualMessage === $message && isset($context[$contextKeys]);
                })
                ->times($times)
                ->andReturnNull();

            return;
        }

        // For an array of keys
        if (is_array($contextKeys)) {
            Log::shouldReceive($level)
                ->withArgs(function ($actualMessage, $context) use ($message, $contextKeys) {
                    if ($actualMessage !== $message) {
                        return false;
                    }

                    foreach ($contextKeys as $key) {
                        if (! isset($context[$key])) {
                            return false;
                        }
                    }

                    return true;
                })
                ->times($times)
                ->andReturnNull();

            return;
        }
    }

    /**
     * Verify that a specific log message at any level was never called.
     *
     * @param string $level The log level
     * @param string|null $message The message (or null to match any message)
     */
    protected function expectNoLog(string $level, ?string $message = null): void
    {
        if ($message === null) {
            Log::shouldReceive($level)->never();
        } else {
            Log::shouldReceive($level)->with($message, Mockery::any())->never();
        }
    }

    /**
     * Assert that a specific log message was recorded.
     * This is used after the test has run to check that logs were made.
     *
     * @param string $level The log level
     * @param string $message The message to check for
     * @param array|string|null $contextKeys Optional context keys to check for
     */
    protected function assertLogContains(string $level, string $message, $contextKeys = null): void
    {
        // Basic assertion that the log was called with the message
        Log::shouldHaveReceived($level)->with($message, Mockery::any());

        // If context keys were provided, do more specific checks
        if ($contextKeys !== null) {
            if (is_string($contextKeys)) {
                // For a single key
                Log::shouldHaveReceived($level)
                    ->with($message, Mockery::hasKey($contextKeys));
            } elseif (is_array($contextKeys)) {
                // For multiple keys
                $matcher = function ($context) use ($contextKeys) {
                    foreach ($contextKeys as $key) {
                        if (! isset($context[$key])) {
                            return false;
                        }
                    }

                    return true;
                };

                Log::shouldHaveReceived($level)
                    ->with($message, Mockery::on($matcher));
            }
        }
    }
}
