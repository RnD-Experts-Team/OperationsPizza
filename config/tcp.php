<?php

return [
    /*
     |--------------------------------------------------------------------------
     | TCP Manager+ (TimeClock Plus)
     |--------------------------------------------------------------------------
     | The clocking system. TimeClock Plus is the system of record for employee
     | profiles, leave, skills, locations/departments and job codes; Humanity
     | owns only the schedule. TCP's own connector syncs the two every 5 minutes.
     |
     | Docs: https://timeclock-plus.readme.io
     */
    'driver' => env('TCP_DRIVER', 'fake'),

    // No default, for the same reason as Humanity: production is live.
    'environment' => env('TCP_ENV'),

    'writes_enabled' => (bool) env('TCP_WRITES_ENABLED', false),

    'base_url' => env('TCP_BASE_URL', 'https://api.tcplusondemand.com/v1'),
    'token_url' => env('TCP_TOKEN_URL', 'https://auth.api.tcplusondemand.com/oauth2/token'),

    /*
     | Proper machine-to-machine auth — client_credentials, no user password.
     | (Humanity's v2 forces the `password` grant instead, which is why that
     | integration needs a service-account login and this one does not.)
     */
    'client_id' => env('TCP_CLIENT_ID'),
    'client_secret' => env('TCP_CLIENT_SECRET'),
    'scope' => env('TCP_SCOPE', 'tcp-openapi/tcpopenapi.write tcp-openapi/tcpopenapi.read'),

    // Sent on every call alongside the bearer token.
    'api_key' => env('TCP_API_KEY'),
    'company_id' => env('TCP_COMPANY_ID', '1'),

    'timeout' => (int) env('TCP_TIMEOUT', 10),
    'retries' => (int) env('TCP_RETRIES', 2),
    'retry_ms' => (int) env('TCP_RETRY_MS', 250),

    /*
     |--------------------------------------------------------------------------
     | Rate limiting — the binding constraint on this integration
     |--------------------------------------------------------------------------
     | TCP allows 60 requests/minute AND 2500 per rolling 24 hours. The daily
     | cap is the one that hurts: it works out at ~104/hour for the WHOLE
     | service, across every store and every background job.
     |
     | For comparison, a single copy-week against Humanity is ~70 calls. The
     | same budget spent against TCP would be a third of the day's allowance.
     |
     | So: sync worksegments with updatedOnStart deltas (never full scans),
     | batch employeeIds 20-at-a-time, page at perPage=1000, and let the
     | throttle refuse rather than collect a 429 and a penalty.
     */
    'rate_limit' => [
        'per_minute' => (int) env('TCP_RATE_PER_MINUTE', 60),
        'per_day' => (int) env('TCP_RATE_PER_DAY', 2500),
        // Stop short of the ceiling so an interactive request or an urgent
        // punch is not starved by a background sync that ran first.
        'reserve_per_day' => (int) env('TCP_RATE_RESERVE_PER_DAY', 250),
    ],

    /*
     | Max ids per request, per the docs. Exceeding these is silently wrong
     | rather than an error, so the client chunks on them.
     */
    'max_ids_per_request' => 20,
    'max_per_page' => 1000,

    /*
     |--------------------------------------------------------------------------
     | Local clock-state cache
     |--------------------------------------------------------------------------
     | Without this, every punch costs TWO requests: one to check whether the
     | employee is already clocked in, then the punch itself. A lunch rush is
     | exactly when doubling the spend hurts most.
     |
     | We open and close these segments ourselves, so our own record is
     | authoritative for the common case. Kept short because an employee can
     | also punch at a physical clock or in TCP's app — an expired entry costs
     | one lookup, a stale "clocked in" wrongly blocks a real shift.
     */
    'clock_state_ttl_seconds' => (int) env('TCP_CLOCK_STATE_TTL', 900),

    // A punch older than this is treated as a correction and always verified
    // against TCP, because our cache describes "now", not last Tuesday.
    'clock_state_trust_minutes' => (int) env('TCP_CLOCK_STATE_TRUST_MINUTES', 15),

    /*
     | Worked-hours sync window. TCP supports updatedOnStart/updatedOnEnd, a
     | true delta filter, so this only has to cover the gap since the last
     | successful run plus a safety margin for late edits and approvals.
     */
    'timeclock' => [
        'lookback_days' => (int) env('TCP_TIMECLOCK_LOOKBACK_DAYS', 14),
        'delta_overlap_minutes' => (int) env('TCP_TIMECLOCK_OVERLAP_MINUTES', 30),

        /*
         | GET /v1/calculationchanges answers "whose time card changed?" for the
         | WHOLE account in one call — the closest thing TCP has to a webhook.
         | Syncing ten stores must not cost ten identical calls, so the answer is
         | cached for a short window and shared across the run.
         */
        'changes_cache_seconds' => (int) env('TCP_CHANGES_CACHE_SECONDS', 120),
    ],
];
