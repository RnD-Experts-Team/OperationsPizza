<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Drops `employee_clock_states`. An open segment is now the whole of that fact.
 *
 * The table existed because the sync deliberately discarded open segments, so
 * "who is on the clock" had nowhere to live and had to be maintained separately
 * — by TcpClockService, and therefore ONLY when our own API was called. A punch
 * made at a physical clock or in TCP's own app never reached it, which is
 * exactly the gap this work set out to close.
 *
 * Now that `tcp_work_segments` persists open segments (time_out NULL), the
 * question is one indexed query over rows the 10-minute sync already fetches,
 * correct regardless of where the punch was made, at no extra TCP calls. Keeping
 * both would be two tables holding one fact — and would keep the freshness/TTL
 * reasoning that only ever existed because of that split.
 *
 * NOT backfilled, on purpose. Reconstructing a UTC instant here would mean
 * resolving each store's timezone inside a migration and guessing at rows whose
 * store_id is nullable. The next sync rediscovers every open segment from TCP
 * itself, correctly, within ten minutes.
 *
 *     After migrating, run:  php artisan tcp:sync-worksegments
 *
 * Without that, the live board is empty until the scheduler's next pass — which
 * matters if you deploy mid-shift.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::dropIfExists('employee_clock_states');
    }

    /**
     * Recreated empty. The rows are not restorable and should not be faked:
     * TcpClockService repopulates it on the next punch, and the old sync never
     * wrote to it at all.
     */
    public function down(): void
    {
        Schema::create('employee_clock_states', function (Blueprint $table) {
            $table->id();

            $table->unsignedBigInteger('employee_id')->unique();
            $table->foreign('employee_id')->references('id')->on('employees')->cascadeOnDelete();

            $table->unsignedBigInteger('store_id')->nullable();
            $table->foreign('store_id')->references('id')->on('stores')->nullOnDelete();

            $table->string('tcp_employee_id', 64)->index();
            $table->string('status', 20)->index();
            $table->string('tcp_work_segment_id', 64)->nullable();

            $table->dateTime('clock_in_at')->nullable();
            $table->dateTime('break_started_at')->nullable();
            $table->json('open_segment')->nullable();
            $table->dateTime('last_synced_at');

            $table->timestamps();
        });
    }
};
