<?php

return [
    /*
     |--------------------------------------------------------------------------
     | Driver
     |--------------------------------------------------------------------------
     | 'http' talks to Humanity; 'fake' uses an in-memory double. Tests and
     | local development should use 'fake'.
     */
    'driver' => env('HUMANITY_DRIVER', 'fake'),

    /*
     |--------------------------------------------------------------------------
     | Environment guard
     |--------------------------------------------------------------------------
     | Humanity production is LIVE — real managers and employees depend on it.
     | There is deliberately no default: HumanityHttpClient refuses to start
     | without an explicit value, so nobody points a dev box at production by
     | forgetting a variable.
     */
    'environment' => env('HUMANITY_ENV'),

    /*
     | Master switch for every mutating call. Off by default, and it must stay
     | off until humanity:backfill-employee-ids has matched the existing live
     | roster — otherwise upsertStaff() creates a duplicate staff record for
     | every employee that was set up by hand in Humanity.
     */
    'writes_enabled' => (bool) env('HUMANITY_WRITES_ENABLED', false),

    /*
     |--------------------------------------------------------------------------
     | API
     |--------------------------------------------------------------------------
     | v2 REST. Note there are three Humanity API surfaces; this is the current
     | documented one. Do not mix in api.humanity.com/v1 (the SDK's target) or
     | the legacy v1 RPC API.
     */
    'base_url' => env('HUMANITY_BASE_URL', 'https://www.humanity.com/api/v2'),
    'token_url' => env('HUMANITY_TOKEN_URL', 'https://www.humanity.com/oauth2/token.php'),

    /*
     | v2 has no scopes (the token response returns scope: null). Authorization
     | is the authenticating USER's role: 2=Manager, 3=Supervisor, 4=Scheduler,
     | 5=Employee, 6=Accountant, 7=Schedule Viewer. POST/DELETE /shifts require
     | level 3, so the service account must be Manager or Supervisor.
     |
     | client_credentials is not supported — only `password` and `refresh_token`.
     */
    'client_id' => env('HUMANITY_CLIENT_ID'),
    'client_secret' => env('HUMANITY_CLIENT_SECRET'),
    'username' => env('HUMANITY_USERNAME'),
    'password' => env('HUMANITY_PASSWORD'),
    'redirect_uri' => env('HUMANITY_REDIRECT_URI'),

    'timeout' => (int) env('HUMANITY_TIMEOUT', 10),
    'retries' => (int) env('HUMANITY_RETRIES', 2),
    'retry_ms' => (int) env('HUMANITY_RETRY_MS', 250),

    /*
     | Humanity's rate limit is real but unpublished — application status 91
     | fires reliably on bulk shift creation. Throttle conservatively.
     */
    'requests_per_second' => (float) env('HUMANITY_RPS', 3),
    'throttle_backoff_seconds' => (int) env('HUMANITY_THROTTLE_BACKOFF', 30),

    /*
     | Reconciler window. Humanity offers no webhooks and no `updated_at` on
     | shifts, so polling a rolling date range is the only way to notice a
     | change made in Humanity's own UI.
     */
    'reconcile' => [
        'days_back' => (int) env('HUMANITY_RECONCILE_DAYS_BACK', 7),
        'days_forward' => (int) env('HUMANITY_RECONCILE_DAYS_FORWARD', 21),
        // Grace period before a local shift missing upstream is soft-deleted,
        // so a shift created seconds ago isn't reaped by an in-flight pass.
        'missing_grace_seconds' => (int) env('HUMANITY_RECONCILE_GRACE', 120),
        'lock_seconds' => (int) env('HUMANITY_RECONCILE_LOCK', 300),
    ],
];
