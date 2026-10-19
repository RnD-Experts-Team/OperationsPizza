<?php

namespace App\Providers;

use App\Services\Humanity\FakeHumanityClient;
use App\Services\Humanity\HumanityClientInterface;
use App\Services\Humanity\HumanityHttpClient;
use App\Services\Tcp\FakeTcpClient;
use App\Services\Tcp\TcpClientInterface;
use App\Services\Tcp\TcpHttpClient;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
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
        $this->configureRateLimiting();
    }

    /**
     * Rate limits, applied by ObservableThrottleRequests (the `throttle` alias).
     *
     * Every throttle runs BEFORE auth.token.store - the framework sorts it ahead
     * of the api group's SubstituteBindings - so a throttled request never costs
     * a pizzasys round-trip. That also means no user id exists yet: limits are
     * keyed on a hash of the bearer token, or the IP when there is none, and
     * {storeId} is the raw store_number from the path.
     *
     * Phase 1: the real limits are generous and the `target:` limits are the
     * numbers we expect to end on - they only log when passed. Phase 2 sets the
     * real limits from those logs and drops the targets.
     */
    private function configureRateLimiting(): void
    {
        // Every route: per token, reads and writes counted apart, plus a
        // per-IP ceiling for floods of made-up tokens (a store's staff share
        // one NAT address, hence the size).
        RateLimiter::for('api', function (Request $request) {
            $token = self::tokenKey($request);
            $kind = $request->isMethodSafe() ? 'r:' : 'w:';
            $ip = 'ip:'.$request->ip();

            return [
                Limit::perMinute($request->isMethodSafe() ? 600 : 300)->by($kind.$token),
                Limit::perMinute(5000)->by($ip),
                Limit::perMinute($request->isMethodSafe() ? 120 : 60)->by('target:'.$kind.$token),
                Limit::perMinute(1200)->by('target:'.$ip),
            ];
        });

        // Poll routes drop `throttle:api` (withoutMiddleware) - its read limit
        // would cap them - so they carry the per-IP ceiling themselves. Keyed
        // apart from tokenKey(), which is also `ip:` when there is no bearer.
        RateLimiter::for('api-poll', fn (Request $request) => [
            Limit::perMinute(1200)->by(self::tokenKey($request)),
            Limit::perMinute(5000)->by('ceiling:ip:'.$request->ip()),
            Limit::perMinute(300)->by('target:'.self::tokenKey($request)),
        ]);

        RateLimiter::for('api-heavy', fn (Request $request) => [
            Limit::perMinute(60)->by(self::tokenKey($request)),
            Limit::perMinute(20)->by('target:m:'.self::tokenKey($request)),
            Limit::perHour(200)->by('target:h:'.self::tokenKey($request)),
        ]);

        // Bulk writes and the employee sync: each one is a run of serial
        // Humanity calls against its undocumented rate limit. Per store, so a
        // manager covering two stores does not share one bucket.
        RateLimiter::for('external-fanout', fn (Request $request) => [
            Limit::perMinute(10)->by(self::storeKey($request)),
            Limit::perMinute(3)->by('target:m:'.self::storeKey($request)),
            Limit::perHour(20)->by('target:h:'.self::storeKey($request)),
        ]);

        // Punches: every one is a call to TCP Manager+.
        RateLimiter::for('clocking', fn (Request $request) => [
            Limit::perMinute(30)->by(self::storeKey($request)),
            Limit::perMinute(10)->by('target:'.self::storeKey($request)),
        ]);
    }

    /**
     * The caller's bearer token, hashed - never stored or logged raw - or the
     * IP when there is no token.
     */
    private static function tokenKey(Request $request): string
    {
        $token = (string) $request->bearerToken();

        return $token !== '' ? 't:'.hash('sha256', $token) : 'ip:'.$request->ip();
    }

    /**
     * tokenKey() plus the store in the path.
     */
    private static function storeKey(Request $request): string
    {
        return self::tokenKey($request).'|'.$request->route('storeId');
    }
}
