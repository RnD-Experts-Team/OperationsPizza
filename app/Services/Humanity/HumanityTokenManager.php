<?php

namespace App\Services\Humanity;

use App\Services\Humanity\Exceptions\HumanityAuthException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/**
 * OAuth2 against Humanity's token endpoint.
 *
 * The only grants documented for v2 are `password` and `refresh_token` — there
 * is no client_credentials, so the integration authenticates AS a real user.
 * That user's role is the entire authorization model (v2 returns scope: null),
 * and it must be Manager (2) or Supervisor (3) for shift writes to be allowed.
 *
 * Practical consequence: an expired service-account password silently breaks
 * all scheduling, so credential rotation is an operational runbook item.
 */
class HumanityTokenManager
{
    private const CACHE_KEY = 'humanity:oauth';
    private const LOCK_KEY = 'humanity:oauth:lock';

    /** Refresh this many seconds before the hour is up. */
    private const EXPIRY_SKEW = 60;

    public function accessToken(): string
    {
        $cached = Cache::get(self::CACHE_KEY);

        if (is_array($cached) && isset($cached['access_token'])) {
            return $cached['access_token'];
        }

        // Serialise the refresh so a fleet of queue workers doesn't stampede
        // the token endpoint (and risk tripping the flagged-key statuses).
        return Cache::lock(self::LOCK_KEY, 20)->block(15, function () {
            $cached = Cache::get(self::CACHE_KEY);

            if (is_array($cached) && isset($cached['access_token'])) {
                return $cached['access_token'];
            }

            return $this->requestToken()['access_token'];
        });
    }

    /** Drop the cached token so the next call re-authenticates. Used on a 401. */
    public function forget(): void
    {
        Cache::forget(self::CACHE_KEY);
    }

    private function requestToken(): array
    {
        $config = config('humanity');

        foreach (['client_id', 'client_secret', 'username', 'password'] as $key) {
            if (empty($config[$key])) {
                throw new HumanityAuthException("Humanity config missing: humanity.{$key}");
            }
        }

        $refreshToken = Cache::get(self::CACHE_KEY . ':refresh');

        $body = $refreshToken
            ? [
                'client_id' => $config['client_id'],
                'client_secret' => $config['client_secret'],
                'grant_type' => 'refresh_token',
                'refresh_token' => $refreshToken,
            ]
            : [
                'client_id' => $config['client_id'],
                'client_secret' => $config['client_secret'],
                'grant_type' => 'password',
                'username' => $config['username'],
                'password' => $config['password'],
                'redirect_uri' => $config['redirect_uri'] ?? '',
            ];

        $response = Http::asForm()
            ->timeout((int) $config['timeout'])
            ->post($config['token_url'], $body);

        $data = $response->json();

        if (!$response->successful() || !is_array($data) || empty($data['access_token'])) {
            // A refresh_token can expire independently; fall back to `password`
            // once rather than locking the integration out until someone notices.
            if ($refreshToken) {
                Cache::forget(self::CACHE_KEY . ':refresh');

                return $this->requestToken();
            }

            throw new HumanityAuthException(
                'Humanity token request failed: ' . $response->body(),
                httpStatus: $response->status(),
            );
        }

        $ttl = max(60, (int) ($data['expires_in'] ?? 3600) - self::EXPIRY_SKEW);

        Cache::put(self::CACHE_KEY, ['access_token' => $data['access_token']], $ttl);

        if (!empty($data['refresh_token'])) {
            // Outlives the access token deliberately.
            Cache::put(self::CACHE_KEY . ':refresh', $data['refresh_token'], now()->addDays(25));
        }

        return $data;
    }
}
