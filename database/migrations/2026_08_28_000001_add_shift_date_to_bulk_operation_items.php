<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The day a bulk item belongs to, so work can be sliced by day.
 *
 * Bulk operations are processed one DAY at a time and yield between days, which
 * is what stops one store's whole week draining before another store has any of
 * its own (Humanity's limit is account-wide, so 38 stores publishing at once
 * share one budget). Slicing needs the date as a column: it was previously only
 * inside `payload` for creates, and not present at all for deletes.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('schedule_bulk_operation_items', function (Blueprint $table) {
            // Nullable because a delete item whose shift has since vanished has
            // no date to claim; those sort last and are processed at the end.
            $table->date('shift_date')->nullable()->after('action');

            // The slice query is (bulk_operation_id, status, shift_date).
            $table->index(['bulk_operation_id', 'shift_date']);
        });
    }

    public function down(): void
    {
        Schema::table('schedule_bulk_operation_items', function (Blueprint $table) {
            $table->dropIndex(['bulk_operation_id', 'shift_date']);
        });

        Schema::table('schedule_bulk_operation_items', function (Blueprint $table) {
            $table->dropColumn('shift_date');
        });
    }
};
