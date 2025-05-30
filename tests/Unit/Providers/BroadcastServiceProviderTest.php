<?php

namespace Tests\Unit\Providers;

use App\Providers\BroadcastServiceProvider;
use Illuminate\Support\Facades\Broadcast;
use Tests\TestCase;
use Mockery;

class BroadcastServiceProviderTest extends TestCase
{
    public function test_boot_registers_broadcast_routes(): void
    {
        // Mock the Broadcast facade
        $mock = Mockery::mock('alias:Illuminate\Support\Facades\Broadcast');
        $mock->shouldReceive('routes')
            ->once()
            ->withNoArgs();

        // Create an actual instance of the provider
        $provider = new BroadcastServiceProvider($this->app);

        // Act - call the boot method on the real provider
        // This will now require routes/channels.php, but we've created a dummy file
        // so it won't cause errors
        $provider->boot();

        // Assert - add an explicit assertion to avoid the "risky" test warning
        $this->addToAssertionCount(1); // Verify that Mockery expectations were met
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }
}
