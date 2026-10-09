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

    /*
     | Self-imposed pacing, enforced account-wide by HumanityRateLimiter.
     |
     | Humanity's real limit is unpublished — status 91 says only "try again
     | later", with no number, window or headers, and the question has sat
     | unanswered on their developer forum for years. So this figure is a
     | guess, and env-tunable precisely because it is: if 91s start appearing
     | in the logs, lower it without a deploy. 0 disables pacing entirely
     | (what the test suite does — there is no real API on the other end).
     */
    'requests_per_second' => (int) env('HUMANITY_REQUESTS_PER_SECOND', 3),

    // How long the whole account stays paused after a 91. Short on purpose:
    // being wrong short costs one refused call, being wrong long stalls a
    // schedule nobody can publish.
    'throttle_backoff_seconds' => (int) env('HUMANITY_THROTTLE_BACKOFF_SECONDS', 30),

    /*
     | Retry cadence for a shift a throttle left owed (see
     | PendingShiftSyncService): wait this many hours, or until the next day
     | starts, whichever comes first — then give up after max_attempts and park
     | it for a human.
     |
     | Env-tunable because the tradeoff is real and only production can settle
     | it: a shift is invisible to employees in Humanity until it syncs, so six
     | hours is cautious. Lower it if the logs show throttles clearing quickly.
     */
    'sync_retry_hours' => (int) env('HUMANITY_SYNC_RETRY_HOURS', 6),
    'sync_max_attempts' => (int) env('HUMANITY_SYNC_MAX_ATTEMPTS', 4),

    /*
     | How long to wait after an employee's TCP id arrives before looking up
     | their Humanity record. TCP's own connector carries the employee across
     | on a ~5 minute cycle, so asking immediately would usually just miss.
     | A miss is harmless — the shift-write lookup and the nightly
     | humanity:sync-employees both still cover it.
     */
    'employee_link_delay_minutes' => 20,

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
