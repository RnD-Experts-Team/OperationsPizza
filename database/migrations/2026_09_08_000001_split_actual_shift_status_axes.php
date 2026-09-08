<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Splits `actual_shifts.status` into the two independent facts it was
 * conflating.
 *
 * The old enum mixed three different KINDS of statement:
 *
 *   confirmed / modified   a COMPARISON against the plan — derived from data,
 *                          and recomputable by anyone at any time;
 *   added                  a STRUCTURAL fact — there is no plan behind it;
 *   absent                 a HUMAN ASSERTION about attendance, which no amount
 *                          of data can contradict.
 *
 * Holding all three in one column is why an absence could be silently
 * re-derived into "worked as planned" by an edit, and why `tcp:sync-worksegments`
 * and the review path kept disagreeing: both recomputed the same overloaded
 * field, so a background job could overwrite a human's judgement.
 *
 * After this there are two axes, and each has exactly one owner:
 *
 *   time_variance   DERIVED. Recomputed freely — by the review path, by the TCP
 *                   sync, by anything. Never asserted by a client.
 *   review_state    ASSERTED. Set only by a human action. Never derived, and
 *                   never touched by a background sync.
 *
 * This also matches TCP's own model, which keeps managerApproval /
 * employeeApproval / otherApproval separate from a segment's times.
 *
 * The API keeps emitting the old `status` string, computed from these two in
 * ActualShift::legacyStatus(), so no client has to change.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('actual_shifts', function (Blueprint $table) {
            // matches | differs | unplanned
            $table->string('time_variance', 20)->default('unplanned')->after('shift_type');
            // unreviewed | worked | absent
            $table->string('review_state', 20)->default('unreviewed')->after('time_variance');
        });

        $this->backfill();

        Schema::table('actual_shifts', function (Blueprint $table) {
            $table->dropIndex(['status']);
        });

        Schema::table('actual_shifts', function (Blueprint $table) {
            $table->dropColumn('status');
        });

        Schema::table('actual_shifts', function (Blueprint $table) {
            $table->index(['store_id', 'review_state']);
            $table->index('time_variance');
        });
    }

    /**
     * The mapping is deterministic in one direction, so no row is guessed at.
     *
     * The one judgement call is `review_state` for a non-absent row: only a
     * human write ever set `reviewed_by_user_id`, so rows without one were
     * imported by the TCP sync and were never actually reviewed. Calling those
     * `unreviewed` is more truthful than promoting every historical import to
     * "a manager signed this off".
     */
    private function backfill(): void
    {
        DB::table('actual_shifts')->where('status', 'confirmed')->update(['time_variance' => 'matches']);
        DB::table('actual_shifts')->where('status', 'modified')->update(['time_variance' => 'differs']);
        DB::table('actual_shifts')->where('status', 'added')->update(['time_variance' => 'unplanned']);

        // An absence carries the planned times, so it compared as `matches`.
        // The variance is meaningless for a row nobody worked; it is set for
        // consistency, and review_state is what any reader looks at.
        DB::table('actual_shifts')->where('status', 'absent')->update([
            'time_variance' => 'matches',
            'review_state' => 'absent',
        ]);

        DB::table('actual_shifts')
            ->where('status', '!=', 'absent')
            ->whereNotNull('reviewed_by_user_id')
            ->update(['review_state' => 'worked']);
    }

    public function down(): void
    {
        Schema::table('actual_shifts', function (Blueprint $table) {
            $table->string('status', 20)->default('added')->after('shift_type');
        });

        DB::table('actual_shifts')->where('review_state', 'absent')->update(['status' => 'absent']);
        DB::table('actual_shifts')->where('review_state', '!=', 'absent')->where('time_variance', 'matches')->update(['status' => 'confirmed']);
        DB::table('actual_shifts')->where('review_state', '!=', 'absent')->where('time_variance', 'differs')->update(['status' => 'modified']);
        DB::table('actual_shifts')->where('review_state', '!=', 'absent')->where('time_variance', 'unplanned')->update(['status' => 'added']);

        Schema::table('actual_shifts', function (Blueprint $table) {
            $table->dropIndex(['store_id', 'review_state']);
            $table->dropIndex(['time_variance']);
            $table->dropColumn(['time_variance', 'review_state']);
            $table->index('status');
        });
    }
};
