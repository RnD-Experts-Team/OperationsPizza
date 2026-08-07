<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The Humanity mirror. Humanity is the source of truth: every write goes there
 * first and a row only lands here once it succeeded.
 *
 * Times are stored twice on purpose. The wall-clock trio (shift_date,
 * start_time, end_time) is what we display and what we send to Humanity, which
 * speaks local dates + "HH:MM". The UTC instants are what every query, overlap
 * check and sort uses — comparing TIME columns breaks the moment a shift
 * crosses midnight, which is routine here because the store closes at 00:00.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('shifts', function (Blueprint $table) {
            $table->id();

            $table->unsignedBigInteger('store_id');
            $table->foreign('store_id')->references('id')->on('stores')->cascadeOnDelete();

            $table->string('humanity_location_id', 64)->nullable();
            // Humanity's `schedule` request param.
            $table->string('humanity_position_id', 64)->nullable()->index();
            $table->string('humanity_shift_id', 64)->nullable()->unique();

            // Local business date of the shift's START.
            $table->date('shift_date');
            $table->time('start_time');
            $table->time('end_time');

            $table->dateTime('starts_at_utc');
            $table->dateTime('ends_at_utc');
            // True UTC delta, so a DST fall-back overnight shift is 9h not 8h.
            // Prefer this over any wall-clock subtraction.
            $table->unsignedInteger('duration_minutes');
            $table->boolean('crosses_midnight')->default(false);

            $table->string('label', 120)->nullable();
            $table->string('shift_type', 20)->default('custom'); // morning|evening|night|split|custom
            $table->text('note')->nullable();
            $table->unsignedTinyInteger('slots')->default(1);

            // Humanity does not publish a shift just because staff were added,
            // so this tracks OUR intent until the publish endpoint is confirmed.
            $table->boolean('is_published')->default(false)->index();

            $table->string('origin', 20)->default('operations'); // operations|humanity|reconciler
            $table->ulid('recurring_group_id')->nullable()->index();

            $table->unsignedBigInteger('created_by_user_id')->nullable();

            $table->timestamp('humanity_updated_at')->nullable();
            // sha1 fingerprint of the mirrored fields; lets the reconciler skip
            // untouched shifts without diffing them field by field.
            $table->char('humanity_hash', 40)->nullable();
            $table->timestamp('last_reconciled_at')->nullable();

            $table->timestamps();
            $table->softDeletes();

            $table->index(['store_id', 'shift_date']);
            $table->index(['store_id', 'starts_at_utc']);
        });

        Schema::create('shift_assignments', function (Blueprint $table) {
            $table->id();

            $table->unsignedBigInteger('shift_id');
            $table->foreign('shift_id')->references('id')->on('shifts')->cascadeOnDelete();

            $table->unsignedBigInteger('employee_id');
            $table->foreign('employee_id')->references('id')->on('employees')->restrictOnDelete();

            $table->string('humanity_employee_id', 64)->nullable();
            $table->string('status', 20)->default('assigned'); // assigned|open|released

            $table->timestamps();
            $table->softDeletes();

            $table->unique(['shift_id', 'employee_id']);
            $table->index('employee_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('shift_assignments');
        Schema::dropIfExists('shifts');
    }
};
