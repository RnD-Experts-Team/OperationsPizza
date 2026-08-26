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
// Backstop only: ShiftWriteService::resolveHumanityIdLive() already tries a
// single targeted eid lookup the moment a shift-write hits an unlinked
// employee, so most links happen well before this ever runs. This still
// catches anyone who was never scheduled (so the live path never fired).
Schedule::command('humanity:sync-employees')
    ->dailyAt('03:30')
    ->withoutOverlapping()
    ->skip(fn () => config('humanity.driver') !== 'http');

// TCP locations + job codes (~6 quota calls). Keeps the store bindings and
// the clockable-code catalog fresh; new stores surface as unmatched rows.
Schedule::command('tcp:sync-catalog')
    ->dailyAt('03:15')
    ->withoutOverlapping()
    ->skip(fn () => config('tcp.driver') !== 'http');

// Approved leave is a scheduling guard, so it needs to be fresher than daily.
Schedule::command('humanity:sync-leave')
    ->everyThirtyMinutes()
    ->withoutOverlapping()
    ->skip(fn () => config('humanity.driver') !== 'http');

// Locations and positions are the only Humanity resources with a real delta
// filter, so this is cheap.
Schedule::command('humanity:sync-catalog')
    ->dailyAt('03:00')
    ->withoutOverlapping()
    ->skip(fn () => config('humanity.driver') !== 'http');

// TCP Manager+ worked hours -> actual_shifts. Incremental via TCP's updatedOn
// delta filter, so each run costs a handful of calls rather than re-reading the
// fortnight. Hourly rather than every 5 minutes because the daily quota is
// 2500 for the whole service — see config/tcp.php.
Schedule::command('tcp:sync-worksegments')
    ->hourly()
    ->withoutOverlapping()
    ->skip(fn () => config('tcp.driver') !== 'http');
