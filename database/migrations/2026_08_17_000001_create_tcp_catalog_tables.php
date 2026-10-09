<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The TCP side of store linking, mirroring humanity_locations' shape.
 *
 * The binding convention is NAME equality: a TCP Location's `name` is our
 * store_number ("03795-00001"). tcp:sync-catalog enforces that convention —
 * nothing is matched by name at runtime; runtime reads these rows.
 *
 * Job codes are the reason clocking works at all: a punch must carry a TCP
 * jobCodeId, and the account's codes are per-store ("Crew Member - 3795-01")
 * with a "Restaurant Id" custom field carrying the store_number. That custom
 * field, not the description, is what attributes a code to a store.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('tcp_locations', function (Blueprint $table) {
            $table->id();

            $table->unsignedBigInteger('store_id')->unique();
            $table->foreign('store_id')->references('id')->on('stores')->cascadeOnDelete();

            $table->string('tcp_location_id', 64)->unique();
            // TCP's location name at last sync. By convention identical to
            // store_number; kept so a rename on the TCP side is detectable.
            $table->string('name', 190)->nullable();

            $table->timestamp('last_synced_at')->nullable();
            $table->timestamps();
        });

        Schema::create('tcp_job_codes', function (Blueprint $table) {
            $table->id();

            // TCP's jobCodeId (e.g. 37950101) — the value a punch carries.
            // TCP also has a separate record `id`; we deliberately store the
            // jobCodeId because that is what the punch API speaks.
            $table->string('tcp_job_code_id', 64)->unique();

            $table->string('description', 190);

            // From the "Restaurant Id" custom field. NULL = a company-wide
            // code (Regular, Sick, ...), present = a per-store code.
            $table->string('store_number', 32)->nullable()->index();

            $table->boolean('clockable')->default(false);
            $table->boolean('is_active')->default(true);

            $table->timestamp('last_synced_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tcp_job_codes');
        Schema::dropIfExists('tcp_locations');
    }
};
