<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Transactional outbox. Rows are written inside the same DB transaction as the
 * domain change, then published by PublishOutboxEventJob, with
 * `outbox:publish-pending` (every 5 min) as the sweeper.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('operations_outbox_events', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->string('subject', 190)->index();
            $table->string('type', 190)->index(); // same as subject
            $table->json('payload');
            $table->unsignedInteger('attempts')->default(0);
            $table->text('last_error')->nullable();
            $table->timestamp('published_at')->nullable()->index();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('operations_outbox_events');
    }
};
