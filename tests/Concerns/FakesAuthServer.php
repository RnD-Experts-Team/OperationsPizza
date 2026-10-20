<?php

namespace Tests\Concerns;

use App\Models\User;
use Illuminate\Support\Facades\Http;

/**
 * Stands in for pizzasys. This service has no local login: every request goes
 * through AuthTokenStoreScopeMiddleware, which POSTs the caller's token to the
 * auth server. Faking that one call exercises the real middleware chain rather
 * than bypassing it. Ported, in minimal form, from ToolboxPizza.
 */
trait FakesAuthServer
{
    protected User $authUser;

    /**
     * Registered ONCE per test. Http::fake() MERGES stubs and the first match
     * wins, so re-faking mid-test would silently keep the original response.
     * preventStrayRequests() means any unmatched request fails loudly rather
     * than escaping to the network.
     */
    protected function fakeAuthServer(): User
    {
        config([
            'services.auth_server.base_url' => 'http://auth.test',
            'services.auth_server.verify_path' => '/api/v1/auth/token-verify',
            'services.auth_server.service_name' => 'operations-system',
            'services.auth_server.call_token' => 'service-token',
        ]);

        $this->authUser = User::query()->create(['id' => 9, 'name' => 'Dana', 'email' => 'dana@example.com']);

        Http::preventStrayRequests();

        Http::fake([
            'auth.test/*' => Http::response([
                'active' => true,
                'subject_type' => 'user',
                'user' => ['id' => 9, 'name' => 'Dana', 'email' => 'dana@example.com'],
                'roles' => [],
                'permissions' => [],
                'ext' => ['authorized' => true],
            ]),
        ]);

        return $this->authUser;
    }

    /**
     * @return array<string, string>
     */
    protected function headers(): array
    {
        return ['Authorization' => 'Bearer 1|test-token', 'Accept' => 'application/json'];
    }
}
