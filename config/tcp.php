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

    /*
     | Break punches, OFF by default.
     |
     | A shift here is continuous expected work — you work it or you are asked
     | to leave — so a break is not something the business schedules, and
     | offering the action implied otherwise. The code is kept rather than
     | deleted because a store or a state may yet mandate a meal break.
     |
     | This gates only OUR endpoints. The SYNC must always handle multi-segment
     | shifts regardless, because anyone can punch out and back in at a physical
     | clock and TCP will split the segment whether we offer the button or not.
     */
    'breaks_enabled' => (bool) env('TCP_BREAKS_ENABLED', false),

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
     | Clock state
     |--------------------------------------------------------------------------
     | clock_state_ttl_seconds and open_segment_ttl_seconds are gone. Both were
     | cache windows over `employee_clock_states`, and both existed only because
     | the sync discarded open segments and left that table as the single place
     | the current state lived. Open segments now persist in tcp_work_segments,
     | so "is this person on the clock" is an indexed local query — nothing to
     | cache, and no staleness window to reason about.
     |
     | This one stays: a punch older than this is a CORRECTION, and our local
     | picture describes "now", not last Tuesday. Those are always verified
     | against TCP before we act on them.
     */
    'clock_state_trust_minutes' => 15,

    /*
     | Worked-hours sync window. TCP supports updatedOnStart/updatedOnEnd, a
     | true delta filter, so this only has to cover the gap since the last
     | successful run plus a safety margin for late edits and approvals.
     */
    'timeclock' => [
        'lookback_days' => 14,
        'delta_overlap_minutes' => 30,

        /*
         | GET /calculationchanges answers "whose time card changed?" for the
         | WHOLE account in one call, and the sync runs once per store — so the
         | result is cached and shared.
         |
         | The cache key carries the `since` it was fetched with, and `since`
         | comes from a PER-STORE cursor, so 38 stores whose cursors landed on
         | different minutes used to mean several calls per run instead of one.
         | Flooring `since` to a bucket puts every store in a run on the same
         | key. Rounding DOWN is the safe direction: it widens the window, so
         | the feed returns a superset of who changed, and each store still
         | narrows to its own roster afterwards.
         |
         | The TTL must stay BELOW the schedule interval, or a run would serve
         | the previous run's answer and miss changes for a whole cycle.
         */
        'changes_bucket_minutes' => 5,
        'changes_cache_seconds' => 240,

        /*
         | The date floor when running a DELTA. The 14 days above is the payroll
         | window; this is deliberately much wider, because `startDate` bounds
         | the query independently of `updatedOnStart` — so a timecard dated
         | three weeks ago and corrected this morning was being excluded by the
         | date filter even though the delta filter matched it.
         |
         | Costs nothing: the delta still bounds the result set to what actually
         | changed. It only stops the date window from silently dropping rows.
         */
        'delta_lookback_days' => 90,
    ],

    /*
     |--------------------------------------------------------------------------
     | Rolling segments up into shifts
     |--------------------------------------------------------------------------
     | TCP stores segments; a manager reviews shifts. Punching out and back in
     | closes one segment and opens another, so one shift routinely arrives as
     | several and something has to decide which of them belong together.
     */
    'rollup' => [
        /*
         | Segments for one employee closer together than this form ONE shift.
         |
         | Not a break threshold — breaks are not part of the operating model.
         | It is there to glue punch noise back together, while leaving a real
         | split shift ("come in this morning, then again this evening") as the
         | two separate shifts it actually is.
         */
        'gap_minutes' => 60,

        /*
         | When somebody forgets to clock out, an open segment would otherwise
         | accrue forever and show a forty-hour shift in the grid. Past this,
         | the roll-up stops counting minutes and raises needs_attention.
         |
         | It never invents an end time: TCP owns the times, so only a real
         | punch or a correction made in TCP can close the segment.
         */
        'max_shift_hours' => 16,
    ],
];
