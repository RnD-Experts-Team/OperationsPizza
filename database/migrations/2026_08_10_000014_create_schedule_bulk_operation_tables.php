<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One UI action ("copy previous week") fans out into ~70 Humanity calls, and
 * Humanity throttles (application status 91) on exactly that pattern. So bulk
 * work is always async: 202 + a batch id the client polls.
 *
 * There is deliberately no rollback. Deleting shifts we already created in
 * order to "undo" is more destructive than a partial week, especially if the
 * week was published and employees have seen it. Failures surface per item
 * with a Retry Failed action instead.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('schedule_bulk_operations', function (Blueprint $table) {
            $table->ulid('id')->primary();

            $table->unsignedBigInteger('store_id');
            $table->foreign('store_id')->references('id')->on('stores')->cascadeOnDelete();

            $table->string('type', 30); // copy_week|apply_template|publish_week|unpublish_week|clear_week|restore_published|recurring_expand|retry_failed
            $table->string('status', 30)->default('queued')->index(); // queued|processing|completed|completed_with_errors|failed

            $table->date('week_start_date')->nullable();
            $table->date('source_week_start_date')->nullable();
            $table->unsignedBigInteger('schedule_template_id')->nullable();

            $table->unsignedInteger('total_items')->default(0);
            $table->unsignedInteger('succeeded_items')->default(0);
            $table->unsignedInteger('failed_items')->default(0);

            $table->json('params')->nullable();
            $table->text('error')->nullable();

            $table->unsignedBigInteger('requested_by_user_id')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();
        });

        Schema::create('schedule_bulk_operation_items', function (Blueprint $table) {
            $table->id();

            $table->ulid('bulk_operation_id');
            $table->foreign('bulk_operation_id')->references('id')->on('schedule_bulk_operations')->cascadeOnDelete();

            // Deletes are sequenced before creates in replace mode, so a
            // mid-delete failure is visible rather than silently producing a
            // doubled week.
            $table->unsignedInteger('sequence');
            $table->string('action', 20); // create|update|delete|publish|unpublish
            $table->string('status', 20)->default('pending')->index(); // pending|processing|succeeded|failed|skipped

            $table->unsignedBigInteger('employee_id')->nullable();
            $table->unsignedBigInteger('shift_id')->nullable();
            $table->json('payload')->nullable();

            $table->string('error_code', 64)->nullable();
            $table->text('error_message')->nullable();
            $table->unsignedTinyInteger('attempts')->default(0);

            $table->timestamps();

            $table->index('bulk_operation_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('schedule_bulk_operation_items');
        Schema::dropIfExists('schedule_bulk_operations');
    }
};
