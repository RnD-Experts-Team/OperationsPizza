<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('event_inbox', function (Blueprint $table) {
            $table->id();

            // CloudEvent id (both auth + hiring factories use ULID)
            $table->string('event_id', 64)->unique();

            // subject/type
            $table->string('subject', 255)->index();

            // source system: 'auth-system' | 'hiring-system'
            $table->string('source', 100)->nullable()->index();

            // stream + durable consumer for traceability
            $table->string('stream', 100)->nullable()->index();
            $table->string('consumer', 100)->nullable()->index();

            $table->json('payload');

            $table->timestamp('processed_at')->nullable()->index();
            $table->unsignedInteger('attempts')->default(0);
            $table->timestamp('parked_at')->nullable()->index();
            $table->text('last_error')->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('event_inbox');
    }
};
