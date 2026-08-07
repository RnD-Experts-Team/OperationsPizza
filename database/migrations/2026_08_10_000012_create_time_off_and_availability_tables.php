<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        /**
         * Mirror of Humanity's leave ("vacations") records. GET /leaves is the
         * one Humanity list endpoint with real page+limit pagination.
         *
         * Stored as a date RANGE; the week endpoint expands it to one entry per
         * day, because the scheduling grid is per-day.
         */
        Schema::create('time_off', function (Blueprint $table) {
            $table->id();

            $table->unsignedBigInteger('store_id')->nullable();
            $table->foreign('store_id')->references('id')->on('stores')->nullOnDelete();

            $table->unsignedBigInteger('employee_id');
            $table->foreign('employee_id')->references('id')->on('employees')->cascadeOnDelete();

            $table->string('humanity_leave_id', 64)->nullable()->unique();

            $table->date('start_date')->index();
            $table->date('end_date')->index();
            $table->boolean('all_day')->default(true);
            $table->time('start_time')->nullable();
            $table->time('end_time')->nullable();

            $table->string('type', 20)->default('pto'); // pto|vacation|sick|unpaid|other
            $table->string('label', 120)->nullable();
            $table->string('status', 20)->default('approved')->index(); // pending|approved|denied
            $table->text('note')->nullable();

            $table->string('origin', 20)->default('humanity'); // humanity|operations

            $table->timestamps();
            $table->softDeletes();

            $table->index(['employee_id', 'start_date']);
        });

        /**
         * Manager-entered blocks, layered on top of the availability projected
         * from the employee's replicated hiring profile. Owned here because
         * hiring's availability is a long-lived profile, not a one-week note.
         */
        Schema::create('schedule_availability_overrides', function (Blueprint $table) {
            $table->id();

            $table->unsignedBigInteger('store_id');
            $table->foreign('store_id')->references('id')->on('stores')->cascadeOnDelete();

            $table->unsignedBigInteger('employee_id');
            $table->foreign('employee_id')->references('id')->on('employees')->cascadeOnDelete();

            $table->string('scope', 10)->default('weekly'); // weekly | date

            // Canonical 0=Sun..6=Sat. Converted to the UI's Tuesday-based
            // day_index only at the API edge — never stored that way.
            $table->unsignedTinyInteger('day_of_week')->nullable();
            $table->date('specific_date')->nullable();

            $table->boolean('all_day')->default(true);
            $table->time('start_time')->nullable();
            $table->time('end_time')->nullable();
            $table->string('reason', 255)->nullable();

            $table->date('effective_from')->nullable();
            $table->date('effective_to')->nullable();

            $table->timestamps();
            $table->softDeletes();

            $table->index(['store_id', 'employee_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('schedule_availability_overrides');
        Schema::dropIfExists('time_off');
    }
};
