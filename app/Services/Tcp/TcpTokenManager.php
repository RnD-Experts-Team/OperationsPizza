<?php

namespace App\Services\Tcp;

use App\Services\Tcp\Exceptions\TcpAuthException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/**
 * OAuth2 client_credentials against TCP.
 *
 * Genuinely machine-to-machine: an app id and secret, no user account. That
 * makes it materially better operated than the Humanity integration, which
 * v2 forces onto the `password` grant — there is no human password here to
 * expire, no 2FA to trip over, and nothing that breaks when someone leaves.
 */
class TcpTokenManager
{
    private const CACHE_KEY = 'tcp:oauth:token';
    private const LOCK_KEY = 'tcp:oauth:lock';

    /** Refresh a minute early; TCP tokens last 3600s. */
    private const EXPIRY_SKEW = 60;

    public function accessToken(): string
    {
        $cached = Cache::get(self::CACHE_KEY);

        if (is_string($cached) && $cached !== '') {
            return $cached;
        }

        // Serialise refreshes so a fleet of queue workers doesn't spend several
        // of a very small daily quota fetching the same token.
        return Cache::lock(self::LOCK_KEY, 20)->block(15, function () {
            $cached = Cache::get(self::CACHE_KEY);

            if (is_string($cached) && $cached !== '') {
                return $cached;
            }

            return $this->requestToken();
        });
    }

    public function forget(): void
    {
        Cache::forget(self::CACHE_KEY);
    }

    private function requestToken(): string
    {
        $config = (array) config('tcp');

        foreach (['client_id', 'client_secret'] as $key) {
            if (empty($config[$key])) {
                throw new TcpAuthException("TCP config missing: tcp.{$key}");
            }
        }

        $response = Http::asForm()
            ->timeout((int) ($config['timeout'] ?? 10))
            ->post($config['token_url'], [
                'grant_type' => 'client_credentials',
                'scope' => $config['scope'],
                'client_id' => $config['client_id'],
                'client_secret' => $config['client_secret'],
            ]);

        $data = $response->json();

        if (!$response->successful() || !is_array($data) || empty($data['access_token'])) {
            throw new TcpAuthException(
                'TCP token request failed: ' . $response->body(),
                httpStatus: $response->status(),
            );
        }

        Cache::put(
            self::CACHE_KEY,
            $data['access_token'],
            max(60, (int) ($data['expires_in'] ?? 3600) - self::EXPIRY_SKEW)
        );

        return $data['access_token'];
    }
}
