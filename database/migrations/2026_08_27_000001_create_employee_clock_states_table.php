<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Who is on the clock right now.
 *
 * This state used to live only in the cache, which made it unqueryable and lost
 * it on a flush — every punch then paid a TCP lookup to rediscover something we
 * already knew. TCP remains the system of record for worked TIME (`actual_shifts`
 * is still populated by reading segments back); this table records the CURRENT
 * state so it survives a restart and can be reported on.
 *
 * One row per employee, upserted on every punch. The cache stays in front of it
 * as a read-through layer, so a polling dashboard still doesn't hit the DB per
 * request.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('employee_clock_states', function (Blueprint $table) {
            $table->id();

            // One live state per employee — the row is upserted, never appended.
            $table->unsignedBigInteger('employee_id')->unique();
            $table->foreign('employee_id')->references('id')->on('employees')->cascadeOnDelete();

            // Nullable: a live re-check against TCP learns the state without
            // knowing which store the punch was made at.
            $table->unsignedBigInteger('store_id')->nullable();
            $table->foreign('store_id')->references('id')->on('stores')->nullOnDelete();

            // Denormalised so a state can be matched back to TCP's own id
            // without a join — the employee's link can also change underneath.
            $table->string('tcp_employee_id', 64)->index();

            // clocked_out | clocked_in | on_break. A break CLOSES the worked
            // segment in TCP, so on_break and clocked_out are both "no open
            // segment" — the distinction is ours, not TCP's.
            $table->string('status', 20)->index();

            $table->string('tcp_work_segment_id', 64)->nullable();

            $table->dateTime('clock_in_at')->nullable();
            $table->dateTime('break_started_at')->nullable();

            // The open segment as TCP last reported it, so clock-status can be
            // answered after a cache flush without spending a TCP call.
            $table->json('open_segment')->nullable();

            // How stale this row is. Read paths trust it only inside the same
            // window the cache used to enforce (tcp.clock_state_ttl_seconds).
            $table->dateTime('last_synced_at');

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('employee_clock_states');
    }
};
