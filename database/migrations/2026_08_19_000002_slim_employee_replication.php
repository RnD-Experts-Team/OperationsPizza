<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Cuts OperationsPizza back to the employee data a SCHEDULING service needs.
 *
 * HiringPizza owns employees. We were replicating its whole HR record — pay
 * history, status history, contacts, demographics, payroll-system ids, and a
 * full positions catalog + pivot. None of that drives scheduling.
 *
 * What stays: identity, whether they are currently active, their store
 * membership, availability (it drives the unavailable-shift write guard), the
 * TCP/Humanity links, and their position as a plain label string.
 *
 * Positions collapse to `employees.position_label` because nothing branches on
 * a position id — both vendors match on the label TEXT ("Crew Member" prefixes
 * "Crew Member - 3795-01"), so a catalog table and pivot bought us nothing.
 *
 * NOTE on the drops: no dropForeign()/dropIndex() calls. Every table dropped
 * here only has an OUTGOING foreign key, which is dropped along with the table.
 * An explicit dropForeign() would need MySQL's generated constraint name (wrong
 * name = errno 152) and throws outright on SQLite, which is what the test suite
 * runs on. Omitting it is correct on both drivers.
 */
return new class extends Migration {
    public function up(): void
    {
        foreach ([
            'employee_pay_histories',
            'employee_status_histories',
            'employee_external_ids',
            'employee_contacts',
            'employee_position',
            'positions',
        ] as $table) {
            Schema::dropIfExists($table);
        }

        Schema::table('employees', function (Blueprint $table) {
            // None of these six is indexed, so no index teardown is needed.
            $table->dropColumn([
                'middle_name',
                'gender',
                'employment_type',
                'birth_date',
                'image_url',
                'current_status_effective_date',
            ]);
        });

        // Separate statement: `after()` must not name a column being dropped.
        Schema::table('employees', function (Blueprint $table) {
            // The wage in effect when the snapshot landed. Replaces the pay
            // history table — see LaborCostCalculator for the accuracy caveat.
            $table->decimal('hourly_rate', 10, 4)->nullable()->after('current_status');

            // The employee's current position, as text. Matched by prefix
            // against TCP job codes and Humanity position names.
            $table->string('position_label', 190)->nullable()->index()->after('hourly_rate');
        });

        // Order matters on MySQL: the (store_id, position_id) unique index is
        // what backs the store_id foreign key, so dropping it first fails with
        // errno 1553. Add the replacement index before removing the old one so
        // the FK is never left without a usable index.
        Schema::table('humanity_position_map', function (Blueprint $table) {
            // NULL still means "the store's default/fallback position", used
            // when an employee's label matches nothing.
            $table->string('position_label', 190)->nullable()->after('store_id');
        });

        Schema::table('humanity_position_map', function (Blueprint $table) {
            $table->unique(['store_id', 'position_label']);
        });

        Schema::table('humanity_position_map', function (Blueprint $table) {
            $table->dropUnique(['store_id', 'position_id']);
        });

        Schema::table('humanity_position_map', function (Blueprint $table) {
            $table->dropColumn('position_id');
        });
    }

    /**
     * Restores the SCHEMA so migrate:refresh works. The DATA is not
     * recoverable — it only ever existed here as a replica, and the way to get
     * it back is `hiring:republish-employees` on the HiringPizza side.
     */
    public function down(): void
    {
        // Mirror of up()'s ordering, for the same FK-backing-index reason.
        Schema::table('humanity_position_map', function (Blueprint $table) {
            $table->unsignedBigInteger('position_id')->nullable()->after('store_id');
        });

        Schema::table('humanity_position_map', function (Blueprint $table) {
            $table->unique(['store_id', 'position_id']);
        });

        Schema::table('humanity_position_map', function (Blueprint $table) {
            $table->dropUnique(['store_id', 'position_label']);
        });

        Schema::table('humanity_position_map', function (Blueprint $table) {
            $table->dropColumn('position_label');
        });

        Schema::table('employees', function (Blueprint $table) {
            $table->dropColumn(['hourly_rate', 'position_label']);
        });

        Schema::table('employees', function (Blueprint $table) {
            $table->string('middle_name', 120)->nullable()->after('first_name');
            $table->string('gender', 20)->nullable();
            $table->string('employment_type', 10)->nullable();
            $table->date('birth_date')->nullable();
            $table->string('image_url', 2048)->nullable();
            $table->date('current_status_effective_date')->nullable();
        });

        Schema::create('positions', function (Blueprint $table) {
            $table->unsignedBigInteger('id')->primary();
            $table->string('label', 190);
            $table->text('description')->nullable();
            $table->timestamps();
        });

        Schema::create('employee_contacts', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('employee_id');
            $table->foreign('employee_id')->references('id')->on('employees')->cascadeOnDelete();
            $table->string('type', 30);
            $table->string('name', 120)->nullable();
            $table->string('value', 190);
            $table->boolean('is_primary')->default(false);
            $table->timestamps();
            $table->index(['employee_id', 'type', 'is_primary']);
        });

        Schema::create('employee_external_ids', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('employee_id');
            $table->foreign('employee_id')->references('id')->on('employees')->cascadeOnDelete();
            $table->string('id_type', 64)->index();
            $table->string('value', 128);
            $table->timestamps();
            $table->unique(['employee_id', 'id_type']);
        });

        Schema::create('employee_status_histories', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('employee_id');
            $table->foreign('employee_id')->references('id')->on('employees')->cascadeOnDelete();
            $table->string('status', 20);
            $table->date('effective_date');
            $table->string('store_number', 32)->nullable()->index();
            $table->timestamps();
            $table->index(['employee_id', 'effective_date']);
        });

        Schema::create('employee_pay_histories', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('employee_id');
            $table->foreign('employee_id')->references('id')->on('employees')->cascadeOnDelete();
            $table->decimal('base_pay', 10, 4);
            $table->decimal('performance_pay', 10, 4)->nullable();
            $table->date('effective_date');
            $table->timestamps();
            $table->index(['employee_id', 'effective_date']);
        });

        Schema::create('employee_position', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('employee_id');
            $table->foreign('employee_id')->references('id')->on('employees')->cascadeOnDelete();
            $table->unsignedBigInteger('position_id');
            $table->boolean('is_primary')->default(false);
            $table->date('effective_date')->nullable();
            $table->timestamps();
            $table->unique(['employee_id', 'position_id']);
        });
    }
};
