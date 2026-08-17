<?php

return [
    /*
     |--------------------------------------------------------------------------
     | TCP Manager+ (TimeClock Plus) — clocking and worked hours
     |--------------------------------------------------------------------------
     | TCP is the system of record for employees, leave and job codes; Humanity
     | owns only the schedule. TCP's own connector syncs the two every 5 min.
     | Docs: https://timeclock-plus.readme.io
     |
     | There is NO TCP sandbox — production is the only environment — so what
     | protects it is driver=fake by default, writes_enabled below, and the
     | EXTERNAL_WRITE_ALLOWED_STORES rollout allowlist (config/external.php).
     */
    'driver' => env('TCP_DRIVER', 'fake'),

    // Master switch for every mutating call (punches, employee writes).
    'writes_enabled' => (bool) env('TCP_WRITES_ENABLED', false),

    // Vendor URLs and scope — fixed by TCP, not deployment configuration.
    'base_url' => 'https://api.tcplusondemand.com/v1',
    'token_url' => 'https://auth.api.tcplusondemand.com/oauth2/token',
    'scope' => 'tcp-openapi/tcpopenapi.write tcp-openapi/tcpopenapi.read',

    /*
     | Proper machine-to-machine auth — client_credentials, no user password.
     | (Humanity's v2 forces the `password` grant instead, which is why that
     | integration needs a service-account login and this one does not.)
     */
    'client_id' => env('TCP_CLIENT_ID'),
    'client_secret' => env('TCP_CLIENT_SECRET'),
    // Sent on every call alongside the bearer token.
    'api_key' => env('TCP_API_KEY'),
    'company_id' => env('TCP_COMPANY_ID', '1'),

    'timeout' => 10,
    'retries' => 2,
    'retry_ms' => 250,

    /*
     | Optional account-wide fallback job code (e.g. 1000 "Regular") for an
     | employee whose position matches none of their store's per-store codes.
     | Validated against the synced tcp_job_codes catalog before use.
     */
    'default_job_code' => env('TCP_DEFAULT_JOB_CODE'),

    /*
     |--------------------------------------------------------------------------
     | Rate limiting — the binding constraint on this integration
     |--------------------------------------------------------------------------
     | TCP allows 60 requests/minute AND 2500 per rolling 24 hours — vendor-
     | imposed numbers, so hardcoded. The daily cap is the one that hurts:
     | ~104/hour for the WHOLE service, across every store and background job.
     |
     | So: sync worksegments with updatedOnStart deltas (never full scans),
     | batch employeeIds 20-at-a-time, page at the documented maximum, and let
     | the throttle refuse rather than collect a 429 and a penalty.
     */
    'rate_limit' => [
        'per_minute' => 60,
        'per_day' => 2500,
        // Stop short of the ceiling so an interactive punch is not starved by
        // a background sync that ran first.
        'reserve_per_day' => 250,
    ],

    // Max ids per request / page size, per the docs. Exceeding these is
    // silently wrong rather than an error, so the client chunks on them.
    'max_ids_per_request' => 20,
    'max_per_page' => 1000,

    /*
     |--------------------------------------------------------------------------
     | Local clock-state cache
     |--------------------------------------------------------------------------
     | Without this, every punch costs TWO requests: one to check whether the
     | employee is already clocked in, then the punch itself. Kept short
     | because an employee can also punch at a physical clock or in TCP's app.
     */
    'clock_state_ttl_seconds' => 900,
    // A punch older than this is treated as a correction and always verified
    // against TCP, because our cache describes "now", not last Tuesday.
    'clock_state_trust_minutes' => 15,

    /*
     | Worked-hours sync window. TCP supports updatedOnStart/updatedOnEnd, a
     | true delta filter, so this only has to cover the gap since the last
     | successful run plus a safety margin for late edits and approvals.
     */
    'timeclock' => [
        'lookback_days' => 14,
        'delta_overlap_minutes' => 30,
        // GET /calculationchanges answers "whose time card changed?" for the
        // WHOLE account in one call; cached so ten stores don't cost ten calls.
        'changes_cache_seconds' => 120,
    ],
];
