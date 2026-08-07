<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Makes the business week and store hours DATA rather than constants baked
 * into the frontend. The scheduling UI currently hardcodes a Tuesday-start
 * week (dayIndex 0 = Tue) and 09:00–00:00 store hours in lib/scheduling/data.ts.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('store_schedule_settings', function (Blueprint $table) {
            $table->id();

            $table->unsignedBigInteger('store_id')->unique();
            $table->foreign('store_id')->references('id')->on('stores')->cascadeOnDelete();

            // 0=Sun … 6=Sat. Default 2 = Tuesday, matching getWeekDates() in
            // components/scheduling/scheduling-manager-new.tsx.
            $table->unsignedTinyInteger('week_start_dow')->default(2);

            $table->time('open_time')->default('09:00');
            // 00:00 means midnight-at-the-end-of-day. Shifts legitimately cross
            // midnight here; see ShiftTimeResolver.
            $table->time('close_time')->default('00:00');

            $table->unsignedSmallInteger('slot_minutes')->default(30);
            $table->unsignedInteger('overtime_threshold_minutes')->default(2400); // 40h

            // Fallback when an employee has no pay history yet.
            $table->unsignedInteger('default_labor_rate_cents')->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('store_schedule_settings');
    }
};
