<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * eMAR P01 foundation C5: the 12-month backfill of dose slots.
 *
 * - medication_dose_slots.reconstructed marks a slot the backfill rebuilt
 *   from history rather than one the live generator made.
 * - medication_dose_slot_backfills records each run: the NZ period it
 *   reconstructs, its cursor (so an interrupted run resumes), and its counts.
 *   A completed run sets where dose-slot reads become available from.
 * - an index on (reconstructed, generated_at) finds when live generation
 *   began without scanning the slots.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('medication_dose_slots', function (Blueprint $table) {
            $table->boolean('reconstructed')->default(false)->after('generated_at');
            // When live generation began: the first live slot written.
            $table->index(['reconstructed', 'generated_at'], 'med_dose_slots_reconstructed_generated_idx');
        });

        Schema::create('medication_dose_slot_backfills', function (Blueprint $table) {
            $table->id();
            $table->date('from_date');
            $table->date('to_date');
            // running | completed
            $table->string('status', 16)->default('running');
            $table->unsignedBigInteger('last_order_id')->nullable();
            $table->unsignedInteger('orders_done')->default(0);
            $table->unsignedInteger('slots_created')->default(0);
            $table->unsignedInteger('outcomes_written')->default(0);
            $table->unsignedInteger('records_without_slot')->default(0);
            $table->unsignedInteger('days_from_records')->default(0);
            $table->unsignedInteger('orders_skipped')->default(0);
            $table->dateTime('started_at');
            $table->dateTime('finished_at')->nullable();
            $table->timestamps();

            $table->index(['status', 'from_date'], 'med_dose_backfills_status_from_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('medication_dose_slot_backfills');
        Schema::table('medication_dose_slots', function (Blueprint $table) {
            $table->dropIndex('med_dose_slots_reconstructed_generated_idx');
            $table->dropColumn('reconstructed');
        });
    }
};
