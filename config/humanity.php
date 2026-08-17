<?php

return [
    /*
     |--------------------------------------------------------------------------
     | Humanity (schedule source of truth)
     |--------------------------------------------------------------------------
     | 'http' talks to the real account; 'fake' (the default) uses an in-memory
     | double. There is NO Humanity sandbox — production is the only account —
     | so what protects it is this default, writes_enabled below, and the
     | EXTERNAL_WRITE_ALLOWED_STORES rollout allowlist (config/external.php).
     */
    'driver' => env('HUMANITY_DRIVER', 'fake'),

    // Master switch for every mutating call (shifts, assignments). The client
    // throws on any write while false.
    'writes_enabled' => (bool) env('HUMANITY_WRITES_ENABLED', false),

    /*
     | v2 REST. Three Humanity API surfaces exist; this is the current
     | documented one. Do not mix in api.humanity.com/v1 (the SDK's target) or
     | the legacy v1 RPC API. Vendor URLs — not deployment configuration.
     */
    'base_url' => 'https://www.humanity.com/api/v2',
    'token_url' => 'https://www.humanity.com/oauth2/token.php',

    /*
     | v2 has no scopes (the token response returns scope: null). Authorization
     | is the authenticating USER's role: 2=Manager, 3=Supervisor, 4=Scheduler,
     | 5=Employee, 6=Accountant, 7=Schedule Viewer. POST/DELETE /shifts require
     | Manager or Supervisor, so the service account must be one of those.
     |
     | client_credentials is not supported — only `password` and `refresh_token`,
     | which is why a real user's credentials appear here at all.
     */
    'client_id' => env('HUMANITY_CLIENT_ID'),
    'client_secret' => env('HUMANITY_CLIENT_SECRET'),
    'username' => env('HUMANITY_USERNAME'),
    'password' => env('HUMANITY_PASSWORD'),
    'redirect_uri' => '',

    'timeout' => 10,
    'retries' => 2,
    'retry_ms' => 250,

    // Self-imposed pacing for bulk fan-out; Humanity's real limit is unpublished
    // (status 91 = throttled). Backoff seconds honoured when 91 carries no hint.
    'requests_per_second' => 3,
    'throttle_backoff_seconds' => 30,

    'reconcile' => [
        // OFF by default. The hourly reconcile cron is an explicit opt-in,
        // flipped on only after manual --dry-run diffs have been read clean —
        // a first pass imports a store's live schedule and can soft-delete
        // local rows. (This replaces the old HUMANITY_ENV=sandbox gate, whose
        // label inverted safety: it enabled the cron and skipped the confirm.)
        'cron_enabled' => (bool) env('HUMANITY_RECONCILE_CRON', false),

        'days_back' => 7,
        'days_forward' => 21,
        // Grace period before a local shift missing upstream is soft-deleted,
        // so a shift created seconds ago isn't reaped by an in-flight pass.
        'missing_grace_seconds' => 120,
        'lock_seconds' => 300,
    ],
];
