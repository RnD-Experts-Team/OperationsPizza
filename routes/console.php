<?php

use Illuminate\Support\Facades\Schedule;

// Safety net for the transactional outbox: PublishOutboxEventJob is the primary
// path, this sweeps up anything the queue lost or never published.
Schedule::command('outbox:publish-pending')->everyFiveMinutes()->withoutOverlapping();

// Humanity has no webhooks and no `updated_at` on shifts, so polling a rolling
// window is the ONLY way to notice an edit made in Humanity's own UI. This is
// the primary sync mechanism, not a backstop.
//
// Gated by an explicit opt-in flag, OFF by default: the first pass against a
// store imports its live schedule and can soft-delete local rows, so the cron
// is enabled only after manual `--dry-run` diffs have been read clean (see
// the rollout runbook). --force skips the interactive confirm; the store
// allowlist still scopes which stores a pass may touch.
Schedule::command('humanity:reconcile --force')
    ->hourly()
    ->withoutOverlapping()
    ->skip(fn () => !config('humanity.reconcile.cron_enabled'));

// Read-only: links local employees to Humanity records by TCP id (eid /
// username prefix). This is how humanity_employee_id appears now that nothing
// of ours writes Humanity's employee records — TCP's connector owns them.
// Backstop only, and now the third line of defence: SyncEmployeeToHumanityJob
// fires shortly after a TCP id arrives, and ShiftWriteService retries the same
// lookup the moment a shift-write hits an unlinked employee. This still catches
// anyone both of those missed.
Schedule::command('humanity:sync-employees')
    ->dailyAt('03:30')
    ->withoutOverlapping()
    ->skip(fn () => config('humanity.driver') !== 'http');

// Approved leave is a scheduling guard, so it needs to be fresher than daily.
Schedule::command('humanity:sync-leave')
    ->everyThirtyMinutes()
    ->withoutOverlapping()
    ->skip(fn () => config('humanity.driver') !== 'http');

/*
 | NOT scheduled, deliberately: `tcp:sync-catalog` and `humanity:sync-catalog`.
 |
 | Both mirror catalogs — TCP locations/job codes, Humanity locations/positions
 | — that only change when a human does something rare and deliberate: opening a
 | store, defining a job code, adding a position. Neither vendor has a webhook,
 | so polling was the only way to notice, and a daily poll for an event that
 | happens a few times a year is noise that also has to be reasoned about every
 | time the TCP quota is reviewed.
 |
 | Run them by hand as part of onboarding instead:
 |     php artisan tcp:sync-catalog --check
 |     php artisan humanity:sync-catalog --full --auto-map
 */

// TCP Manager+ worked hours -> actual_shifts. Incremental via TCP's updatedOn
// delta filter, so each run costs a handful of calls rather than re-reading the
// fortnight. Hourly rather than every 5 minutes because the daily quota is
// 2500 for the whole service — see config/tcp.php.
Schedule::command('tcp:sync-worksegments')
    ->hourly()
    ->withoutOverlapping()
    ->skip(fn () => config('tcp.driver') !== 'http');
