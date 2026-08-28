<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Whether a shift has reached Humanity yet.
 *
 * Humanity is still written FIRST for every shift, and a real failure (bad
 * position mapping, permission, conflict) still rejects the whole request
 * without writing anything locally — that no-orphans guarantee is unchanged.
 *
 * The single exception is a throttle (application status 91). A rate limit is
 * not the manager's mistake and cannot be fixed by redoing it, so the shift is
 * saved locally and carried to Humanity later by humanity:sync-pending-shifts.
 * The state describes the ROW, not a queued operation: syncing means "make
 * Humanity match this local row", which is idempotent and needs no payload
 * snapshot or ordering between queued edits of the same shift.
 *
 * `sync_status` therefore also gates the reconciler — a pending shift is
 * legitimately absent upstream and must not be soft-deleted as missing.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('shifts', function (Blueprint $table) {
            // synced | pending | parked. Existing rows are synced by definition:
            // until now a shift only existed locally after Humanity accepted it.
            $table->string('sync_status', 20)->default('synced')->index()->after('humanity_hash');

            $table->unsignedTinyInteger('sync_attempts')->default(0)->after('sync_status');
            // Indexed with status because the sweep selects on exactly this pair.
            $table->dateTime('sync_next_attempt_at')->nullable()->after('sync_attempts');
            $table->text('sync_last_error')->nullable()->after('sync_next_attempt_at');
            // Set when retries are exhausted and a human has to intervene.
            $table->dateTime('sync_parked_at')->nullable()->after('sync_last_error');

            $table->index(['sync_status', 'sync_next_attempt_at']);
        });
    }

    public function down(): void
    {
        Schema::table('shifts', function (Blueprint $table) {
            $table->dropIndex(['sync_status', 'sync_next_attempt_at']);
        });

        Schema::table('shifts', function (Blueprint $table) {
            $table->dropIndex(['sync_status']);
        });

        Schema::table('shifts', function (Blueprint $table) {
            $table->dropColumn([
                'sync_status', 'sync_attempts', 'sync_next_attempt_at',
                'sync_last_error', 'sync_parked_at',
            ]);
        });
    }
};
