<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * TCP Manager+ (TimeClock Plus) is the clocking system and the system of record
 * for employees; Humanity owns only the schedule.
 *
 * TCP's `employeeId` is CLIENT-SUPPLIED on create, so for anyone we create it
 * will simply equal `employees.id`. The column exists because the live roster
 * predates us and carries whatever ids TCP already assigned — so the link has
 * to be discoverable rather than assumed.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('employees', function (Blueprint $table) {
            $table->string('tcp_employee_id', 64)->nullable()->unique()->after('humanity_synced_at');
            $table->timestamp('tcp_synced_at')->nullable()->after('tcp_employee_id');
        });

        Schema::table('actual_shifts', function (Blueprint $table) {
            // Replaces humanity_timeclock_id in practice: clocking lives in TCP,
            // not Humanity. The old column is left alone rather than dropped —
            // nothing has written to it, and dropping columns in a migration
            // that also adds them makes a rollback lossy.
            $table->string('tcp_work_segment_id', 64)->nullable()->unique()->after('humanity_timeclock_id');

            // TCP flags segments it knows are incomplete. A missed punch means
            // the recorded time is a system default, not something the employee
            // actually did, so it must be visible to a manager rather than
            // imported as fact.
            $table->boolean('has_missed_punch')->default(false)->after('tcp_work_segment_id');

            // What the employee physically punched, before rounding rules or a
            // manager edit. Payroll uses start/end; disputes need these.
            $table->dateTime('actual_punch_in_at')->nullable()->after('has_missed_punch');
            $table->dateTime('actual_punch_out_at')->nullable()->after('actual_punch_in_at');
        });
    }

    public function down(): void
    {
        Schema::table('actual_shifts', function (Blueprint $table) {
            $table->dropColumn([
                'tcp_work_segment_id',
                'has_missed_punch',
                'actual_punch_in_at',
                'actual_punch_out_at',
            ]);
        });

        Schema::table('employees', function (Blueprint $table) {
            $table->dropColumn(['tcp_employee_id', 'tcp_synced_at']);
        });
    }
};
