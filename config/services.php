<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'key' => env('POSTMARK_API_KEY'),
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | pizzasys auth server
    |--------------------------------------------------------------------------
    | AuthTokenStoreScopeMiddleware POSTs every request's bearer token here and
    | requires both `active` and `ext.authorized`. `call_token` is the raw token
    | of this service's `service_clients` row in pizzasys — that row and the
    | matching `auth_rules` for service_name must exist or every request 403s.
    |
    | `service_name` is ONE string doing three jobs in pizzasys, and they must
    | all agree or nothing works:
    |   1. it is sent as the `service` field of the verify request;
    |   2. ServiceCallerAuthenticator looks it up as `service_clients.name`;
    |   3. AuthorizationResolver matches it against `auth_rules.service`.
    | Changing it here means changing the seeded rows in pizzasys too.
    */
    'auth_server' => [
        'base_url' => env('AUTH_SERVER_BASE_URL', 'http://auth-service.local'),
        'verify_path' => env('AUTH_SERVER_VERIFY_PATH', '/api/v1/auth/token/verify'),
        'service_name' => env('AUTH_SERVER_SERVICE_NAME', 'Operations'),
        'call_token' => env('AUTH_SERVER_CALL_TOKEN', ''),
        'timeout' => (int) env('AUTH_SERVER_TIMEOUT', 3),
        'retries' => (int) env('AUTH_SERVER_RETRIES', 1),
        'retry_ms' => (int) env('AUTH_SERVER_RETRY_MS', 100),
        'cache_ttl' => (int) env('AUTH_SERVER_CACHE_TTL', 30),
    ],

];
