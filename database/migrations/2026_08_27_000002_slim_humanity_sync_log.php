<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Trims the sync log to what is actually read, and drops the dead-letter table.
 *
 * `humanity_sync_log` stays: pendingFor() reads it back to stop the reconciler
 * deleting a shift whose create call is still in flight. But most of its columns
 * were speculative observability — `http_method` and `endpoint` were never
 * written by any code path at all, `attempts` was hardcoded to 1 and never
 * incremented, `idempotency_key` was generated on every write and never looked
 * up (the real idempotency is entity_type + entity_id + status), and the two
 * payload blobs were written on every external call and read by nothing. On a
 * table that gets a row per Humanity/TCP call, those blobs are the bulk of it.
 *
 * `humanity_dead_letters` goes entirely: its only writer, HumanitySyncLogger::
 * park(), was never called from anywhere, so nothing has ever written a row and
 * nothing has ever read one.
 *
 * The reconcile outcome that `error_code` was being overloaded to carry moves
 * into `diff` (kept), so no information is lost — see HumanitySyncLogger.
 */
return new class extends Migration {
    public function up(): void
    {
        // Indexes first, and in their own statement: MySQL refuses to drop a
        // column that an index still covers, while SQLite is lenient about it.
        Schema::table('humanity_sync_log', function (Blueprint $table) {
            $table->dropUnique(['idempotency_key']);
            $table->dropIndex(['correlation_id']);
        });

        Schema::table('humanity_sync_log', function (Blueprint $table) {
            $table->dropColumn([
                'idempotency_key',
                'http_method',
                'endpoint',
                'http_status',
                'humanity_status',
                'request_payload',
                'response_payload',
                'attempts',
                'error_code',
                'duration_ms',
                'correlation_id',
            ]);
        });

        Schema::dropIfExists('humanity_dead_letters');
    }

    public function down(): void
    {
        Schema::table('humanity_sync_log', function (Blueprint $table) {
            $table->ulid('idempotency_key')->nullable();
            $table->string('http_method', 10)->nullable();
            $table->string('endpoint', 255)->nullable();
            $table->smallInteger('http_status')->nullable();
            $table->smallInteger('humanity_status')->nullable();
            $table->json('request_payload')->nullable();
            $table->json('response_payload')->nullable();
            $table->unsignedTinyInteger('attempts')->default(0);
            $table->string('error_code', 64)->nullable();
            $table->unsignedInteger('duration_ms')->nullable();
            $table->ulid('correlation_id')->nullable();
        });

        Schema::table('humanity_sync_log', function (Blueprint $table) {
            // Not unique on the way back: the column is refilled with nulls, so
            // a unique constraint would reject the second restored row.
            $table->index('idempotency_key');
            $table->index('correlation_id');
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
    }
};
