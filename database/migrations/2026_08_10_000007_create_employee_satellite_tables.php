<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Employee satellites replicated from the hiring snapshot.
 *
 * All of these are rebuilt wholesale whenever their collection appears in an
 * event (delete + insert), because hiring's `changed_fields` ships whole arrays
 * rather than per-row patches. Their ids therefore carry no meaning here and
 * are plain auto-increments — except employee_external_ids, which is keyed by
 * (employee_id, id_type) so the Humanity ID survives a rebuild.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('employee_contacts', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('employee_id');
            $table->foreign('employee_id')->references('id')->on('employees')->cascadeOnDelete();

            $table->string('type', 30); // email | phone | emergency_contact
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

            // HiringPizza id_types.label — 'Humanity ID', 'Altametrics ID',
            // 'Paychecks_ID', ...
            $table->string('id_type', 64)->index();
            $table->string('value', 128);
            $table->timestamps();

            $table->unique(['employee_id', 'id_type']);
        });

        Schema::create('employee_status_histories', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('employee_id');
            $table->foreign('employee_id')->references('id')->on('employees')->cascadeOnDelete();

            $table->string('status', 20); // hired | resigned | terminated | rehired
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

        Schema::create('employee_availability_days', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('employee_id');
            $table->foreign('employee_id')->references('id')->on('employees')->cascadeOnDelete();

            // sunday..saturday, as hiring spells it.
            $table->string('day_of_week', 12);
            $table->string('shift_type', 4); // AM | PM | OP
            $table->timestamps();

            $table->index(['employee_id', 'day_of_week']);
        });

        Schema::create('employee_availability_times', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('employee_availability_day_id');
            $table->foreign('employee_availability_day_id', 'eat_day_fk')
                ->references('id')->on('employee_availability_days')->cascadeOnDelete();

            $table->time('available_from');
            $table->time('available_to');
            $table->timestamps();
        });

        // Pivots. Auto-increment ids because hiring's pivot ids are not stable
        // across the delete+reinsert its own sync* methods perform — the natural
        // key is the pair.
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

        Schema::create('employee_store', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('employee_id');
            $table->foreign('employee_id')->references('id')->on('employees')->cascadeOnDelete();

            // Matched by store_number, not id: the hiring payload identifies a
            // store by employee.stores[].store.store_number, and that store may
            // not have replicated here yet.
            $table->string('store_number', 32)->index();
            $table->unsignedBigInteger('store_id')->nullable()->index();

            $table->string('status', 20)->nullable();
            $table->boolean('active')->default(false);
            $table->date('effective_date')->nullable();
            $table->timestamps();

            $table->unique(['employee_id', 'store_number']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('employee_store');
        Schema::dropIfExists('employee_position');
        Schema::dropIfExists('employee_availability_times');
        Schema::dropIfExists('employee_availability_days');
        Schema::dropIfExists('employee_pay_histories');
        Schema::dropIfExists('employee_status_histories');
        Schema::dropIfExists('employee_external_ids');
        Schema::dropIfExists('employee_contacts');
    }
};
