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

// Shifts a Humanity throttle left owed. Runs often because each shift carries
// its OWN next-attempt time (min(+6h, next midnight)) — this sweep only picks
// up what is actually due, and no-ops during a cooldown. Ordered by shift date
// across all stores, so a throttled publish day drains everyone's Monday
// before anyone's Tuesday.
Schedule::command('humanity:sync-pending-shifts')
    ->everyFiveMinutes()
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
// fortnight.
//
// Every ten minutes, not hourly: TCP is the system of record for worked time
// and the dashboard now writes to it, so a change made in TCP's own UI should
// come back in minutes rather than being up to an hour stale.
//
// The budget maths, since the daily quota is 2500 for the WHOLE service:
//   144 runs/day x ~1 call = ~150/day floor, roughly 6% of the 2250 left after
//   the interactive reserve. Getting there took two fixes — dropping the
//   per-run ping, and bucketing the calculationchanges cache key so all 38
//   stores in a run share ONE feed call instead of one per cursor-minute.
//   Segment fetches sit on top and are driven by real punch volume.
//
// Before shortening this further, run `php artisan tcp:quota` against
// production and look at what is actually being spent — the variable part
// depends on punch distribution, not on arithmetic.
Schedule::command('tcp:sync-worksegments')
    ->everyTenMinutes()
    ->withoutOverlapping()
    ->skip(fn () => config('tcp.driver') !== 'http');

/*
 | The nightly truth pass, and the backstop for two things the delta above
 | structurally cannot do.
 |
 | A segment VOIDED in TCP simply stops being returned, which a delta cannot
 | tell apart from "unchanged" — so without this, an orphan row is paid out
 | forever. And the delta skips a store whenever /calculationchanges does not
 | name any of its people; TCP does not document whether that feed reflects raw
 | punch inserts or only its own recalculations, so re-reading the window
 | unconditionally is what bounds that risk to a day rather than leaving it open.
 |
 | It also reports who is missing. The delta filters on employeeIds, so TCP only
 | returns people we already knew to ask about; somebody working at a store with
 | no local link is invisible to it by construction.
 |
 | Cost: roughly 76-150 calls, plus one per store for the roster — comfortably
 | inside the ~2250/day left after the interactive reserve. Run at 03:00, after
 | the worst of the overnight close and before anyone opens.
 */
Schedule::command('tcp:reconcile-worksegments')
    ->dailyAt('03:00')
    ->withoutOverlapping()
    ->skip(fn () => config('tcp.driver') !== 'http');
