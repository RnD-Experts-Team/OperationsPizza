<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Turns `actual_shifts` from "one row per TCP segment" into "one row per shift".
 *
 * The raw punches move to `tcp_work_segments` (see the migration before this).
 * What is left here is the thing a manager reviews and the thing the grid shows:
 * a shift, with however many segments happen to sit behind it.
 *
 * Columns leaving, and why:
 *
 *   tcp_work_segment_id    per-segment facts, and a roll-up has N segments.
 *   has_missed_punch
 *   actual_punch_in_at
 *   actual_punch_out_at
 *
 *   note                   split in two. One column served both TCP's shiftNotes
 *                          and the manager's own words, and the sync wrote the
 *                          former over the latter — so a manager's note was
 *                          silently erased by the next run whenever TCP carried
 *                          no shift note, which is most of the time.
 *
 *   humanity_timeclock_id  dead since the TCP migration landed. Present in
 *                          $fillable, read and written by nothing.
 *
 *   source                 derived from the segments' `origin` now. It could NOT
 *                          be derived from "has segments", because a manager's
 *                          hand-entry is pushed to TCP and gets one too.
 *
 * `shift_assignment_id` stays UNIQUE. One actual per planned assignment was
 * always the right rule; it only looked wrong because the old grain made a split
 * shift collide with itself.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('actual_shifts', function (Blueprint $table) {
            // Work in progress: somebody is on the clock and this shift has not
            // finished. duration_minutes on such a row is "so far", not a final
            // figure, so every reader has to check this.
            $table->boolean('is_open')->default(false)->after('crosses_midnight');

            /*
             | DERIVED, like time_variance — the grid's "look at this" flag.
             |
             | It exists so review_state can stay purely human-asserted while a
             | manager still only touches the shifts that need it: a missed
             | punch, a variance against the plan, a segment that changed after
             | they signed it off, or somebody who never clocked out. A clean
             | shift raises nothing and needs no click.
             */
            $table->boolean('needs_attention')->default(false)->after('review_state');

            /*
             | ASSERTED, like review_state. A manager has merged two roll-ups or
             | split one apart, and the automatic grouper must not undo it.
             |
             | Safe to honour because grouping is OURS: TCP has no concept of
             | which segments form a shift, so a pinned grouping contradicts
             | nothing TCP holds. The times underneath still come from TCP and
             | are never locally overridden.
             */
            $table->boolean('grouping_pinned')->default(false)->after('needs_attention');

            // The manager's own words, which no sync may ever touch.
            $table->text('manager_note')->nullable()->after('grouping_pinned');
        });

        Schema::table('actual_shifts', function (Blueprint $table) {
            /*
             | A shift in progress has no end yet, and must not pretend to.
             |
             | Filling these with "now" would make a running shift look like a
             | finished one whose end time crept forward every time anyone
             | looked. NULL says the true thing — still on the clock — and
             | is_open next to it is the flag readers branch on.
             |
             | duration_minutes stays NOT NULL: minutes worked so far is a real
             | number even mid-shift, and zero is its honest starting value.
             */
            $table->time('end_time')->nullable()->change();
            $table->dateTime('ends_at_utc')->nullable()->change();
        });

        $this->backfill();

        Schema::table('actual_shifts', function (Blueprint $table) {
            $table->dropUnique(['tcp_work_segment_id']);
            $table->dropUnique(['humanity_timeclock_id']);
        });

        Schema::table('actual_shifts', function (Blueprint $table) {
            $table->dropColumn([
                'tcp_work_segment_id',
                'has_missed_punch',
                'actual_punch_in_at',
                'actual_punch_out_at',
                'note',
                'humanity_timeclock_id',
                'source',
            ]);
        });

        Schema::table('actual_shifts', function (Blueprint $table) {
            $table->index(['store_id', 'is_open']);
            $table->index(['store_id', 'needs_attention']);
        });
    }

    /**
     * Every existing row that carried a segment id becomes a segment row, so no
     * hours are lost and each roll-up keeps exactly what it already had.
     *
     * Rows without one are hand-entered or absences. They simply get no
     * segments, which is the honest representation and derives `source` back to
     * `manual` on its own.
     */
    private function backfill(): void
    {
        // Unquoted identifier: portable across the MySQL this runs on and the
        // SQLite the test suite uses.
        DB::table('actual_shifts')->update(['manager_note' => DB::raw('note')]);

        DB::table('actual_shifts')
            ->whereNotNull('tcp_work_segment_id')
            ->whereNull('deleted_at')
            ->orderBy('id')
            ->chunkById(500, function ($rows) {
                $employees = DB::table('employees')
                    ->whereIn('id', $rows->pluck('employee_id')->unique()->all())
                    ->pluck('tcp_employee_id', 'id');

                $now = now()->toDateTimeString();
                $insert = [];

                foreach ($rows as $row) {
                    $startDate = substr((string) $row->shift_date, 0, 10);

                    $endDate = $row->crosses_midnight
                        ? date('Y-m-d', strtotime($startDate . ' +1 day'))
                        : $startDate;

                    $insert[] = [
                        'tcp_work_segment_id' => $row->tcp_work_segment_id,
                        'actual_shift_id' => $row->id,
                        'employee_id' => $row->employee_id,
                        'store_id' => $row->store_id,
                        'tcp_employee_id' => (string) ($employees[$row->employee_id] ?? ''),
                        'tcp_job_code_id' => null,
                        // The old `source` maps exactly: a timeclock row was
                        // found in TCP, a manual row was entered by a manager.
                        'origin' => $row->source === 'timeclock' ? 'discovered' : 'manual',
                        'time_in' => $startDate . ' ' . $row->start_time,
                        'time_out' => $endDate . ' ' . $row->end_time,
                        'starts_at_utc' => $row->starts_at_utc,
                        'ends_at_utc' => $row->ends_at_utc,
                        'duration_minutes' => $row->duration_minutes,
                        'actual_punch_in_at' => $row->actual_punch_in_at,
                        'actual_punch_out_at' => $row->actual_punch_out_at,
                        // The old column was a single OR of the two ends. Putting
                        // it on the out-punch preserves that OR exactly rather
                        // than inventing which end was missed — and the next sync
                        // replaces both with TCP's own flags anyway.
                        'missed_in_punch' => false,
                        'missed_out_punch' => (bool) $row->has_missed_punch,
                        'segment_note' => null,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ];
                }

                if ($insert !== []) {
                    DB::table('tcp_work_segments')->insert($insert);
                }
            });
    }

    public function down(): void
    {
        Schema::table('actual_shifts', function (Blueprint $table) {
            $table->string('tcp_work_segment_id', 64)->nullable()->unique();
            $table->boolean('has_missed_punch')->default(false);
            $table->dateTime('actual_punch_in_at')->nullable();
            $table->dateTime('actual_punch_out_at')->nullable();
            $table->text('note')->nullable();
            $table->string('humanity_timeclock_id', 64)->nullable()->unique();
            $table->string('source', 20)->default('manual');
        });

        DB::table('actual_shifts')->update(['note' => DB::raw('manager_note')]);

        Schema::table('actual_shifts', function (Blueprint $table) {
            $table->time('end_time')->nullable(false)->change();
            $table->dateTime('ends_at_utc')->nullable(false)->change();
        });

        Schema::table('actual_shifts', function (Blueprint $table) {
            $table->dropIndex(['store_id', 'is_open']);
            $table->dropIndex(['store_id', 'needs_attention']);
            $table->dropColumn(['is_open', 'needs_attention', 'grouping_pinned', 'manager_note']);
        });
    }
};
