<?php

use App\Http\Controllers\Api\V1\ActualShiftController;
use App\Http\Controllers\Api\V1\AvailabilityController;
use App\Http\Controllers\Api\V1\ClockController;
use App\Http\Controllers\Api\V1\EmployeeSyncController;
use App\Http\Controllers\Api\V1\HealthController;
use App\Http\Controllers\Api\V1\PublishedScheduleController;
use App\Http\Controllers\Api\V1\ScheduleBulkController;
use App\Http\Controllers\Api\V1\ScheduleController;
use App\Http\Controllers\Api\V1\ScheduleTemplateController;
use App\Http\Controllers\Api\V1\ShiftController;
use App\Http\Controllers\Api\V1\TimeOffController;
use Illuminate\Support\Facades\Route;

/*
| Every route is store-scoped and verified against pizzasys by
| AuthTokenStoreScopeMiddleware. {storeId} is the store_number STRING
| ("03759-00001"), matching HiringPizza — not the numeric pk NATS events use.
|
| Updates are POST, not PUT/PATCH: the house convention across these services.
| Bulk endpoints return 202 + a batch id; they fan out into dozens of Humanity
| calls against an undocumented rate limit and cannot be synchronous.
*/

Route::prefix('v1')->middleware('auth.token.store')->group(function (): void {
    Route::get('health', [HealthController::class, 'show'])->name('api.v1.health');

    Route::prefix('stores/{storeId}')->group(function (): void {
        // ---- the one call that boots the whole grid -------------------------
        Route::get('schedule/week', [ScheduleController::class, 'week'])->name('api.v1.schedule.week');
        Route::get('schedule/employees', [ScheduleController::class, 'employees'])->name('api.v1.schedule.employees');
        Route::get('schedule/departments', [ScheduleController::class, 'departments'])->name('api.v1.schedule.departments');

        // ---- planned shifts (write-through to Humanity) ---------------------
        Route::post('shifts', [ShiftController::class, 'store'])->name('api.v1.shifts.store');
        Route::get('shifts/{shiftId}', [ShiftController::class, 'show'])->whereNumber('shiftId')->name('api.v1.shifts.show');
        Route::post('shifts/{shiftId}', [ShiftController::class, 'update'])->whereNumber('shiftId')->name('api.v1.shifts.update');
        Route::delete('shifts/{shiftId}', [ShiftController::class, 'destroy'])->whereNumber('shiftId')->name('api.v1.shifts.destroy');

        // ---- actual shifts (local only, never pushed to Humanity) -----------
        Route::post('actual-shifts', [ActualShiftController::class, 'store'])->name('api.v1.actual-shifts.store');
        Route::post('actual-shifts/{actualId}', [ActualShiftController::class, 'update'])->whereNumber('actualId')->name('api.v1.actual-shifts.update');
        Route::post('actual-shifts/{actualId}/absent', [ActualShiftController::class, 'absent'])->whereNumber('actualId')->name('api.v1.actual-shifts.absent');

        /*
         | Grouping, asserted by a manager.
         |
         | Which punches form one shift is decided by a gap threshold, and a
         | threshold is a default rather than a verdict. These two let a person
         | overrule it, and the decision is pinned so the next sync leaves it
         | alone.
         |
         | Neither touches TCP. Grouping is ours — TCP stores segments and has no
         | concept of a shift over them — so there is nothing to tell it, and the
         | hours are unchanged either way: the same punches, counted once.
         */
        Route::post('actual-shifts/{actualId}/merge', [ActualShiftController::class, 'merge'])->whereNumber('actualId')->name('api.v1.actual-shifts.merge');
        Route::post('actual-shifts/{actualId}/split', [ActualShiftController::class, 'split'])->whereNumber('actualId')->name('api.v1.actual-shifts.split');
        Route::delete('actual-shifts/{actualId}', [ActualShiftController::class, 'destroy'])->whereNumber('actualId')->name('api.v1.actual-shifts.destroy');
        Route::post('shift-assignments/{assignmentId}/confirm-actual', [ActualShiftController::class, 'confirmPlanned'])
            ->whereNumber('assignmentId')->name('api.v1.actual-shifts.confirm');
        // The mirror of confirm-actual: "they never turned up". Addresses the
        // ASSIGNMENT, so a planned shift with no actual yet can be marked in
        // one call — the client used to create a "worked as planned" entry and
        // then flip it, which momentarily recorded the opposite of the truth.
        Route::post('shift-assignments/{assignmentId}/absent-actual', [ActualShiftController::class, 'absentPlanned'])
            ->whereNumber('assignmentId')->name('api.v1.actual-shifts.absent-planned');

        // ---- availability & time off ----------------------------------------
        Route::get('availability', [AvailabilityController::class, 'index'])->name('api.v1.availability.index');
        Route::post('availability-overrides', [AvailabilityController::class, 'store'])->name('api.v1.availability.store');
        // Accepts a raw numeric id or the "override-{id}-{dayIndex}" display id
        // the week grid hands back verbatim from the availability projection.
        Route::delete('availability-overrides/{overrideId}', [AvailabilityController::class, 'destroy'])
            ->where('overrideId', '\d+|override-\d+-\d+')->name('api.v1.availability.destroy');

        Route::get('time-off', [TimeOffController::class, 'index'])->name('api.v1.time-off.index');
        Route::post('time-off', [TimeOffController::class, 'store'])->name('api.v1.time-off.store');
        Route::delete('time-off/{timeOffId}', [TimeOffController::class, 'destroy'])->whereNumber('timeOffId')->name('api.v1.time-off.destroy');

        // ---- templates -------------------------------------------------------
        Route::get('schedule-templates', [ScheduleTemplateController::class, 'index'])->name('api.v1.templates.index');
        Route::post('schedule-templates', [ScheduleTemplateController::class, 'store'])->name('api.v1.templates.store');
        Route::get('schedule-templates/{templateId}', [ScheduleTemplateController::class, 'show'])->whereNumber('templateId')->name('api.v1.templates.show');
        Route::post('schedule-templates/{templateId}', [ScheduleTemplateController::class, 'update'])->whereNumber('templateId')->name('api.v1.templates.update');
        Route::delete('schedule-templates/{templateId}', [ScheduleTemplateController::class, 'destroy'])->whereNumber('templateId')->name('api.v1.templates.destroy');

        // ---- published weeks (multipart screenshot upload) --------------------
        Route::get('published-schedules', [PublishedScheduleController::class, 'index'])->name('api.v1.published.index');
        Route::post('published-schedules', [PublishedScheduleController::class, 'store'])->name('api.v1.published.store');
        Route::get('published-schedules/{publishedId}', [PublishedScheduleController::class, 'show'])->whereNumber('publishedId')->name('api.v1.published.show');
        Route::delete('published-schedules/{publishedId}', [PublishedScheduleController::class, 'destroy'])->whereNumber('publishedId')->name('api.v1.published.destroy');

        // ---- bulk (always async: 202 + batch id) -----------------------------
        Route::post('schedule/bulk/create-shifts', [ScheduleBulkController::class, 'createShifts'])->name('api.v1.bulk.create-shifts');
        Route::post('schedule/bulk/copy-week', [ScheduleBulkController::class, 'copyWeek'])->name('api.v1.bulk.copy-week');
        Route::post('schedule/bulk/apply-template', [ScheduleBulkController::class, 'applyTemplate'])->name('api.v1.bulk.apply-template');
        Route::post('schedule/bulk/clear-week', [ScheduleBulkController::class, 'clearWeek'])->name('api.v1.bulk.clear-week');
        Route::get('schedule/bulk/{batchId}', [ScheduleBulkController::class, 'show'])->name('api.v1.bulk.show');
        Route::post('schedule/bulk/{batchId}/retry-failed', [ScheduleBulkController::class, 'retryFailed'])->name('api.v1.bulk.retry');

        // ---- clocking (TCP Manager+, NOT Humanity) ---------------------------
        // Worked time lives in TCP; shifts live in Humanity. Kept apart on
        // purpose — nothing here touches the `shifts` tables.
        Route::post('employees/{employeeId}/clock-in', [ClockController::class, 'clockIn'])->whereNumber('employeeId')->name('api.v1.clock.in');
        Route::post('employees/{employeeId}/clock-out', [ClockController::class, 'clockOut'])->whereNumber('employeeId')->name('api.v1.clock.out');
        Route::get('employees/{employeeId}/clock-status', [ClockController::class, 'status'])->whereNumber('employeeId')->name('api.v1.clock.status');

        // Everyone on the clock at this store, in one query. No TCP call, so a
        // dashboard may poll it — which was not true of clock-status, where each
        // miss cost a vendor request per employee.
        Route::get('on-the-clock', [ClockController::class, 'onTheClock'])->name('api.v1.clock.on-the-clock');

        /*
         | Break punches, registered only when tcp.breaks_enabled is on — and it
         | is off by default.
         |
         | A shift here is continuous expected work: you work it, or you are
         | asked to leave. Breaks are not something the business schedules, so
         | offering the action implied a concept that does not exist. The code
         | behind these is kept rather than deleted, because a store or a state
         | may yet mandate a meal break.
         |
         | This hides only the ACTION. The sync still handles a shift arriving as
         | several segments, because anyone can punch out and back in at a
         | physical clock and TCP will split it regardless of what we offer.
         */
        if (config('tcp.breaks_enabled')) {
            Route::post('employees/{employeeId}/break-start', [ClockController::class, 'breakStart'])->whereNumber('employeeId')->name('api.v1.clock.break-start');
            Route::post('employees/{employeeId}/break-end', [ClockController::class, 'breakEnd'])->whereNumber('employeeId')->name('api.v1.clock.break-end');
        }

        // ---- the unsynced-employee loop ---------------------------------------
        Route::get('employees/{employeeId}/sync-status', [EmployeeSyncController::class, 'status'])->whereNumber('employeeId')->name('api.v1.employees.sync-status');
        Route::post('employees/{employeeId}/humanity-sync', [EmployeeSyncController::class, 'request'])->whereNumber('employeeId')->name('api.v1.employees.humanity-sync');
    });
});
