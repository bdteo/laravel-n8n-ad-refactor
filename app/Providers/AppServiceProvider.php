<?php

namespace App\Providers;

use App\Contracts\AdScriptTaskServiceInterface;
use App\Contracts\N8nClientInterface;
use App\Services\AdScriptTaskService;
use App\Services\HttpN8nClient;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->bind(AdScriptTaskServiceInterface::class, AdScriptTaskService::class);
        $this->app->bind(N8nClientInterface::class, HttpN8nClient::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        //
    }
}
