<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * eMAR P01 foundation C3: the materialised dose-slot projection.
 *
 * - medication_dose_schedule_versions: each version of an order's schedule
 *   (written, verified or rejected), so an edit awaiting verification keeps
 *   the previous version in effect and history is never rewritten.
 * - medication_dose_order_pauses: when an order was paused and resumed.
 * - medication_dose_slots: one row per order × NZ calendar day × ordered
 *   time, generated ahead and on order changes; the outcome is written in the
 *   same transaction as the administration that records it.
 *
 * Nothing reads the slots yet (C6), there is no backfill (C5), and the
 * house/day read indexes come with the read service (C4).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('medication_dose_schedule_versions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('client_medication_id')->constrained('client_medications')->cascadeOnDelete();
            // NZ wall-clock "HH:MM" times as read when the version was written.
            $table->json('dose_times');
            $table->date('start_date')->nullable();
            $table->date('end_date')->nullable();
            $table->boolean('is_prn')->default(false);
            $table->boolean('self_managed')->default(false);
            // DATETIME (UTC): an order verified with no recorded time is in
            // effect "since the beginning" (1970-01-01), outside TIMESTAMP's range.
            $table->dateTime('changed_at');
            $table->dateTime('verified_at')->nullable();
            $table->dateTime('rejected_at')->nullable();
            $table->timestamps();

            $table->index(['client_medication_id', 'changed_at'], 'med_dose_versions_order_changed_idx');
        });

        Schema::create('medication_dose_order_pauses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('client_medication_id')->constrained('client_medications')->cascadeOnDelete();
            $table->timestamp('paused_at');
            $table->timestamp('resumed_at')->nullable();
            $table->timestamps();

            $table->index(['client_medication_id', 'paused_at'], 'med_dose_pauses_order_paused_idx');
        });

        Schema::create('medication_dose_slots', function (Blueprint $table) {
            $table->id();
            $table->foreignId('client_id')->constrained('clients')->cascadeOnDelete();
            $table->foreignId('client_medication_id')->constrained('client_medications')->cascadeOnDelete();
            $table->foreignId('schedule_version_id')->nullable()
                ->constrained('medication_dose_schedule_versions', 'id', 'med_dose_slots_version_fk')->nullOnDelete();
            $table->date('nz_date');
            $table->char('ordered_time', 5);
            $table->timestamp('due_at');
            $table->boolean('controlled')->default(false);
            $table->boolean('order_change_pending')->default(false);
            $table->string('dst_adjustment', 16)->nullable();
            $table->boolean('self_managed')->default(false);
            $table->boolean('last_day')->default(false);
            // A not-yet-due slot an order change or stop removed; never set on a recorded slot.
            $table->timestamp('superseded_at')->nullable();
            $table->string('outcome', 16)->nullable();
            $table->foreignId('outcome_administration_id')->nullable()
                ->constrained('client_medication_administrations', 'id', 'med_dose_slots_outcome_admin_fk')->nullOnDelete();
            $table->timestamp('outcome_at')->nullable();
            $table->timestamp('generated_at');
            $table->timestamps();

            $table->unique(['client_medication_id', 'nz_date', 'ordered_time'], 'med_dose_slots_order_day_time_unique');
            $table->index(['client_medication_id', 'due_at'], 'med_dose_slots_order_due_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('medication_dose_slots');
        Schema::dropIfExists('medication_dose_order_pauses');
        Schema::dropIfExists('medication_dose_schedule_versions');
    }
};
