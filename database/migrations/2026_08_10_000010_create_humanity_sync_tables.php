<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Integration observability + the idempotency anchor.
 *
 * Humanity has no Idempotency-Key header, so a POST that times out leaves us
 * unable to tell whether it landed. The log row is written BEFORE the call;
 * a retry that finds its own row still `pending` performs a read-back probe
 * and adopts the remote shift rather than creating a duplicate.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('humanity_sync_log', function (Blueprint $table) {
            $table->ulid('id')->primary();

            $table->unsignedBigInteger('store_id')->nullable();

            $table->string('entity_type', 30); // shift|shift_assignment|employee|time_off|position|location|timeclock
            $table->unsignedBigInteger('entity_id')->nullable();
            $table->string('humanity_id', 64)->nullable()->index();

            $table->string('operation', 20); // create|update|delete|publish|unpublish|fetch|reconcile
            $table->ulid('idempotency_key')->unique();

            $table->string('status', 20)->default('pending')->index(); // pending|succeeded|failed|abandoned

            $table->string('http_method', 10)->nullable();
            $table->string('endpoint', 255)->nullable();
            $table->smallInteger('http_status')->nullable();
            // Humanity returns 200 for almost everything and puts the real
            // outcome in the body: 1 = success, 91 = throttled, 7 = permission.
            $table->smallInteger('humanity_status')->nullable();

            $table->json('request_payload')->nullable();
            $table->json('response_payload')->nullable();
            $table->json('diff')->nullable();

            $table->unsignedTinyInteger('attempts')->default(0);
            $table->string('error_code', 64)->nullable();
            $table->text('error_message')->nullable();
            $table->unsignedInteger('duration_ms')->nullable();

            $table->ulid('correlation_id')->nullable()->index();

            $table->timestamps();

            $table->index(['entity_type', 'entity_id']);
            $table->index('created_at');
        });

        Schema::create('humanity_dead_letters', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('humanity_sync_log_id')->nullable();
            $table->unsignedBigInteger('store_id')->nullable();

            $table->string('entity_type', 30);
            $table->unsignedBigInteger('entity_id')->nullable();
            $table->string('operation', 20);

            $table->json('payload')->nullable();
            $table->string('error_code', 64)->nullable();
            $table->text('error_message')->nullable();
            $table->unsignedTinyInteger('attempts')->default(0);

            $table->timestamp('parked_at')->index();
            $table->timestamp('resolved_at')->nullable();
            $table->unsignedBigInteger('resolved_by_user_id')->nullable();
            $table->timestamps();
        });

        /**
         * Scheduling found an employee with no Humanity link. HiringPizza owns
         * the write, so we ask over NATS and wait — this row is what the UI
         * polls and what stops us re-publishing the request every few seconds.
         */
        Schema::create('employee_sync_requests', function (Blueprint $table) {
            $table->id();

            $table->unsignedBigInteger('employee_id')->unique();
            $table->foreign('employee_id')->references('id')->on('employees')->cascadeOnDelete();

            $table->string('status', 20)->default('requested')->index(); // requested|fulfilled|failed
            $table->unsignedBigInteger('requested_by_user_id')->nullable();
            $table->timestamp('requested_at');
            $table->timestamp('fulfilled_at')->nullable();
            $table->unsignedTinyInteger('attempts')->default(0);
            $table->text('last_error')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('employee_sync_requests');
        Schema::dropIfExists('humanity_dead_letters');
        Schema::dropIfExists('humanity_sync_log');
    }
};
