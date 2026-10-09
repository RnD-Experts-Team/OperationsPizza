<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The raw punches, exactly as TCP holds them.
 *
 * `actual_shifts` used to be 1:1 with a TCP work segment, which quietly assumed
 * one segment per shift. TCP does not work that way: punching out and back in
 * CLOSES a segment and OPENS a new one, so a single shift routinely arrives as
 * two or more. Because `actual_shifts.shift_assignment_id` is unique and
 * overlap-matching mapped every one of those segments to the same planned
 * assignment, the second segment could not be written at all — it raised a
 * constraint violation that killed the store's sync run, or was swallowed as a
 * log warning when a punch triggered it.
 *
 * So the grain is split. This table is the faithful mirror of TCP, one row per
 * segment and nothing derived; `actual_shifts` becomes the roll-up a manager
 * actually reviews, grouping these into shifts.
 *
 * The governing rule for everything here: TCP owns every time, duration and
 * punch absolutely, and none of it is ever locally overridden. What this service
 * owns — which segments form a shift, the link to the plan, the human verdict —
 * has no TCP counterpart and therefore cannot contradict it.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('tcp_work_segments', function (Blueprint $table) {
            $table->id();

            // TCP's own id, and the only key a re-sync may match on.
            $table->string('tcp_work_segment_id', 64)->unique();

            // The grouping decision, STORED rather than re-derived on read.
            // Nullable because a segment is imported before it is grouped, and
            // because a roll-up can be soft-deleted out from under it.
            $table->unsignedBigInteger('actual_shift_id')->nullable();
            $table->foreign('actual_shift_id')->references('id')->on('actual_shifts')->nullOnDelete();

            $table->unsignedBigInteger('employee_id');
            $table->foreign('employee_id')->references('id')->on('employees')->restrictOnDelete();

            $table->unsignedBigInteger('store_id');
            $table->foreign('store_id')->references('id')->on('stores')->cascadeOnDelete();

            // Denormalised so a segment can be matched back to TCP without a
            // join, and so an unmapped segment can still be reported on.
            $table->string('tcp_employee_id', 64)->index();
            $table->string('tcp_job_code_id', 64)->nullable();

            /*
             | Where this segment came from, recorded at the moment we write or
             | first see it — the one thing about a segment that TCP cannot tell
             | us afterwards, because TCP stores a segment identically however it
             | was made.
             |
             |   punch       our clock endpoints created it
             |   manual      a manager hand-entered it through the review grid
             |   discovered  the sync found it already in TCP — a physical clock,
             |               TCP's own app, or TCP's web UI
             |
             | `discovered` is the case this whole change exists to serve, and it
             | is also what replaces `actual_shifts.source`: that column could not
             | be derived from "does it have segments", because a manager's
             | hand-entry is pushed to TCP and gets a segment too.
             */
            $table->string('origin', 20)->default('discovered')->index();

            /*
             | Both representations, the same rule the planned side follows: the
             | wall clock is what TCP speaks and what a manager reads, the UTC
             | instants are what every overlap check and sort uses.
             |
             | time_out NULL means the segment is OPEN — somebody is on the clock
             | right now. That is the whole live-state mechanism: there is no
             | second table holding "who is clocked in", because an open row here
             | already IS that fact.
             */
            $table->dateTime('time_in');
            $table->dateTime('time_out')->nullable();
            $table->dateTime('starts_at_utc');
            $table->dateTime('ends_at_utc')->nullable();

            // Null while open. The true UTC delta, so it stays correct on both
            // DST days a year.
            $table->unsignedInteger('duration_minutes')->nullable();

            // What the employee physically punched, before TCP's rounding rules
            // or a manager's edit. Payroll uses the pair above; disputes need
            // these.
            $table->dateTime('actual_punch_in_at')->nullable();
            $table->dateTime('actual_punch_out_at')->nullable();

            // TCP's own incompleteness flags. A missed punch means the recorded
            // time is a system default, not something the employee did.
            $table->boolean('missed_in_punch')->default(false);
            $table->boolean('missed_out_punch')->default(false);

            // TCP's shiftNotes, kept strictly apart from the manager's note on
            // the roll-up. Holding both in one column is how a sync carrying no
            // shift note silently erased what a manager had typed.
            $table->text('segment_note')->nullable();

            // TCP's updatedOn, so a reconcile can tell a real change from a
            // re-read, and last_seen_at so "when did we last confirm TCP still
            // has this" is answerable when a row goes missing.
            $table->dateTime('tcp_updated_on')->nullable();
            $table->dateTime('last_seen_at')->nullable();

            $table->timestamps();
            // Soft, never hard: a segment that vanishes from TCP is a payroll
            // event somebody may need to explain later.
            $table->softDeletes();

            // "Who is on the clock at this store" — the open-segment query, now
            // one indexed read instead of a vendor call per employee.
            $table->index(['store_id', 'time_out']);
            $table->index(['employee_id', 'starts_at_utc']);
            // The reconcile sweep's window scan.
            $table->index(['store_id', 'starts_at_utc']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tcp_work_segments');
    }
};
