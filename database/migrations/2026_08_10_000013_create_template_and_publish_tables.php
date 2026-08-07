<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('schedule_templates', function (Blueprint $table) {
            $table->id();

            $table->unsignedBigInteger('store_id');
            $table->foreign('store_id')->references('id')->on('stores')->cascadeOnDelete();

            $table->string('name', 190);
            $table->text('description')->nullable();
            $table->unsignedInteger('shift_count')->default(0);
            $table->unsignedInteger('total_minutes')->default(0);
            $table->unsignedBigInteger('created_by_user_id')->nullable();

            $table->timestamps();
            $table->softDeletes();

            $table->unique(['store_id', 'name']);
        });

        /**
         * The ONLY table that stores day_index. A template is week-relative by
         * definition, so here the index is the data; everywhere else shifts
         * carry an absolute shift_date and day_index is computed per request.
         * 0..6 relative to the store's week_start_dow (Tuesday by default).
         */
        Schema::create('schedule_template_shifts', function (Blueprint $table) {
            $table->id();

            $table->unsignedBigInteger('schedule_template_id');
            $table->foreign('schedule_template_id')->references('id')->on('schedule_templates')->cascadeOnDelete();

            $table->unsignedBigInteger('employee_id')->nullable();
            $table->unsignedBigInteger('position_id')->nullable();

            $table->unsignedTinyInteger('day_index');
            $table->time('start_time');
            $table->time('end_time');
            $table->string('label', 120)->nullable();
            $table->string('shift_type', 20)->default('custom');
            $table->text('note')->nullable();

            $table->timestamps();

            $table->index('schedule_template_id');
        });

        Schema::create('published_schedules', function (Blueprint $table) {
            $table->id();

            $table->unsignedBigInteger('store_id');
            $table->foreign('store_id')->references('id')->on('stores')->cascadeOnDelete();

            $table->date('week_start_date')->index();
            $table->string('week_label', 64)->nullable();

            // A file on a disk, NOT a data URL. html2canvas at scale:2 over a
            // 10x7 grid produces 1-3 MB, which has no business in a JSON body
            // or a DB column.
            $table->string('screenshot_disk', 32)->default('public');
            $table->string('screenshot_path', 2048)->nullable();
            $table->unsignedInteger('screenshot_bytes')->nullable();

            // The shift list as it stood at publish time, so "restore" and any
            // dispute later can be answered without reconstructing history.
            $table->json('shift_snapshot')->nullable();
            $table->unsignedInteger('shift_count')->default(0);
            $table->unsignedInteger('total_minutes')->default(0);

            $table->unsignedBigInteger('published_by_user_id')->nullable();
            $table->timestamp('published_at')->index();
            $table->timestamp('unpublished_at')->nullable();

            $table->timestamps();
            $table->softDeletes();

            $table->index(['store_id', 'week_start_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('published_schedules');
        Schema::dropIfExists('schedule_template_shifts');
        Schema::dropIfExists('schedule_templates');
    }
};
