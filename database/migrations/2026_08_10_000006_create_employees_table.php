<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Replicated from HiringPizza via hiring.v1.employee.{created,updated}.
 *
 * `created` carries a full snapshot at data.employee; `updated` carries only
 * data.changed_fields.<field>.{from,to}. See EmployeeUpdatedHandler.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('employees', function (Blueprint $table) {
            // HiringPizza employee id — NOT auto-incrementing.
            $table->unsignedBigInteger('id')->primary();

            $table->string('first_name', 120);
            $table->string('middle_name', 120)->nullable();
            $table->string('last_name', 120);

            $table->string('gender', 20)->nullable();
            $table->string('employment_type', 10)->nullable(); // W2 | 1099
            $table->date('birth_date')->nullable();
            $table->string('image_url', 2048)->nullable();

            // AuthTokenStoreScopeMiddleware checks this for employee-subject
            // tokens. HiringPizza's copy of that middleware checks the same
            // attribute against a column that does not exist there, so every
            // employee token 401s; ours must actually have it.
            // Derived: true iff at least one store membership has an active status.
            $table->boolean('active')->default(false)->index();

            // Denormalised latest status_history so the roster query doesn't
            // need a correlated max(effective_date) subquery per employee.
            $table->string('current_status', 20)->nullable()->index();
            $table->date('current_status_effective_date')->nullable();

            // Lifted out of employee_external_ids['Humanity ID'] for cheap joins.
            // Also written directly as Humanity's `eid` on the staff record, which
            // is what makes the HiringPizza-side upsert idempotent.
            $table->string('humanity_employee_id', 64)->nullable()->unique();
            $table->timestamp('humanity_synced_at')->nullable();

            // Out-of-order-delivery guard: the `time` of the last applied event.
            // JetStream redelivery can replay an older update after a newer one.
            $table->timestamp('hiring_event_at')->nullable();

            $table->timestamps();
            $table->softDeletes();

            $table->index(['last_name', 'first_name']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('employees');
    }
};
