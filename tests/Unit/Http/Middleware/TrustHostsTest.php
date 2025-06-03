<?php

namespace Tests\Unit\Http\Middleware;

use App\Http\Middleware\TrustHosts;
use Tests\TestCase;

class TrustHostsTest extends TestCase
{
    public function test_hosts_returns_array_with_all_subdomains_of_application_url(): void
    {
        // Arrange
        $middleware = new TrustHosts($this->app);

        // Act
        $result = $middleware->hosts();

        // Assert
        $this->assertIsArray($result);
        $this->assertCount(1, $result);

        // The first item is the result of allSubdomainsOfApplicationUrl(),
        // which should be a string pattern or null depending on the app URL
        $pattern = $result[0];
        $this->assertTrue(
            is_string($pattern) || $pattern === null,
            'Host pattern should be a string or null'
        );
    }
}
