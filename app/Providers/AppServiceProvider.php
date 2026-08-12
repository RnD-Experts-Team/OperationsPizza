<?php

namespace App\Providers;

use App\Services\Humanity\FakeHumanityClient;
use App\Services\Humanity\HumanityClientInterface;
use App\Services\Humanity\HumanityHttpClient;
use App\Services\Tcp\FakeTcpClient;
use App\Services\Tcp\TcpClientInterface;
use App\Services\Tcp\TcpHttpClient;
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

        // TCP Manager+ (TimeClock Plus) — clocking and worked hours. Separate
        // system from Humanity, separate credentials, separate rate limits.
        $this->app->singleton(FakeTcpClient::class);

        $this->app->bind(TcpClientInterface::class, function ($app) {
            return config('tcp.driver') === 'http'
                ? $app->make(TcpHttpClient::class)
                : $app->make(FakeTcpClient::class);
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
