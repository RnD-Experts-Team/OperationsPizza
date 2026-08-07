<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The bridge between our domain and TCP Humanity.
 *
 * Humanity's object model is Location → Position → Shift. Its API spells
 * "position" as the `schedule` parameter ("The 'schedule' parameter is referred
 * to as 'Position ID' in the rest of the documentation") — there is no separate
 * schedule entity. A Humanity Position is what the scheduling UI calls a
 * "department".
 *
 * Single Humanity company, one Location per store — so no child_company_id.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('humanity_locations', function (Blueprint $table) {
            $table->id();

            $table->unsignedBigInteger('store_id')->unique();
            $table->foreign('store_id')->references('id')->on('stores')->cascadeOnDelete();

            $table->string('humanity_location_id', 64)->unique();
            $table->string('name', 190)->nullable();
            $table->string('timezone', 64)->nullable();
            $table->timestamp('last_synced_at')->nullable();
            $table->timestamps();
        });

        Schema::create('humanity_positions', function (Blueprint $table) {
            $table->id();

            $table->string('humanity_position_id', 64)->unique();
            $table->string('humanity_location_id', 64)->nullable()->index();

            // Rendered as `department` in the scheduling grid.
            $table->string('name', 190);
            $table->string('color', 16)->nullable();
            $table->boolean('is_active')->default(true);

            // Humanity supports ?updated_at=<ISO8601>&include_deleted=1 on
            // positions and locations — the only true delta sync it offers.
            $table->timestamp('remote_updated_at')->nullable();
            $table->timestamp('deleted_remotely_at')->nullable();
            $table->timestamp('last_synced_at')->nullable();
            $table->timestamps();
        });

        Schema::create('humanity_position_map', function (Blueprint $table) {
            $table->id();

            $table->unsignedBigInteger('store_id');
            $table->foreign('store_id')->references('id')->on('stores')->cascadeOnDelete();

            // NULL = the store's default/fallback position, used when an
            // employee has no mapped position.
            $table->unsignedBigInteger('position_id')->nullable();

            $table->string('humanity_position_id', 64);
            $table->boolean('is_default')->default(false);
            $table->timestamps();

            $table->unique(['store_id', 'position_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('humanity_position_map');
        Schema::dropIfExists('humanity_positions');
        Schema::dropIfExists('humanity_locations');
    }
};
