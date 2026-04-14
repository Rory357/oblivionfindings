<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        foreach ([
            'clinical_observations',
            'clinical_events',
            'clinical_protocols',
            'clinical_protocol_schedules',
        ] as $table) {
            if (Schema::hasTable($table)) {
                return;
            }
        }

        // ── Clinical Protocols ─────────────────────────────────────────────
        Schema::create('clinical_protocols', function (Blueprint $table) {
            $table->id();
            $table->foreignId('client_id')->constrained('clients')->cascadeOnDelete();
            $table->string('observation_type', 50)->index();
            $table->string('frequency', 30); // daily, twice_daily, weekly, fortnightly, monthly, custom
            $table->unsignedSmallInteger('custom_interval_days')->nullable();
            $table->timestamp('next_due_at')->nullable()->index();
            $table->timestamp('last_recorded_at')->nullable();
            $table->string('status', 20)->default('active')->index(); // active, paused, completed
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->constrained('users');
            $table->softDeletes();
            $table->timestamps();

            $table->index(['client_id', 'observation_type', 'status']);
            $table->index(['status', 'next_due_at']);
        });

        // ── Clinical Protocol Schedules ────────────────────────────────────
        Schema::create('clinical_protocol_schedules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('clinical_protocol_id')->constrained('clinical_protocols')->cascadeOnDelete();
            $table->string('day_of_week', 10)->nullable(); // mon, tue, wed, ... or null for daily
            $table->time('preferred_time')->nullable();
            $table->timestamps();
        });

        // ── Clinical Observations ──────────────────────────────────────────
        Schema::create('clinical_observations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('client_id')->constrained('clients')->cascadeOnDelete();
            $table->foreignId('shift_id')->nullable()->constrained('shifts')->nullOnDelete();
            $table->foreignId('clinical_protocol_id')->nullable()->constrained('clinical_protocols')->nullOnDelete();
            $table->string('observation_type', 50)->index();
            $table->json('data');
            $table->text('notes')->nullable();
            $table->foreignId('recorded_by')->constrained('users');
            $table->timestamp('recorded_at');
            $table->softDeletes();
            $table->timestamps();

            $table->index(['client_id', 'observation_type', 'recorded_at']);
            $table->index(['shift_id', 'observation_type']);
            $table->index(['recorded_by', 'recorded_at']);
            $table->index(['recorded_at']);
        });

        // ── Clinical Events ────────────────────────────────────────────────
        Schema::create('clinical_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('client_id')->constrained('clients')->cascadeOnDelete();
            $table->foreignId('shift_id')->nullable()->constrained('shifts')->nullOnDelete();
            $table->string('event_type', 50)->index(); // fall, seizure, behavioural_episode, hospital_admission, hospital_discharge, gp_visit, specialist_appointment
            $table->enum('severity', ['low', 'medium', 'high', 'critical'])->default('low')->index();
            $table->timestamp('occurred_at');
            $table->text('description');
            $table->json('metadata')->nullable();
            $table->boolean('follow_up_required')->default(false);
            $table->timestamp('follow_up_completed_at')->nullable();
            $table->text('follow_up_notes')->nullable();
            $table->foreignId('reported_by')->constrained('users');
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->foreignId('linked_observation_id')->nullable()->constrained('clinical_observations')->nullOnDelete();
            $table->softDeletes();
            $table->timestamps();

            $table->index(['client_id', 'event_type', 'occurred_at']);
            $table->index(['severity', 'occurred_at']);
            $table->index(['follow_up_required', 'follow_up_completed_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('clinical_events');
        Schema::dropIfExists('clinical_observations');
        Schema::dropIfExists('clinical_protocol_schedules');
        Schema::dropIfExists('clinical_protocols');
    }
};
