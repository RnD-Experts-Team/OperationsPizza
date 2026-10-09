<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What actually happened, as reviewed by a manager.
 *
 * NEVER written to Humanity — Humanity owns the plan, and worked time is not a
 * schedule.
 *
 * It IS written through to TCP Manager+, which is the system of record for
 * worked hours (see ActualShiftService). That was not true when this table was
 * created: rows were local-only and pre-filled by a TimeClock importer
 * (source='timeclock'), which meant a manager's correction lived here alone and
 * was reverted by the next `tcp:sync-worksegments` run.
 *
 * Two columns stay local because TCP models neither: `status='absent'` (an
 * absence is the absence of worked time — marking one deletes the segment) and
 * `shift_assignment_id` (the link to the plan, which only this service joins).
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('actual_shifts', function (Blueprint $table) {
            $table->id();

            $table->unsignedBigInteger('store_id');
            $table->foreign('store_id')->references('id')->on('stores')->cascadeOnDelete();

            $table->unsignedBigInteger('employee_id');
            $table->foreign('employee_id')->references('id')->on('employees')->restrictOnDelete();

            // One actual per planned assignment. NULL = ad-hoc coverage with no
            // planned counterpart (status='added').
            $table->unsignedBigInteger('shift_assignment_id')->nullable()->unique();
            $table->foreign('shift_assignment_id')->references('id')->on('shift_assignments')->nullOnDelete();

            $table->date('shift_date')->index();
            $table->time('start_time');
            $table->time('end_time');
            $table->dateTime('starts_at_utc');
            $table->dateTime('ends_at_utc');
            $table->unsignedInteger('duration_minutes');
            $table->boolean('crosses_midnight')->default(false);

            $table->string('label', 120)->nullable();
            $table->string('shift_type', 20)->default('custom');

            // confirmed = matched the plan; modified = times/label changed;
            // absent = no attendance; added = no planned counterpart.
            $table->string('status', 20)->index();
            $table->text('note')->nullable();

            $table->string('source', 20)->default('manual'); // manual | timeclock
            $table->string('humanity_timeclock_id', 64)->nullable()->unique();

            $table->unsignedBigInteger('reviewed_by_user_id')->nullable();
            $table->timestamp('reviewed_at')->nullable();

            $table->timestamps();
            $table->softDeletes();

            $table->index(['store_id', 'shift_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('actual_shifts');
    }
};
