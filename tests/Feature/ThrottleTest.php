<?php

namespace Tests\Feature;

use App\Http\Middleware\ObservableThrottleRequests;
use App\Models\Store;
use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\RateLimiter as CacheRateLimiter;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Cache\Repository;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;
use Mockery;
use Tests\Concerns\FakesAuthServer;
use Tests\TestCase;

/**
 * ObservableThrottleRequests and the limits in AppServiceProvider.
 *
 * The real limits are far too big to walk past in a test, so most cases swap
 * in a tiny limiter under the same name; the last few check the real numbers.
 * Ported from ToolboxPizza.
 */
class ThrottleTest extends TestCase
{
    use FakesAuthServer, RefreshDatabase;

    // A route with only the general limit: poll routes drop it.
    private const PROBE = '/api/v1/stores/03759-00001/schedule-templates';

    protected function setUp(): void
    {
        parent::setUp();

        $this->fakeAuthServer();

        Store::query()->create(['id' => 1, 'store_number' => '03759-00001', 'name' => 'Downtown', 'timezone' => 'America/Chicago']);
    }

    private function tinyApiLimiter(int $real, int $target = 100): void
    {
        RateLimiter::for('api', fn (Request $request) => [
            Limit::perMinute($real)->by('t:'.$request->bearerToken()),
            Limit::perMinute($target)->by('target:t:'.$request->bearerToken()),
        ]);
    }

    public function test_passing_a_target_logs_once_and_blocks_nothing(): void
    {
        Log::spy();
        Log::shouldReceive('channel')->with('throttle')->andReturnSelf();
        $this->tinyApiLimiter(real: 100, target: 2);

        foreach (range(1, 5) as $_) {
            $this->getJson(self::PROBE, $this->headers())->assertOk();
        }

        Log::shouldHaveReceived('warning')
            ->with('throttle.usage', Mockery::on(fn ($ctx) => $ctx['limiter'] === 'api' && $ctx['max'] === 2))
            ->once();
    }

    public function test_record_only_mode_lets_a_breach_through_and_logs_it_once(): void
    {
        config(['app.throttle_enforce' => false]);
        Log::spy();
        Log::shouldReceive('channel')->with('throttle')->andReturnSelf();
        $this->tinyApiLimiter(real: 2);

        $this->getJson(self::PROBE, $this->headers())->assertOk();
        $this->getJson(self::PROBE, $this->headers())->assertOk();

        foreach (range(1, 3) as $_) {
            $this->getJson(self::PROBE, $this->headers())
                ->assertOk()
                ->assertHeader('X-RateLimit-Remaining', '0');
        }

        Log::shouldHaveReceived('warning')
            ->with('throttle.breach', Mockery::on(fn ($ctx) => $ctx['enforced'] === false))
            ->once();
    }

    public function test_enforce_mode_answers_429_with_retry_after(): void
    {
        config(['app.throttle_enforce' => true]);
        $this->tinyApiLimiter(real: 2);

        $this->getJson(self::PROBE, $this->headers())->assertOk();
        $this->getJson(self::PROBE, $this->headers())->assertOk();

        $this->getJson(self::PROBE, $this->headers())
            ->assertStatus(429)
            ->assertHeader('Retry-After');
    }

    public function test_a_throttled_request_never_reaches_pizzasys(): void
    {
        config(['app.throttle_enforce' => true]);
        $this->tinyApiLimiter(real: 1);

        $this->getJson(self::PROBE, $this->headers())->assertOk();
        $sent = count(Http::recorded());

        $this->getJson(self::PROBE, $this->headers())->assertStatus(429);

        $this->assertCount($sent, Http::recorded());
    }

    public function test_a_route_tier_also_runs_before_pizzasys(): void
    {
        config(['app.throttle_enforce' => true]);
        RateLimiter::for('api-poll', fn (Request $request) => Limit::perMinute(1)->by('t:'.$request->bearerToken()));

        $this->getJson('/api/v1/health', $this->headers())->assertOk();
        $sent = count(Http::recorded());

        $this->getJson('/api/v1/health', $this->headers())->assertStatus(429);

        $this->assertCount($sent, Http::recorded());
    }

    public function test_two_tokens_do_not_share_a_bucket(): void
    {
        config(['app.throttle_enforce' => true]);
        $this->tinyApiLimiter(real: 1);

        $this->getJson(self::PROBE, $this->headers())->assertOk();
        $this->getJson(self::PROBE, $this->headers())->assertStatus(429);

        $this->getJson(self::PROBE, ['Authorization' => 'Bearer 2|other-token', 'Accept' => 'application/json'])
            ->assertOk();
    }

    public function test_an_unreachable_limiter_store_serves_the_request_unthrottled(): void
    {
        config(['app.throttle_enforce' => true]);

        $down = new class extends ArrayStore
        {
            public function get($key)
            {
                throw new \RuntimeException('redis down');
            }

            public function increment($key, $value = 1)
            {
                throw new \RuntimeException('redis down');
            }

            public function put($key, $value, $seconds)
            {
                throw new \RuntimeException('redis down');
            }
        };

        $limiter = new CacheRateLimiter(new Repository($down));
        $limiter->for('api', fn (Request $request) => Limit::perMinute(1)->by('t:'.$request->bearerToken()));
        $this->app->instance(CacheRateLimiter::class, $limiter);

        Log::spy();
        Log::shouldReceive('channel')->with('throttle')->andReturnSelf();

        $this->getJson(self::PROBE, $this->headers())->assertOk();
        $this->getJson(self::PROBE, $this->headers())->assertOk();

        Log::shouldHaveReceived('error')
            ->with('throttle.store_unavailable', Mockery::on(fn ($ctx) => $ctx['limiter'] === 'api'))
            ->twice();
    }

    public function test_the_real_api_limits_split_reads_from_writes(): void
    {
        $limits = fn (string $method) => collect(RateLimiter::limiter('api')(
            Request::create(self::PROBE, $method, server: ['HTTP_AUTHORIZATION' => 'Bearer 1|test-token'])
        ));

        $real = fn ($all) => $all->reject(fn (Limit $l) => str_starts_with($l->key, 'target:'))->pluck('maxAttempts')->all();
        $target = fn ($all) => $all->filter(fn (Limit $l) => str_starts_with($l->key, 'target:'))->pluck('maxAttempts')->all();

        $this->assertSame([600, 5000], $real($limits('GET')));
        $this->assertSame([120, 1200], $target($limits('GET')));
        $this->assertSame([300, 5000], $real($limits('POST')));
        $this->assertSame([60, 1200], $target($limits('POST')));
    }

    public function test_keys_hash_the_token_and_fall_back_to_the_ip(): void
    {
        $key = fn (array $server) => RateLimiter::limiter('api-poll')(Request::create('/x', 'POST', server: $server))[0]->key;

        $a = $key(['HTTP_AUTHORIZATION' => 'Bearer 1|aaa']);

        $this->assertSame($a, $key(['HTTP_AUTHORIZATION' => 'Bearer 1|aaa']));
        $this->assertNotSame($a, $key(['HTTP_AUTHORIZATION' => 'Bearer 1|bbb']));
        $this->assertStringNotContainsString('aaa', $a);
        $this->assertSame('ip:10.1.2.3', $key(['REMOTE_ADDR' => '10.1.2.3']));
    }

    /**
     * The limits a limiter returns for a real route, so {storeId} resolves.
     */
    private function limitsFor(string $limiter, string $method, string $uri)
    {
        $request = Request::create($uri, $method, server: ['HTTP_AUTHORIZATION' => 'Bearer 1|aaa']);
        $route = Route::getRoutes()->match($request);
        $request->setRouteResolver(fn () => $route);

        return collect(RateLimiter::limiter($limiter)($request));
    }

    public function test_the_store_limiters_key_on_token_and_store_with_the_plan_numbers(): void
    {
        $fanout = $this->limitsFor('external-fanout', 'POST', '/api/v1/stores/03759-00001/schedule/bulk/clear-week');
        $clocking = $this->limitsFor('clocking', 'POST', '/api/v1/stores/03759-00001/employees/501/clock-in');

        foreach ([$fanout, $clocking] as $limits) {
            $this->assertStringEndsWith('|03759-00001', $limits[0]->key);
            $this->assertStringNotContainsString('aaa', $limits[0]->key);
        }

        $this->assertNotSame(
            $fanout[0]->key,
            $this->limitsFor('external-fanout', 'POST', '/api/v1/stores/03759-00002/schedule/bulk/clear-week')[0]->key,
        );

        $real = fn ($all) => $all->reject(fn (Limit $l) => str_starts_with($l->key, 'target:'))->pluck('maxAttempts')->all();
        $target = fn ($all) => $all->filter(fn (Limit $l) => str_starts_with($l->key, 'target:'))->pluck('maxAttempts')->all();

        $this->assertSame([10], $real($fanout));
        $this->assertSame([3, 20], $target($fanout));
        $this->assertSame([30], $real($clocking));
        $this->assertSame([10], $target($clocking));
    }

    public function test_the_eleventh_fanout_at_one_store_is_refused_before_pizzasys_and_another_store_is_not(): void
    {
        config(['app.throttle_enforce' => true]);
        $bulk = fn (string $store) => $this->postJson("/api/v1/stores/{$store}/schedule/bulk/clear-week", [], $this->headers());

        foreach (range(1, 10) as $_) {
            $this->assertNotSame(429, $bulk('03759-00001')->status());
        }

        $sent = count(Http::recorded());
        $bulk('03759-00001')->assertStatus(429);
        $this->assertCount($sent, Http::recorded());

        $this->assertNotSame(429, $bulk('03759-00002')->status());
    }

    public function test_the_thirty_first_punch_at_one_store_is_refused(): void
    {
        config(['app.throttle_enforce' => true]);
        $punch = fn () => $this->postJson('/api/v1/stores/03759-00001/employees/501/clock-in', [], $this->headers());

        foreach (range(1, 30) as $_) {
            $this->assertNotSame(429, $punch()->status());
        }

        $punch()->assertStatus(429);
    }

    public function test_a_poll_route_is_not_capped_by_the_general_limit(): void
    {
        config(['app.throttle_enforce' => true]);
        $this->tinyApiLimiter(real: 1);

        foreach (range(1, 3) as $_) {
            $this->getJson('/api/v1/health', $this->headers())->assertOk();
        }

        $this->getJson(self::PROBE, $this->headers())->assertOk();
        $this->getJson(self::PROBE, $this->headers())->assertStatus(429);
    }

    public function test_the_poll_ip_ceiling_is_keyed_apart_from_a_tokenless_caller(): void
    {
        $keys = collect(RateLimiter::limiter('api-poll')(Request::create('/x', 'GET', server: ['REMOTE_ADDR' => '10.1.2.3'])))
            ->reject(fn (Limit $l) => str_starts_with($l->key, 'target:'));

        $this->assertSame([1200, 5000], $keys->pluck('maxAttempts')->all());
        $this->assertSame(['ip:10.1.2.3', 'ceiling:ip:10.1.2.3'], $keys->pluck('key')->all());
    }

    public function test_each_tier_is_on_the_routes_the_plan_puts_it_on(): void
    {
        $named = fn (string $middleware) => collect(Route::getRoutes()->getRoutes())
            ->filter(fn ($route) => in_array($middleware, $route->gatherMiddleware(), true))
            ->map(fn ($route) => $route->getName())
            ->sort()->values()->all();

        $this->assertSame([
            'api.v1.bulk.apply-template', 'api.v1.bulk.clear-week', 'api.v1.bulk.copy-week',
            'api.v1.bulk.create-shifts', 'api.v1.bulk.retry', 'api.v1.employees.humanity-sync',
        ], $named('throttle:external-fanout'));

        // break-start/end carry it too, but are only registered with
        // tcp.breaks_enabled on, which it is not here.
        $this->assertSame(['api.v1.clock.in', 'api.v1.clock.out'], $named('throttle:clocking'));

        $this->assertSame([
            'api.v1.bulk.show', 'api.v1.clock.on-the-clock', 'api.v1.clock.status',
            'api.v1.employees.sync-status', 'api.v1.health',
        ], $named('throttle:api-poll'));

        $this->assertSame(['api.v1.schedule.insights', 'api.v1.schedule.week'], $named('throttle:api-heavy'));

        // Poll routes swap the general limit for their own tier.
        $general = collect(Route::getRoutes()->getRoutes())
            ->filter(fn ($route) => in_array(ObservableThrottleRequests::class.':api', app('router')->gatherRouteMiddleware($route), true))
            ->map(fn ($route) => $route->getName())
            ->sort()->values()->all();

        $this->assertSame(array_values(array_diff($named('auth.token.store'), $named('throttle:api-poll'))), $general);
    }
}
