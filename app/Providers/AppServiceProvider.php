<?php

namespace App\Providers;

use App\Services\Humanity\FakeHumanityClient;
use App\Services\Humanity\HumanityClientInterface;
use App\Services\Humanity\HumanityHttpClient;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // The fake is a singleton so a test can seed it and the code under
        // test sees the same instance. The HTTP client is resolved per use so
        // a config change (driver, environment) takes effect immediately.
        $this->app->singleton(FakeHumanityClient::class);

        $this->app->bind(HumanityClientInterface::class, function ($app) {
            return config('humanity.driver') === 'http'
                ? $app->make(HumanityHttpClient::class)
                : $app->make(FakeHumanityClient::class);
        });
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        //
    }
}
